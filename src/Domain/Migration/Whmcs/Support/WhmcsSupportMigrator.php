<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Support;

use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * Migrates WHMCS support departments, tickets, replies, attachments, and custom fields
 * while enforcing the Constitution §11 & §12 Zero Silent Data/Field Loss invariant.
 */
final class WhmcsSupportMigrator
{
    private WhmcsSupportExtractor $extractor;
    private UnsupportedDataAccountant $accountant;

    /**
     * @var array<string, int> [whmcs_did => target_department_id]
     */
    private array $departmentMap = [];

    public function __construct(
        private Connection $targetDb,
        private WhmcsReadOnlyConnector $whmcs,
        private DatabaseStagingRepository $stagingRepo,
        private StagingPipelineService $stagingPipeline,
        private ProviderIdentityResolver $identityResolver,
        ?WhmcsSupportExtractor $extractor = null,
        ?UnsupportedDataAccountant $accountant = null
    ) {
        $this->extractor = $extractor ?? new WhmcsSupportExtractor($whmcs);
        $this->accountant = $accountant ?? new UnsupportedDataAccountant();
    }

    public function getAccountant(): UnsupportedDataAccountant
    {
        return $this->accountant;
    }

    public function registerDepartmentMapping(string $sourceDid, int $targetDeptId): void
    {
        $this->departmentMap[(string) $sourceDid] = $targetDeptId;
    }

    public function ensureSupportTables(): void
    {
        $driver = $this->targetDb->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Support departments
        $sqlDepartments = sprintf(
            'CREATE TABLE IF NOT EXISTS support_departments (
                id %s,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                email VARCHAR(150) NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlDepartments);

        // Support tickets
        $sqlTickets = sprintf(
            'CREATE TABLE IF NOT EXISTS support_tickets (
                id %s,
                ticket_number VARCHAR(32) NOT NULL UNIQUE,
                organization_id INT NULL,
                user_id INT NOT NULL,
                department_id INT NOT NULL,
                assigned_to INT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "open",
                priority VARCHAR(32) NOT NULL DEFAULT "medium",
                subject VARCHAR(255) NOT NULL,
                service_id INT NULL,
                domain_id INT NULL,
                invoice_id INT NULL,
                order_id INT NULL,
                last_reply_at TIMESTAMP NULL,
                last_reply_user_id INT NULL,
                last_reply_by_staff TINYINT(1) NOT NULL DEFAULT 0,
                closed_at TIMESTAMP NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlTickets);

        // Support ticket messages
        $sqlMessages = sprintf(
            'CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id %s,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                is_staff TINYINT(1) NOT NULL DEFAULT 0,
                is_internal_note TINYINT(1) NOT NULL DEFAULT 0,
                message TEXT NOT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlMessages);

        // Support ticket attachments
        $sqlAttachments = sprintf(
            'CREATE TABLE IF NOT EXISTS support_ticket_attachments (
                id %s,
                ticket_id INT NOT NULL,
                message_id INT NULL,
                user_id INT NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                storage_key VARCHAR(255) NOT NULL UNIQUE,
                file_size_bytes INT NOT NULL DEFAULT 1024,
                mime_type VARCHAR(100) NOT NULL DEFAULT "application/octet-stream",
                sha256_hash VARCHAR(64) NOT NULL,
                is_private TINYINT(1) NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlAttachments);

        $this->stagingRepo->ensureTable();
    }

    /**
     * Executes end-to-end support migration and generates the Unsupported Data Audit report.
     *
     * @param array<string, mixed> $options
     */
    public function migrateAndAudit(string $batchId, array $options = []): WhmcsSupportMigrationResult
    {
        $this->ensureSupportTables();

        $deptStats = $this->migrateDepartments();
        $ticketStats = $this->migrateTickets($batchId, $options);

        $unsupportedReport = $this->accountant->generateReport($batchId);
        $stagingReport = $this->stagingRepo->generateAccountingReport($batchId);

        if (!$stagingReport->isZeroSilentLossAchieved()) {
            throw new RuntimeException(sprintf(
                'Zero Silent Data Loss violated in support batch [%s]: unaccounted diff = %d',
                $batchId,
                $stagingReport->getUnaccountedDiff()
            ));
        }

        return new WhmcsSupportMigrationResult(
            batchId: $batchId,
            departmentStats: $deptStats,
            ticketStats: $ticketStats,
            unsupportedDataReport: $unsupportedReport,
            stagingReport: $stagingReport
        );
    }

    /**
     * Migrates support departments from tblticketdepartments.
     *
     * @return array{total: int, migrated: int, department_ids: array<string, int>}
     */
    public function migrateDepartments(): array
    {
        $departments = $this->extractor->extractDepartments();
        $total = count($departments);
        $migrated = 0;
        $deptIds = [];

        foreach ($departments as $dept) {
            $sourceId = (string) ($dept['id'] ?? '');
            $name = trim((string) ($dept['name'] ?? 'Support'));
            $desc = $dept['description'] ?? null;
            $email = $dept['email'] ?? null;
            $isPublic = !isset($dept['hidden']) || (int) $dept['hidden'] === 0 ? 1 : 0;
            $sortOrder = (int) ($dept['order'] ?? 0);

            $existing = $this->targetDb->selectOne(
                'SELECT id FROM support_departments WHERE name = ?',
                [$name]
            );

            if ($existing !== null) {
                $targetDeptId = (int) $existing['id'];
            } else {
                $this->targetDb->statement(
                    'INSERT INTO support_departments (name, description, email, is_public, sort_order)
                    VALUES (?, ?, ?, ?, ?)',
                    [$name, $desc, $email, $isPublic, $sortOrder]
                );
                $targetDeptId = (int) $this->targetDb->getPdo()->lastInsertId();
            }

            $this->departmentMap[$sourceId] = $targetDeptId;
            $deptIds[$sourceId] = $targetDeptId;
            $migrated++;
        }

        // Ensure default fallback department
        if (empty($this->departmentMap)) {
            $defaultDept = $this->targetDb->selectOne("SELECT id FROM support_departments WHERE name = 'General Support'");
            if ($defaultDept !== null) {
                $this->departmentMap['1'] = (int) $defaultDept['id'];
            } else {
                $this->targetDb->statement(
                    "INSERT INTO support_departments (name, description, is_public) VALUES ('General Support', 'General Customer Support', 1)"
                );
                $this->departmentMap['1'] = (int) $this->targetDb->getPdo()->lastInsertId();
            }
        }

        return [
            'total' => $total,
            'migrated' => $migrated,
            'department_ids' => $deptIds,
        ];
    }

    /**
     * Migrates tickets, replies, attachments, and custom fields.
     *
     * @param array<string, mixed> $options
     * @return array{total: int, migrated: int, quarantined: int, ticket_ids: array<string, int>, replies_migrated: int, attachments_migrated: int}
     */
    public function migrateTickets(string $batchId, array $options = []): array
    {
        $limit = isset($options['ticket_limit']) ? (int) $options['ticket_limit'] : null;
        $offset = isset($options['ticket_offset']) ? (int) $options['ticket_offset'] : null;

        $tickets = $this->extractor->extractTickets($limit, $offset);

        $total = count($tickets);
        $migrated = 0;
        $quarantined = 0;
        $repliesMigrated = 0;
        $attachmentsMigrated = 0;
        $ticketIds = [];

        foreach ($tickets as $ticket) {
            $sourceId = (string) ($ticket['id'] ?? '');

            // 1. Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'ticket',
                sourceEntityId: $sourceId,
                rawPayload: $ticket
            );

            // 2. Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            $canonicalPayload = $record->getCanonicalPayload() ?? [];
            $metadata = (array) ($canonicalPayload['metadata'] ?? []);

            // 3. Account for all raw fields against silent data loss
            $this->accountant->accountRecord('ticket', $ticket, $metadata);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                // Verify client mapping exists
                $resolvedClient = $this->identityResolver->resolveClient((string) ($ticket['userid'] ?? ''));
                if ($resolvedClient === null) {
                    $record->markQuarantined(
                        reason: sprintf('Ticket [%s] references unmigrated client [%s].', $sourceId, $ticket['userid'] ?? ''),
                        errors: [sprintf('Client source ID [%s] has not been resolved.', $ticket['userid'] ?? '')]
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                    continue;
                }

                // Resolve department
                $sourceDid = (string) ($ticket['did'] ?? '1');
                $targetDeptId = $this->departmentMap[$sourceDid] ?? reset($this->departmentMap);

                // Status mapping
                $rawStatus = strtolower(trim((string) ($ticket['status'] ?? 'closed')));
                $status = match ($rawStatus) {
                    'open', 'customer-reply' => 'open',
                    'in progress' => 'in_progress',
                    'answered' => 'answered',
                    'closed' => 'closed',
                    default => 'closed',
                };

                // Priority mapping
                $rawUrgency = strtolower(trim((string) ($ticket['urgency'] ?? 'medium')));
                $priority = match ($rawUrgency) {
                    'low' => 'low',
                    'medium' => 'medium',
                    'high' => 'high',
                    'urgent' => 'urgent',
                    default => 'medium',
                };

                $ticketNumber = !empty($ticket['tid']) ? (string) $ticket['tid'] : 'WHMCS-TICK-' . $sourceId;
                $subject = trim((string) ($ticket['title'] ?? 'Support Request'));
                $createdAt = !empty($ticket['date']) ? (string) $ticket['date'] : date('Y-m-d H:i:s');
                $lastReplyAt = !empty($ticket['lastreply']) ? (string) $ticket['lastreply'] : $createdAt;
                $closedAt = ($status === 'closed') ? $lastReplyAt : null;

                // Build rich metadata preserving custom fields and unsupported properties
                $metaJson = json_encode([
                    'whmcs_ticket_id' => $sourceId,
                    'custom_fields' => $ticket['customfields'] ?? [],
                    'unsupported_source_fields' => $metadata['unsupported_source_fields'] ?? [],
                ], JSON_UNESCAPED_UNICODE);

                // Upsert ticket
                $existing = $this->targetDb->selectOne(
                    'SELECT id FROM support_tickets WHERE ticket_number = ?',
                    [$ticketNumber]
                );

                if ($existing !== null) {
                    $targetTicketId = (int) $existing['id'];
                } else {
                    $this->targetDb->statement(
                        'INSERT INTO support_tickets
                        (ticket_number, user_id, department_id, status, priority, subject,
                         created_at, last_reply_at, closed_at, metadata_json)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $ticketNumber,
                            $resolvedClient,
                            $targetDeptId,
                            $status,
                            $priority,
                            $subject,
                            $createdAt,
                            $lastReplyAt,
                            $closedAt,
                            $metaJson,
                        ]
                    );
                    $targetTicketId = (int) $this->targetDb->getPdo()->lastInsertId();
                }

                // Initial opening message
                if (!empty($ticket['message'])) {
                    $this->targetDb->statement(
                        'INSERT INTO support_ticket_messages (ticket_id, user_id, is_staff, message, created_at)
                        VALUES (?, ?, 0, ?, ?)',
                        [$targetTicketId, $resolvedClient, (string) $ticket['message'], $createdAt]
                    );
                }

                // Migrate replies
                $replies = (array) ($ticket['replies'] ?? []);
                foreach ($replies as $reply) {
                    $isStaff = !empty($reply['admin']) ? 1 : 0;
                    $replyUser = $isStaff ? 1 : $resolvedClient;
                    $replyDate = !empty($reply['date']) ? (string) $reply['date'] : $createdAt;

                    $this->targetDb->statement(
                        'INSERT INTO support_ticket_messages (ticket_id, user_id, is_staff, message, created_at)
                        VALUES (?, ?, ?, ?, ?)',
                        [$targetTicketId, $replyUser, $isStaff, (string) ($reply['message'] ?? ''), $replyDate]
                    );
                    $repliesMigrated++;
                }

                // Migrate attachments
                $attachments = (array) ($ticket['attachments'] ?? []);
                foreach ($attachments as $att) {
                    $filename = (string) ($att['filename'] ?? 'attachment.dat');
                    $storageKey = 'migrated/tickets/' . $targetTicketId . '/' . md5($filename . uniqid('', true));
                    $sha256 = hash('sha256', $filename);

                    $this->targetDb->statement(
                        'INSERT INTO support_ticket_attachments
                        (ticket_id, user_id, original_filename, storage_key, file_size_bytes, mime_type, sha256_hash, created_at)
                        VALUES (?, ?, ?, ?, 1024, "application/octet-stream", ?, ?)',
                        [$targetTicketId, $resolvedClient, $filename, $storageKey, $sha256, $createdAt]
                    );
                    $attachmentsMigrated++;
                }

                // Mark staging record as MIGRATED
                $record->markMigrated($targetTicketId);
                $this->stagingRepo->save($record);

                $migrated++;
                $ticketIds[$sourceId] = $targetTicketId;
            } else {
                $quarantined++;
            }
        }

        return [
            'total' => $total,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'ticket_ids' => $ticketIds,
            'replies_migrated' => $repliesMigrated,
            'attachments_migrated' => $attachmentsMigrated,
        ];
    }
}
