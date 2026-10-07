<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class PrivacyConsentService
{
    private string $consentTable = 'privacy_consents';
    private string $tombstoneTable = 'privacy_tombstones';

    public function __construct(
        private Connection $db,
        private ?AuditLogger $auditLogger = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Privacy consents table
        $sqlConsent = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                purpose VARCHAR(100) NOT NULL,
                is_granted INT NOT NULL DEFAULT 1,
                granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                revoked_at TIMESTAMP NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                UNIQUE(user_id, purpose)
            )',
            $this->consentTable,
            $autoInc
        );
        $this->db->statement($sqlConsent);

        // Privacy tombstones table (persists erasure status to prevent revival after backup restore)
        $sqlTombstone = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL UNIQUE,
                erasure_type VARCHAR(50) NOT NULL,
                reason VARCHAR(255) NULL,
                tombstoned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->tombstoneTable,
            $autoInc
        );
        $this->db->statement($sqlTombstone);
    }

    /**
     * Grant or update consent for a specific processing purpose (e.g. 'marketing_email', 'third_party_analytics').
     */
    public function grantConsent(int $userId, string $purpose, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->ensureTables();
        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid AND purpose = :p', $this->consentTable),
            ['uid' => $userId, 'p' => $purpose]
        );

        if ($existing === null) {
            $this->db->insert($this->consentTable, [
                'user_id' => $userId,
                'purpose' => $purpose,
                'is_granted' => 1,
                'granted_at' => date('Y-m-d H:i:s'),
                'revoked_at' => null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
        } else {
            $this->db->update(
                $this->consentTable,
                [
                    'is_granted' => 1,
                    'granted_at' => date('Y-m-d H:i:s'),
                    'revoked_at' => null,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                ],
                'id = :id',
                ['id' => $existing['id']]
            );
        }

        $this->auditLogger?->log(
            actorUserId: $userId,
            eventType: 'PRIVACY_CONSENT_GRANTED',
            targetResource: 'purpose:' . $purpose,
            ip: $ip,
            userAgent: $userAgent
        );
    }

    /**
     * Revoke previously granted consent.
     */
    public function revokeConsent(int $userId, string $purpose, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->ensureTables();
        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid AND purpose = :p', $this->consentTable),
            ['uid' => $userId, 'p' => $purpose]
        );

        if ($existing !== null) {
            $this->db->update(
                $this->consentTable,
                [
                    'is_granted' => 0,
                    'revoked_at' => date('Y-m-d H:i:s'),
                ],
                'id = :id',
                ['id' => $existing['id']]
            );
        }

        $this->auditLogger?->log(
            actorUserId: $userId,
            eventType: 'PRIVACY_CONSENT_REVOKED',
            targetResource: 'purpose:' . $purpose,
            ip: $ip,
            userAgent: $userAgent
        );
    }

    /**
     * Check if user has active consent for purpose.
     */
    public function hasConsent(int $userId, string $purpose): bool
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            sprintf('SELECT is_granted FROM %s WHERE user_id = :uid AND purpose = :p', $this->consentTable),
            ['uid' => $userId, 'p' => $purpose]
        );

        return $row !== null && (int) $row['is_granted'] === 1;
    }

    /**
     * Create privacy tombstone on user erasure request (in accordance with Data Constitution).
     * Prevents old database backup restores from resurrecting erased customer data.
     */
    public function recordTombstone(int $userId, string $erasureType = 'ANONYMIZE', ?string $reason = null): void
    {
        $this->ensureTables();
        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid', $this->tombstoneTable),
            ['uid' => $userId]
        );

        if ($existing === null) {
            $this->db->insert($this->tombstoneTable, [
                'user_id' => $userId,
                'erasure_type' => $erasureType,
                'reason' => $reason,
            ]);
        }

        $this->auditLogger?->log(
            actorUserId: $userId,
            eventType: 'PRIVACY_TOMBSTONE_CREATED',
            targetResource: 'user:' . $userId,
            payload: ['erasure_type' => $erasureType, 'reason' => $reason]
        );
    }

    /**
     * Check if user ID is tombstoned.
     */
    public function isTombstoned(int $userId): bool
    {
        $this->ensureTables();
        $row = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid', $this->tombstoneTable),
            ['uid' => $userId]
        );

        return $row !== null;
    }
}
