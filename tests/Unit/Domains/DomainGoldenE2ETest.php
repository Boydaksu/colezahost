<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\Catalog\TldPricing;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Lifecycle\DomainExpiryService;
use Coleza\Domain\Domains\Lifecycle\DomainLifecyclePolicy;
use Coleza\Domain\Domains\Lifecycle\DomainRenewalReminder;
use Coleza\Domain\Domains\Registrar\Adapters\NameSilo\NameSiloRegistrarAdapter;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainRegistrarService;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperation;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationReconciliationService;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository;
use Coleza\Domain\Domains\Registrar\Reconciliation\IdempotentDomainOperationService;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Coleza\Domain\Domains\Registrar\Vault\VaultRegistrarSettingsManager;
use Coleza\Domain\Vault\Encryptor;
use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DomainGoldenE2ETest extends TestCase
{
    private Connection $db;
    private DomainCatalogService $catalogService;
    private DomainService $domainService;
    private RegistrarRegistry $registrarRegistry;
    private DomainRegistrarService $flowService;
    private DomainOperationRepository $operationRepo;
    private IdempotentDomainOperationService $idempotentService;
    private DomainOperationReconciliationService $reconciliationService;
    private DomainExpiryService $expiryService;
    private VaultService $vaultService;
    private VaultRegistrarSettingsManager $vaultSettingsManager;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // 1. Vault Service setup
        $encryptor = new Encryptor('0123456789abcdef0123456789abcdef');
        $this->vaultService = new VaultService($this->db, $encryptor);
        $this->vaultService->ensureTable();

        $this->vaultSettingsManager = new VaultRegistrarSettingsManager($this->db, $this->vaultService);
        $this->vaultSettingsManager->ensureTables();

        // 2. Catalog Service
        $this->catalogService = new DomainCatalogService($this->db);
        $this->catalogService->ensureTables();

        // 3. Domain Service
        $this->domainService = new DomainService($this->db, $this->catalogService);
        $this->domainService->ensureTables();

        // 4. Registrar Registry
        $this->registrarRegistry = new RegistrarRegistry();

        // 5. Operations & Reconciliation
        $this->operationRepo = new DomainOperationRepository($this->db);
        $this->operationRepo->ensureTable();

        $this->flowService = new DomainRegistrarService(
            $this->domainService,
            $this->registrarRegistry,
            $this->catalogService
        );

        $this->idempotentService = new IdempotentDomainOperationService(
            $this->operationRepo,
            $this->flowService,
            $this->domainService
        );

        $this->reconciliationService = new DomainOperationReconciliationService(
            $this->operationRepo,
            $this->domainService,
            $this->registrarRegistry
        );

        // 6. Expiry & ICANN ERRP Lifecycle
        $this->expiryService = new DomainExpiryService(
            $this->db,
            $this->domainService,
            $this->catalogService,
            new DomainLifecyclePolicy(30, 30)
        );
        $this->expiryService->ensureTables();
    }

    public function testDomainGoldenEndToEndVertical(): void
    {
        // =========================================================================
        // STEP 1: CATALOG SETUP & MULTI-TIER PRICING
        // =========================================================================
        $tldCom = $this->catalogService->registerTld([
            'extension' => '.com',
            'is_active' => 1,
            'registrar_id' => 'namesilo',
            'grace_period_days' => 30,
            'redemption_period_days' => 30,
        ]);

        $this->catalogService->setTldPricing(
            tldId: $tldCom->getId(),
            operation: TldPricing::OPERATION_REGISTER,
            years: 1,
            priceMinor: 1299, // $12.99
            currencyCode: 'USD'
        );

        $this->catalogService->setTldPricing(
            tldId: $tldCom->getId(),
            operation: TldPricing::OPERATION_REGISTER,
            years: 2,
            priceMinor: 2499, // $24.99
            currencyCode: 'USD'
        );

        $this->catalogService->setTldPricing(
            tldId: $tldCom->getId(),
            operation: TldPricing::OPERATION_RENEW,
            years: 1,
            priceMinor: 1499, // $14.99
            currencyCode: 'USD'
        );

        $this->catalogService->setTldPricing(
            tldId: $tldCom->getId(),
            operation: TldPricing::OPERATION_RESTORE,
            years: 1,
            priceMinor: 8000, // $80.00 redemption restore fee
            currencyCode: 'USD'
        );

        // Multi-part TLD support (.com.tr)
        $tldComTr = $this->catalogService->registerTld([
            'extension' => '.com.tr',
            'is_active' => 1,
            'registrar_id' => 'namesilo',
        ]);
        $this->assertSame('.com.tr', $tldComTr->getExtension());

        // =========================================================================
        // STEP 2: SECURE VAULT SETTINGS & PRODUCTION REGISTRAR ADAPTER INJECTION
        // =========================================================================
        $vaultConfig = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'SECRET-API-KEY-999',
            apiSecret: 'SECRET-HASH-888',
            endpoint: 'https://sandbox.namesilo.com/api',
            isSandbox: true
        );
        $this->vaultSettingsManager->saveConfiguration($vaultConfig, actorUserId: 1);

        // Verify Vault encryption and masking
        $maskedConfig = $this->vaultSettingsManager->getMaskedConfiguration('namesilo');
        $this->assertStringContainsString('••', (string) $maskedConfig['api_key']);

        // Mock production registrar adapter responses
        $remoteOrders = [];
        $remoteNameservers = ['ns1.namesilo.com', 'ns2.namesilo.com'];
        $remoteLocked = true;
        $simulatedTimeout = false;

        $adapter = new NameSiloRegistrarAdapter($vaultConfig, function (string $operation, array $params) use (
            &$remoteOrders,
            &$remoteNameservers,
            &$remoteLocked,
            &$simulatedTimeout
        ) {
            if ($simulatedTimeout) {
                throw new RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds');
            }

            return match ($operation) {
                'checkRegisterAvailability' => [
                    'reply' => [
                        'code' => 300,
                        'available' => ['domain' => ['price' => '12.99']],
                    ],
                ],
                'registerDomain' => [
                    'reply' => [
                        'code' => 300,
                        'order_id' => 'NS-ORD-' . bin2hex(random_bytes(4)),
                        'domain' => $params['domain'] ?? '',
                    ],
                ],
                'renewDomain' => [
                    'reply' => [
                        'code' => 300,
                        'order_id' => 'NS-REN-' . bin2hex(random_bytes(4)),
                    ],
                ],
                'domainChangeNameServers' => [
                    'reply' => ['code' => 300, 'detail' => 'success'],
                ],
                'getDomainInfo' => [
                    'reply' => [
                        'code' => 300,
                        'nameservers' => ['nameserver' => $remoteNameservers],
                        'locked' => $remoteLocked ? 'yes' : 'no',
                    ],
                ],
                'domainLock' => [
                    'reply' => ['code' => 300],
                ],
                'retrieveAuthCode' => [
                    'reply' => ['code' => 300, 'auth_code' => 'AUTH-CODE-SECRET-777'],
                ],
                'domainUpdateContacts' => [
                    'reply' => ['code' => 300],
                ],
                default => ['reply' => ['code' => 300]],
            };
        });

        $this->registrarRegistry->register($adapter);

        // =========================================================================
        // STEP 3: AVAILABILITY CHECK VERTICAL
        // =========================================================================
        $availResult = $this->flowService->checkAvailability('startup-unicorn.com');
        $this->assertTrue($availResult->isAvailable());
        $this->assertSame('startup-unicorn.com', $availResult->getDomain());
        $this->assertSame(12.99, $availResult->getPrice());
        $this->assertSame('USD', $availResult->getCurrency());

        // =========================================================================
        // STEP 4: ORDER PLACEMENT, CONTACTS, AND IDEMPOTENT REGISTRATION
        // =========================================================================
        $domain = $this->domainService->createDomain([
            'user_id' => 42,
            'domain' => 'startup-unicorn.com',
            'registration_period_years' => 2,
            'whois_privacy' => true,
            'nameservers' => ['ns1.namesilo.com', 'ns2.namesilo.com'],
            'registrar_id' => 'namesilo',
        ]);
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $domain->getStatus());

        // Add 4 ICANN contacts
        $this->domainService->setContact(
            domainId: $domain->getId(),
            contactType: DomainContact::TYPE_REGISTRANT,
            data: [
                'first_name' => 'Elon',
                'last_name' => 'Musketeer',
                'company_name' => 'Unicorn Tech Corp',
                'email' => 'elon@unicorn.com',
                'phone' => '+1.5558889900',
                'address_line_1' => '1 Rocket Road',
                'city' => 'Hawthorne',
                'state' => 'CA',
                'postal_code' => '90250',
                'country_code' => 'US',
            ]
        );

        $regKey = 'IDEMPOTENT-REG-KEY-100';

        // 1. First execution: calls registrar and activates domain
        $regResult = $this->idempotentService->executeRegister($domain->getId(), $regKey);
        $this->assertTrue($regResult->isSuccessful());
        $this->assertNotNull($regResult->getRemoteTransactionId());

        $activeDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertNotNull($activeDomain);
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $activeDomain->getStatus());
        $this->assertSame(date('Y-m-d', strtotime('+2 years')), $activeDomain->getExpiryDate());

        // 2. Duplicate registration attempt with same key returns cached result immediately
        $dupResult = $this->idempotentService->executeRegister($domain->getId(), $regKey);
        $this->assertTrue($dupResult->isSuccessful());
        $this->assertSame($regResult->getRemoteTransactionId(), $dupResult->getRemoteTransactionId());

        // =========================================================================
        // STEP 5: DOMAIN ADMINISTRATIVE CONTROLS & SECURITY
        // =========================================================================
        // Update nameservers
        $nsResult = $this->flowService->updateNameservers(
            domainId: $domain->getId(),
            nameservers: ['ns1.cloudflare.com', 'ns2.cloudflare.com']
        );
        $this->assertTrue($nsResult->isSuccessful());
        $this->assertSame(['ns1.cloudflare.com', 'ns2.cloudflare.com'], $this->domainService->findDomainById($domain->getId())->getNameservers());

        // Registrar lock
        $lockResult = $this->flowService->setRegistrarLock(domainId: $domain->getId(), locked: true);
        $this->assertTrue($lockResult->isSuccessful());
        $this->assertTrue($this->domainService->findDomainById($domain->getId())->isLocked());

        // EPP authorization code retrieval
        $authCode = $this->flowService->retrieveEppCode($domain->getId());
        $this->assertSame('AUTH-CODE-SECRET-777', $authCode);
        $this->assertSame('AUTH-CODE-SECRET-777', $this->domainService->findDomainById($domain->getId())->getEppCode());

        // Contact profile update & sync
        $contactSync = $this->flowService->syncContactsToRegistrar($domain->getId());
        $this->assertTrue($contactSync->isSuccessful());

        // =========================================================================
        // STEP 6: TIMEOUT SAFETY & RECONCILIATION TEST (ON SECOND DOMAIN)
        // =========================================================================
        $domain2 = $this->domainService->createDomain([
            'user_id' => 42,
            'domain' => 'reconcile-target.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'namesilo',
        ]);

        $timeoutKey = 'TIMEOUT-IDEMPOTENCY-KEY-200';
        $simulatedTimeout = true; // Trigger socket timeout

        $timeoutResult = $this->idempotentService->executeRegister($domain2->getId(), $timeoutKey);
        $this->assertFalse($timeoutResult->isSuccessful());
        $this->assertSame('UNCERTAIN_TIMEOUT', $timeoutResult->getErrorCode());

        // Domain state remains protected in pending_registration
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $this->domainService->findDomainById($domain2->getId())->getStatus());

        // Recover: enable registrar response and reconcile
        $simulatedTimeout = false;
        $uncertainOp = $this->operationRepo->findByIdempotencyKey($timeoutKey);
        $this->assertNotNull($uncertainOp);
        $this->assertTrue($uncertainOp->isUncertain());

        $reconciled = $this->reconciliationService->reconcileOperation((int) $uncertainOp->getId());
        $this->assertTrue($reconciled->isSucceeded());

        // Domain 2 is now activated
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $this->domainService->findDomainById($domain2->getId())->getStatus());

        // =========================================================================
        // STEP 7: ICANN ERRP RENEWAL REMINDERS & LIFECYCLE EVALUATION
        // =========================================================================
        // Expiry of domain 1 is 2 years from today
        $expiryDateStr = $activeDomain->getExpiryDate();
        $this->assertNotNull($expiryDateStr);
        $expiryDateTime = new DateTimeImmutable($expiryDateStr);

        // Window 1: 30 days before expiration
        $ref30d = $expiryDateTime->modify('-30 days');
        $rem30d = $this->expiryService->dispatchDueReminders($ref30d);
        $this->assertGreaterThanOrEqual(1, $rem30d['reminders_sent']);

        // Window 2: 7 days before expiration
        $ref7d = $expiryDateTime->modify('-7 days');
        $rem7d = $this->expiryService->dispatchDueReminders($ref7d);
        $this->assertGreaterThanOrEqual(1, $rem7d['reminders_sent']);

        // Window 3: 1 day past expiration -> moves to GRACE stage
        $refGrace = $expiryDateTime->modify('+1 day');
        $advGrace = $this->expiryService->evaluateAndAdvanceDomainLifecycles($refGrace);
        $this->assertGreaterThanOrEqual(1, $advGrace['transitioned_to_grace']);

        $domainInGrace = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_GRACE, $domainInGrace->getStatus());

        // During grace: renewal extends domain
        $renewKey = 'RENEW-DURING-GRACE-KEY-300';
        $renewResult = $this->idempotentService->executeRenew($domain->getId(), $renewKey, years: 1);
        $this->assertTrue($renewResult->isSuccessful());

        $renewedDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $renewedDomain->getStatus());
        $this->assertSame($expiryDateTime->modify('+1 year')->format('Y-m-d'), $renewedDomain->getExpiryDate());

        // Advance past all grace and redemption for domain 2
        // Set domain 2 expiry to past date
        $oldDate = '2026-01-01';
        $this->db->statement("UPDATE domains SET expiry_date = ?, status = 'active' WHERE id = ?", [$oldDate, $domain2->getId()]);

        // Advance to redemption (>30 days past 2026-01-01)
        $refRedemption = new DateTimeImmutable('2026-02-15');
        $this->expiryService->evaluateAndAdvanceDomainLifecycles($refRedemption);
        $this->assertSame(DomainStateMachine::STATUS_REDEMPTION, $this->domainService->findDomainById($domain2->getId())->getStatus());

        // Verify metadata shows redemption restore fee ($80.00)
        $redMeta = $this->expiryService->getDomainLifecycleMetadata($domain2->getId(), $refRedemption);
        $this->assertSame('redemption', $redMeta['lifecycle_stage']);
        $this->assertTrue($redMeta['is_restorable']);
        $this->assertSame(8000, $redMeta['restore_price_minor']);

        // Advance past redemption (>60 days past 2026-01-01) -> Cancelled
        $refCancelled = new DateTimeImmutable('2026-03-20');
        $this->expiryService->evaluateAndAdvanceDomainLifecycles($refCancelled);
        $this->assertSame(DomainStateMachine::STATUS_CANCELLED, $this->domainService->findDomainById($domain2->getId())->getStatus());

        // =========================================================================
        // STEP 8: COMPLETE TIMELINE AUDIT VERIFICATION
        // =========================================================================
        $timeline1 = $this->domainService->getTimeline($domain->getId());
        $types1 = array_map(fn($e) => $e->getEventType(), $timeline1);

        $this->assertContains('created', $types1);
        $this->assertContains('activated', $types1);
        $this->assertContains('nameservers_updated', $types1);
        $this->assertContains('registrar_nameservers_synced', $types1);
        $this->assertContains('registrar_lock_synced', $types1);
        $this->assertContains('registrar_epp_retrieved', $types1);
        $this->assertContains('reminder_sent', $types1);
        $this->assertContains('domain_entered_grace', $types1);
        $this->assertContains('renewed', $types1);

        $timeline2 = $this->domainService->getTimeline($domain2->getId());
        $types2 = array_map(fn($e) => $e->getEventType(), $timeline2);

        $this->assertContains('operation_uncertain', $types2);
        $this->assertContains('reconciled_register_success', $types2);
        $this->assertContains('domain_entered_redemption', $types2);
        $this->assertContains('domain_lifecycle_cancelled', $types2);
    }
}
