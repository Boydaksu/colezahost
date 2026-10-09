<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Core;

use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Domain\Identity\Organization\OrganizationService;
use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\DomainAdoptionService;
use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Adoption\ServiceAdoptionService;
use Coleza\Domain\Migration\Canonical\CanonicalDomainDto;
use Coleza\Domain\Migration\Canonical\CanonicalServiceDto;
use Coleza\Domain\Migration\Mapping\MappingEngineInterface;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecord;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * End-to-end migrator for WHMCS core hosting commerce entities:
 * clients, organizations, products/groups, services, and domains.
 *
 * Implements strict Zero Silent Data Loss certification:
 * - Every source record staged has a terminal state (migrated, quarantined, skipped, failed).
 * - Remote provisioning is suppressed during adoption (non-destructive).
 * - Foreign key references (client -> service/domain, product -> service) are mapped and preserved.
 */
final class WhmcsCoreEntityMigrator
{
    private WhmcsCoreEntityExtractor $extractor;

    public function __construct(
        private Connection $targetDb,
        private WhmcsReadOnlyConnector $whmcs,
        private DatabaseStagingRepository $stagingRepo,
        private StagingPipelineService $stagingPipeline,
        private MappingEngineInterface $mappingEngine,
        private ProviderIdentityResolver $identityResolver,
        private ServiceAdoptionService $serviceAdoptionService,
        private DomainAdoptionService $domainAdoptionService,
        private AdoptedIdentityRepository $adoptedIdentityRepo,
        private ?OrganizationService $organizationService = null,
        private ?CatalogService $catalogService = null,
        ?WhmcsCoreEntityExtractor $extractor = null
    ) {
        $this->extractor = $extractor ?? new WhmcsCoreEntityExtractor($whmcs);
    }

    /**
     * Ensures all required target Coleza tables exist prior to migration.
     */
    public function ensureTargetTables(): void
    {
        $driver = $this->targetDb->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Users table
        $sqlUsers = sprintf(
            'CREATE TABLE IF NOT EXISTS users (
                id %s,
                email VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(100) NOT NULL,
                is_active INT NOT NULL DEFAULT 1,
                email_verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlUsers);

        // Product groups table
        $sqlProductGroups = sprintf(
            'CREATE TABLE IF NOT EXISTS product_groups (
                id %s,
                slug VARCHAR(100) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlProductGroups);

        // Products table
        $sqlProducts = sprintf(
            'CREATE TABLE IF NOT EXISTS products (
                id %s,
                group_id INT NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                type VARCHAR(50) NOT NULL DEFAULT "hosting",
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                tag_line VARCHAR(255) NULL,
                features_json TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                is_featured INT NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlProducts);

        if ($this->organizationService !== null) {
            $this->organizationService->ensureTables();
        } else {
            // Standalone organizations & organization_members tables
            $sqlOrgs = sprintf(
                'CREATE TABLE IF NOT EXISTS organizations (
                    id %s,
                    name VARCHAR(150) NOT NULL,
                    slug VARCHAR(100) NOT NULL UNIQUE,
                    owner_user_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )',
                $autoInc
            );
            $this->targetDb->statement($sqlOrgs);

            $sqlMembers = sprintf(
                'CREATE TABLE IF NOT EXISTS organization_members (
                    id %s,
                    organization_id INT NOT NULL,
                    user_id INT NOT NULL,
                    role VARCHAR(50) NOT NULL DEFAULT "member",
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(organization_id, user_id)
                )',
                $autoInc
            );
            $this->targetDb->statement($sqlMembers);
        }

        $this->stagingRepo->ensureTable();
        $this->adoptedIdentityRepo->ensureTable();
        $this->serviceAdoptionService->ensureServicesTable();
        $this->domainAdoptionService->ensureDomainsTable();
    }

    /**
     * Executes the full migration pipeline for WHMCS core entities in dependency order:
     * 1. Clients & Organizations
     * 2. Product Groups & Products
     * 3. Services (Hosting Accounts)
     * 4. Domains
     *
     * Then verifies the Zero Silent Data Loss terminal accounting report.
     *
     * @param array<string, mixed> $options
     */
    public function migrateAll(string $batchId, array $options = []): WhmcsCoreEntityMigrationResult
    {
        $this->ensureTargetTables();

        $clientStats = $this->migrateClients($batchId, $options);
        $productStats = $this->migrateProducts($batchId, $options);
        $serviceStats = $this->migrateServices($batchId, $options);
        $domainStats = $this->migrateDomains($batchId, $options);

        // Terminal accounting certification
        $report = $this->stagingRepo->generateAccountingReport($batchId);

        if (!$report->isZeroSilentLossAchieved()) {
            throw new RuntimeException(sprintf(
                'Zero Silent Data Loss violated for batch [%s]: unaccounted diff = %d',
                $batchId,
                $report->getUnaccountedDiff()
            ));
        }

        return new WhmcsCoreEntityMigrationResult(
            batchId: $batchId,
            clientStats: $clientStats,
            productStats: $productStats,
            serviceStats: $serviceStats,
            domainStats: $domainStats,
            accountingReport: $report
        );
    }

    /**
     * Migrates clients and optional client organizations.
     *
     * @param array<string, mixed> $options
     * @return array{total: int, migrated: int, quarantined: int, user_ids: array<string, int>}
     */
    public function migrateClients(string $batchId, array $options = []): array
    {
        $limit = isset($options['client_limit']) ? (int) $options['client_limit'] : null;
        $offset = isset($options['client_offset']) ? (int) $options['client_offset'] : null;

        $clients = $this->extractor->extractClients($limit, $offset);

        $total = count($clients);
        $migrated = 0;
        $quarantined = 0;
        $userIds = [];

        foreach ($clients as $client) {
            $sourceId = (string) ($client['id'] ?? '');

            // 1. Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'client',
                sourceEntityId: $sourceId,
                rawPayload: $client
            );

            // 2. Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                $canonical = $record->getCanonicalPayload() ?? [];
                $email = strtolower(trim((string) ($canonical['email'] ?? '')));
                $fullName = trim((string) ($canonical['full_name'] ?? ''));
                $isActive = ($canonical['status'] ?? 'active') === 'active' ? 1 : 0;
                $createdAt = (string) ($canonical['created_at'] ?? date('Y-m-d H:i:s'));
                $passwordHash = (string) ($client['password'] ?? password_hash('whmcs-imported-client', PASSWORD_BCRYPT));

                // 3. Upsert user in target database
                $existing = $this->targetDb->selectOne('SELECT id FROM users WHERE email = ?', [$email]);
                if ($existing !== null) {
                    $userId = (int) $existing['id'];
                } else {
                    $this->targetDb->statement(
                        'INSERT INTO users (email, password_hash, name, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
                        [$email, $passwordHash, $fullName ?: 'WHMCS Client', $isActive, $createdAt]
                    );
                    $userId = (int) $this->targetDb->getPdo()->lastInsertId();
                }

                // 4. Handle organization if company name exists
                $company = trim((string) ($canonical['company_name'] ?? ''));
                if ($company !== '') {
                    $this->ensureOrganizationForClient($userId, $company);
                }

                // 5. Register in ProviderIdentityResolver
                $this->identityResolver->registerClientMapping($sourceId, $userId);

                // 6. Mark staging record as MIGRATED
                $record->markMigrated($userId);
                $this->stagingRepo->save($record);

                $migrated++;
                $userIds[$sourceId] = $userId;
            } else {
                $quarantined++;
            }
        }

        // Migrate contacts as subaccount users if enabled
        if ($options['migrate_contacts'] ?? true) {
            $this->migrateContacts($batchId, $userIds);
        }

        return [
            'total' => $total,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'user_ids' => $userIds,
        ];
    }

    /**
     * Migrates products and product groups.
     *
     * @param array<string, mixed> $options
     * @return array{total: int, migrated: int, quarantined: int, product_ids: array<string, int>}
     */
    public function migrateProducts(string $batchId, array $options = []): array
    {
        // 1. Ensure product groups exist
        $groups = $this->extractor->extractProductGroups();
        $groupMap = []; // [whmcs_gid => target_gid]

        foreach ($groups as $grp) {
            $gid = (int) ($grp['id'] ?? 0);
            $name = trim((string) ($grp['name'] ?? 'General Products'));
            $slug = 'whmcs-grp-' . $gid . '-' . $this->slugify($name);
            $isActive = !isset($grp['disabled']) || (int) $grp['disabled'] === 0 ? 1 : 0;

            $existing = $this->targetDb->selectOne('SELECT id FROM product_groups WHERE slug = ?', [$slug]);
            if ($existing !== null) {
                $targetGid = (int) $existing['id'];
            } else {
                $this->targetDb->statement(
                    'INSERT INTO product_groups (slug, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?)',
                    [$slug, $name, $grp['headline'] ?? null, (int) ($grp['order'] ?? 0), $isActive]
                );
                $targetGid = (int) $this->targetDb->getPdo()->lastInsertId();
            }

            $groupMap[$gid] = $targetGid;
        }

        // Fallback default group if no groups exist
        if (empty($groupMap)) {
            $defaultGroup = $this->targetDb->selectOne("SELECT id FROM product_groups WHERE slug = 'general'");
            if ($defaultGroup !== null) {
                $groupMap[0] = (int) $defaultGroup['id'];
            } else {
                $this->targetDb->statement(
                    "INSERT INTO product_groups (slug, name, is_active) VALUES ('general', 'General', 1)"
                );
                $groupMap[0] = (int) $this->targetDb->getPdo()->lastInsertId();
            }
        }

        // 2. Migrate Products
        $limit = isset($options['product_limit']) ? (int) $options['product_limit'] : null;
        $offset = isset($options['product_offset']) ? (int) $options['product_offset'] : null;

        $products = $this->extractor->extractProducts($limit, $offset);

        $total = count($products);
        $migrated = 0;
        $quarantined = 0;
        $productIds = [];

        foreach ($products as $prod) {
            $sourceId = (string) ($prod['id'] ?? '');

            // Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'product',
                sourceEntityId: $sourceId,
                rawPayload: $prod
            );

            // Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                $canonical = $record->getCanonicalPayload() ?? [];
                $name = trim((string) ($canonical['name'] ?? 'Product'));
                $slug = 'whmcs-p' . $sourceId . '-' . $this->slugify($name);
                $type = (string) ($canonical['type'] ?? 'hosting');
                $desc = (string) ($canonical['description'] ?? '');
                $isActive = !empty($canonical['is_active']) ? 1 : 0;
                $sourceGid = (int) ($prod['gid'] ?? 0);
                $targetGid = $groupMap[$sourceGid] ?? reset($groupMap);

                $metaJson = json_encode([
                    'whmcs_product_id' => $sourceId,
                    'module' => $canonical['module'] ?? null,
                    'package_name' => $canonical['package_name'] ?? null,
                    'price' => (float) ($canonical['price'] ?? 0.0),
                    'currency' => (string) ($canonical['currency'] ?? 'USD'),
                    'billing_cycle' => (string) ($canonical['billing_cycle'] ?? 'monthly'),
                ], JSON_UNESCAPED_UNICODE);

                // Upsert product
                $existing = $this->targetDb->selectOne('SELECT id FROM products WHERE slug = ?', [$slug]);
                if ($existing !== null) {
                    $targetProdId = (int) $existing['id'];
                } else {
                    $this->targetDb->statement(
                        'INSERT INTO products (group_id, slug, type, name, description, is_active, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$targetGid, $slug, $type, $name, $desc, $isActive, $metaJson]
                    );
                    $targetProdId = (int) $this->targetDb->getPdo()->lastInsertId();
                }

                // Register in ProviderIdentityResolver
                $this->identityResolver->registerProductMapping($sourceId, $targetProdId);

                // Mark staging record as MIGRATED
                $record->markMigrated($targetProdId);
                $this->stagingRepo->save($record);

                $migrated++;
                $productIds[$sourceId] = $targetProdId;
            } else {
                $quarantined++;
            }
        }

        return [
            'total' => $total,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'product_ids' => $productIds,
        ];
    }

    /**
     * Migrates services (hosting accounts) via ServiceAdoptionService.
     * Prevents remote provisioning execution.
     *
     * @param array<string, mixed> $options
     * @return array{total: int, migrated: int, quarantined: int, service_ids: array<string, int>}
     */
    public function migrateServices(string $batchId, array $options = []): array
    {
        $limit = isset($options['service_limit']) ? (int) $options['service_limit'] : null;
        $offset = isset($options['service_offset']) ? (int) $options['service_offset'] : null;

        $services = $this->extractor->extractServices($limit, $offset);

        $total = count($services);
        $migrated = 0;
        $quarantined = 0;
        $serviceIds = [];

        foreach ($services as $srv) {
            $sourceId = (string) ($srv['id'] ?? '');

            // Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'service',
                sourceEntityId: $sourceId,
                rawPayload: $srv
            );

            // Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                // Reconstruct CanonicalServiceDto
                /** @var CanonicalServiceDto $serviceDto */
                $serviceDto = $this->mappingEngine->map('whmcs', 'service', $srv);

                // Verify client mapping exists
                $resolvedClient = $this->identityResolver->resolveClient($serviceDto->getClientSourceId());
                if ($resolvedClient === null) {
                    $record->markQuarantined(
                        reason: sprintf('Service [%s] references unmigrated client [%s].', $sourceId, $serviceDto->getClientSourceId()),
                        errors: [sprintf('Client source ID [%s] has not been resolved.', $serviceDto->getClientSourceId())]
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                    continue;
                }

                // Adopt service non-destructively
                $serverKey = isset($srv['server']) && (int) $srv['server'] > 0 ? (string) $srv['server'] : null;
                $adoptionResult = $this->serviceAdoptionService->adoptService(
                    serviceDto: $serviceDto,
                    batchId: $batchId,
                    sourceServerKey: $serverKey,
                    verifyRemote: false
                );

                if ($adoptionResult->isSuccess()) {
                    $targetServiceId = (int) $adoptionResult->getAdoptedServiceId();
                    $record->markMigrated($targetServiceId);
                    $this->stagingRepo->save($record);

                    $migrated++;
                    $serviceIds[$sourceId] = $targetServiceId;
                } else {
                    $record->markQuarantined(
                        reason: 'Service adoption failed.',
                        errors: $adoptionResult->getErrors()
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                }
            } else {
                $quarantined++;
            }
        }

        return [
            'total' => $total,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'service_ids' => $serviceIds,
        ];
    }

    /**
     * Migrates domains via DomainAdoptionService.
     * Prevents remote registrar creation or charging.
     *
     * @param array<string, mixed> $options
     * @return array{total: int, migrated: int, quarantined: int, domain_ids: array<string, int>}
     */
    public function migrateDomains(string $batchId, array $options = []): array
    {
        $limit = isset($options['domain_limit']) ? (int) $options['domain_limit'] : null;
        $offset = isset($options['domain_offset']) ? (int) $options['domain_offset'] : null;

        $domains = $this->extractor->extractDomains($limit, $offset);

        $total = count($domains);
        $migrated = 0;
        $quarantined = 0;
        $domainIds = [];

        foreach ($domains as $dom) {
            $sourceId = (string) ($dom['id'] ?? '');

            // Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'domain',
                sourceEntityId: $sourceId,
                rawPayload: $dom
            );

            // Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                // Reconstruct CanonicalDomainDto
                /** @var CanonicalDomainDto $domainDto */
                $domainDto = $this->mappingEngine->map('whmcs', 'domain', $dom);

                // Verify client mapping exists
                $resolvedClient = $this->identityResolver->resolveClient($domainDto->getClientSourceId());
                if ($resolvedClient === null) {
                    $record->markQuarantined(
                        reason: sprintf('Domain [%s] references unmigrated client [%s].', $sourceId, $domainDto->getClientSourceId()),
                        errors: [sprintf('Client source ID [%s] has not been resolved.', $domainDto->getClientSourceId())]
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                    continue;
                }

                // Adopt domain non-destructively
                $registrarKey = !empty($dom['registrar']) ? (string) $dom['registrar'] : null;
                $adoptionResult = $this->domainAdoptionService->adoptDomain(
                    domainDto: $domainDto,
                    batchId: $batchId,
                    sourceRegistrarKey: $registrarKey,
                    verifyRemote: false
                );

                if ($adoptionResult->isSuccess()) {
                    $targetDomainId = (int) $adoptionResult->getAdoptedDomainId();
                    $record->markMigrated($targetDomainId);
                    $this->stagingRepo->save($record);

                    $migrated++;
                    $domainIds[$sourceId] = $targetDomainId;
                } else {
                    $record->markQuarantined(
                        reason: 'Domain adoption failed.',
                        errors: $adoptionResult->getErrors()
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                }
            } else {
                $quarantined++;
            }
        }

        return [
            'total' => $total,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'domain_ids' => $domainIds,
        ];
    }

    /**
     * @param array<string, int> $migratedClientMap
     */
    private function migrateContacts(string $batchId, array $migratedClientMap): void
    {
        $contacts = $this->extractor->extractContacts();

        foreach ($contacts as $cnt) {
            $parentId = (string) ($cnt['userid'] ?? '');
            $targetUserId = $migratedClientMap[$parentId] ?? null;

            if ($targetUserId === null) {
                continue;
            }

            $email = strtolower(trim((string) ($cnt['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $isSubaccount = !empty($cnt['subaccount']) && ((int) $cnt['subaccount'] === 1 || $cnt['subaccount'] === true);
            if (!$isSubaccount) {
                continue;
            }

            // If subaccount has login credentials, ensure user exists
            $existing = $this->targetDb->selectOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($existing === null) {
                $fullName = trim(($cnt['firstname'] ?? '') . ' ' . ($cnt['lastname'] ?? ''));
                $pwd = (string) ($cnt['password'] ?? password_hash('whmcs-subaccount', PASSWORD_BCRYPT));
                $this->targetDb->statement(
                    'INSERT INTO users (email, password_hash, name, is_active, created_at) VALUES (?, ?, ?, 1, ?)',
                    [$email, $pwd, $fullName ?: 'Subaccount Contact', date('Y-m-d H:i:s')]
                );
                $subUserId = (int) $this->targetDb->getPdo()->lastInsertId();

                // If parent user has an organization, add subaccount as member
                $org = $this->targetDb->selectOne('SELECT id FROM organizations WHERE owner_user_id = ?', [$targetUserId]);
                if ($org !== null) {
                    $this->targetDb->statement(
                        'INSERT OR IGNORE INTO organization_members (organization_id, user_id, role) VALUES (?, ?, "member")',
                        [(int) $org['id'], $subUserId]
                    );
                }
            }
        }
    }

    private function ensureOrganizationForClient(int $ownerUserId, string $companyName): void
    {
        $slug = $this->slugify($companyName);
        if ($slug === '') {
            $slug = 'org-' . $ownerUserId;
        }

        $existing = $this->targetDb->selectOne('SELECT id FROM organizations WHERE slug = ?', [$slug]);
        if ($existing !== null) {
            $orgId = (int) $existing['id'];
        } else {
            $this->targetDb->statement(
                'INSERT INTO organizations (name, slug, owner_user_id) VALUES (?, ?, ?)',
                [$companyName, $slug, $ownerUserId]
            );
            $orgId = (int) $this->targetDb->getPdo()->lastInsertId();
        }

        // Link as owner member
        $member = $this->targetDb->selectOne(
            'SELECT id FROM organization_members WHERE organization_id = ? AND user_id = ?',
            [$orgId, $ownerUserId]
        );

        if ($member === null) {
            $this->targetDb->statement(
                'INSERT INTO organization_members (organization_id, user_id, role) VALUES (?, ?, "owner")',
                [$orgId, $ownerUserId]
            );
        }
    }

    private function slugify(string $text): string
    {
        $clean = preg_replace('~[^\pL\d]+~u', '-', $text);
        $clean = trim((string) $clean, '-');
        $clean = strtolower($clean);
        return $clean !== '' ? $clean : 'entity';
    }
}
