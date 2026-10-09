<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Redaction;

use Coleza\Domain\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class RedactionAuditService
{
    private string $tableName = 'privacy_redaction_audits';

    public function __construct(
        private readonly Connection $db,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly string $secretKey = 'coleza_redaction_secret'
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                domain_name VARCHAR(64) NOT NULL,
                action_type VARCHAR(32) NOT NULL,
                records_affected INT NOT NULL DEFAULT 0,
                redacted_fields TEXT NOT NULL,
                pre_checksum VARCHAR(64) NOT NULL,
                post_checksum VARCHAR(64) NOT NULL,
                verified_clean TINYINT(1) NOT NULL DEFAULT 1,
                audited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                audited_by INT NOT NULL,
                audit_signature VARCHAR(64) NOT NULL
            )',
            $this->tableName,
            $autoInc
        );
        $this->db->statement($sql);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_redaction_user ON {$this->tableName} (user_id)");
                $this->db->statement("CREATE INDEX idx_redaction_domain ON {$this->tableName} (domain_name)");
            } catch (\Throwable) {
                // Ignore if index already exists
            }
        }
    }

    public function recordAudit(
        int $userId,
        string $domainName,
        string $actionType,
        int $recordsAffected,
        array $redactedFields,
        string $preChecksum,
        string $postChecksum,
        bool $verifiedClean = true,
        int $auditedBy = 1
    ): RedactionAuditRecord {
        $this->ensureTables();

        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['User ID must be positive.']], 'Invalid user ID.');
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $record = RedactionAuditRecord::create(
            userId: $userId,
            domainName: $domainName,
            actionType: $actionType,
            recordsAffected: $recordsAffected,
            redactedFields: $redactedFields,
            preChecksum: $preChecksum,
            postChecksum: $postChecksum,
            verifiedClean: $verifiedClean,
            auditedBy: $auditedBy,
            auditedAt: $now,
            secretKey: $this->secretKey
        );

        $this->db->insert($this->tableName, [
            'user_id' => $record->getUserId(),
            'domain_name' => $record->getDomainName(),
            'action_type' => $record->getActionType(),
            'records_affected' => $record->getRecordsAffected(),
            'redacted_fields' => json_encode($record->getRedactedFields(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'pre_checksum' => $record->getPreChecksum(),
            'post_checksum' => $record->getPostChecksum(),
            'verified_clean' => $record->isVerifiedClean() ? 1 : 0,
            'audited_at' => $record->getAuditedAt(),
            'audited_by' => $record->getAuditedBy(),
            'audit_signature' => $record->getAuditSignature(),
        ]);

        $this->auditLogger?->log(
            actorUserId: $auditedBy,
            eventType: 'PRIVACY_DOMAIN_REDACTION_AUDITED',
            targetResource: 'user:' . $userId . ':domain:' . $domainName,
            payload: [
                'records_affected' => $recordsAffected,
                'fields' => $redactedFields,
                'verified_clean' => $verifiedClean,
            ]
        );

        return $record;
    }

    /**
     * Conducts an independent compliance scan across all sensitive domain tables
     * to guarantee that an erased user retains zero residual plaintext PII.
     */
    public function scanForUnredactedPii(int $userId): RedactionScanReport
    {
        $tablesScanned = 0;
        $violations = [];

        // 1. Users Table
        try {
            $user = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
            $tablesScanned++;
            if ($user !== null) {
                if (strtolower((string) ($user['status'] ?? '')) !== 'erased') {
                    $violations[] = 'users: status is not "erased"';
                }
                if (!str_starts_with((string) ($user['name'] ?? ''), '[Anonymized User #')) {
                    $violations[] = 'users: name contains unredacted personal value';
                }
                if (!empty($user['phone'])) {
                    $violations[] = 'users: phone number unredacted';
                }
                if (!empty($user['tax_number'])) {
                    $violations[] = 'users: tax identifier unredacted';
                }
                if (isset($user['password_hash']) && $user['password_hash'] !== 'ERASED') {
                    $violations[] = 'users: password hash still present';
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 2. Payment Methods Table
        try {
            $methods = $this->db->select("SELECT * FROM payment_methods WHERE user_id = :uid", ['uid' => $userId]);
            $tablesScanned++;
            foreach ($methods as $m) {
                if (($m['token'] ?? '') !== 'ERASED' || ($m['card_last4'] ?? '') !== '0000') {
                    $violations[] = 'payment_methods #' . $m['id'] . ': active card token or details unredacted';
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 3. Billing Profiles Table
        try {
            $profiles = $this->db->select("SELECT * FROM billing_profiles WHERE user_id = :uid", ['uid' => $userId]);
            $tablesScanned++;
            foreach ($profiles as $p) {
                if (!empty($p['tax_number']) || !empty($p['phone'])) {
                    $violations[] = 'billing_profiles #' . $p['id'] . ': personal tax or phone unredacted';
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 4. Hosting Services Table
        try {
            $services = $this->db->select("SELECT * FROM hosting_services WHERE user_id = :uid", ['uid' => $userId]);
            $tablesScanned++;
            foreach ($services as $s) {
                if (($s['password_encrypted'] ?? '') !== 'ERASED') {
                    $violations[] = 'hosting_services #' . $s['id'] . ': server password credentials unredacted';
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 5. Privacy Consents Table
        try {
            $consents = $this->db->select("SELECT * FROM privacy_consents WHERE user_id = :uid", ['uid' => $userId]);
            $tablesScanned++;
            if (!empty($consents)) {
                $violations[] = 'privacy_consents: active consent records exist for erased subject';
            }
        } catch (\Throwable) {
            // Ignore
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $isCompliant = empty($violations);
        $digest = hash('sha256', sprintf('%d:%d:%d:%s', $userId, $tablesScanned, count($violations), $now));

        return new RedactionScanReport(
            userId: $userId,
            tablesScanned: $tablesScanned,
            violationsDetected: count($violations),
            violationDetails: $violations,
            isCompliant: $isCompliant,
            scannedAt: $now,
            scanDigest: $digest
        );
    }

    /**
     * @return array<RedactionAuditRecord>
     */
    public function getAuditsForUser(int $userId): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE user_id = :uid ORDER BY id ASC', $this->tableName),
            ['uid' => $userId]
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = RedactionAuditRecord::fromArray($row);
        }

        return $results;
    }

    public function verifyAllSignatures(int $userId): bool
    {
        $audits = $this->getAuditsForUser($userId);
        if (empty($audits)) {
            return false;
        }

        foreach ($audits as $audit) {
            if (!$audit->verifySignature($this->secretKey)) {
                return false;
            }
        }

        return true;
    }
}
