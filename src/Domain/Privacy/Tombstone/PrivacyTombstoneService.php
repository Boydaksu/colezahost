<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use Coleza\Domain\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class PrivacyTombstoneService
{
    private string $tableName = 'privacy_tombstones';

    public function __construct(
        private readonly Connection $db,
        private readonly ?TombstoneStoreInterface $persistentStore = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly string $secretKey = 'coleza_tombstone_key',
        private readonly string $salt = 'coleza_privacy_salt'
    ) {
    }

    public function getSalt(): string
    {
        return $this->salt;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
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
                user_id INT NOT NULL UNIQUE,
                user_email_hash VARCHAR(64) NOT NULL,
                erasure_type VARCHAR(32) NOT NULL,
                reason TEXT NOT NULL,
                erasure_checksum VARCHAR(64) NOT NULL,
                metadata TEXT NULL,
                tombstoned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                integrity_signature VARCHAR(64) NOT NULL
            )',
            $this->tableName,
            $autoInc
        );
        $this->db->statement($sql);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_tombstone_email_hash ON {$this->tableName} (user_email_hash)");
            } catch (\Throwable) {
                // Ignore if index already exists
            }
        }
    }

    public function recordTombstone(
        int $userId,
        string $email,
        string $erasureType = 'ANONYMIZE',
        string $reason = 'GDPR Right to Erasure',
        string $erasureChecksum = '',
        array $metadata = []
    ): PrivacyTombstone {
        $this->ensureTables();

        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['Valid positive user ID is required.']], 'Invalid user ID.');
        }

        $existing = $this->getTombstoneByUserId($userId);
        if ($existing !== null) {
            return $existing;
        }

        if ($erasureChecksum === '') {
            $erasureChecksum = hash('sha256', sprintf('%d:%s:%s', $userId, $email, date('Y-m-d H:i:s')));
        }

        $tombstone = PrivacyTombstone::create(
            userId: $userId,
            email: $email,
            erasureType: $erasureType,
            reason: $reason,
            erasureChecksum: $erasureChecksum,
            metadata: $metadata,
            tombstonedAt: date('Y-m-d H:i:s'),
            secretKey: $this->secretKey,
            salt: $this->salt
        );

        $this->db->insert($this->tableName, [
            'user_id' => $tombstone->getUserId(),
            'user_email_hash' => $tombstone->getUserEmailHash(),
            'erasure_type' => $tombstone->getErasureType(),
            'reason' => $tombstone->getReason(),
            'erasure_checksum' => $tombstone->getErasureChecksum(),
            'metadata' => json_encode($tombstone->getMetadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tombstoned_at' => $tombstone->getTombstonedAt(),
            'integrity_signature' => $tombstone->getIntegritySignature(),
        ]);

        // Persist out-of-band so it survives future database restore
        $this->persistentStore?->persist($tombstone);

        $this->auditLogger?->log(
            actorUserId: $userId,
            eventType: 'PRIVACY_TOMBSTONE_CREATED',
            targetResource: 'user:' . $userId,
            payload: [
                'user_id' => $userId,
                'email_hash' => $tombstone->getUserEmailHash(),
                'erasure_type' => $erasureType,
                'checksum' => $erasureChecksum,
            ]
        );

        return $tombstone;
    }

    public function isTombstoned(int $userId): bool
    {
        $this->ensureTables();

        // Check DB first
        $row = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid', $this->tableName),
            ['uid' => $userId]
        );

        if ($row !== null) {
            return true;
        }

        // Check persistent store fallback
        return $this->persistentStore?->hasUserId($userId) ?? false;
    }

    public function isEmailTombstoned(string $email): bool
    {
        $this->ensureTables();
        $hash = PrivacyTombstone::computeEmailHash($email, $this->salt);

        $row = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_email_hash = :hash', $this->tableName),
            ['hash' => $hash]
        );

        if ($row !== null) {
            return true;
        }

        // Check persistent store
        if ($this->persistentStore !== null) {
            foreach ($this->persistentStore->loadAll() as $tombstone) {
                if (hash_equals($tombstone->getUserEmailHash(), $hash)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getTombstoneByUserId(int $userId): ?PrivacyTombstone
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE user_id = :uid', $this->tableName),
            ['uid' => $userId]
        );

        if ($row !== null) {
            return PrivacyTombstone::fromArray($row);
        }

        return $this->persistentStore?->findByUserId($userId);
    }

    public function getTombstoneByEmailHash(string $emailHash): ?PrivacyTombstone
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE user_email_hash = :hash', $this->tableName),
            ['hash' => $emailHash]
        );

        if ($row !== null) {
            return PrivacyTombstone::fromArray($row);
        }

        if ($this->persistentStore !== null) {
            foreach ($this->persistentStore->loadAll() as $tombstone) {
                if (hash_equals($tombstone->getUserEmailHash(), $emailHash)) {
                    return $tombstone;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, PrivacyTombstone>
     */
    public function getAllTombstones(): array
    {
        $this->ensureTables();

        $rows = $this->db->select(sprintf('SELECT * FROM %s ORDER BY id ASC', $this->tableName));
        $result = [];

        foreach ($rows as $row) {
            $tombstone = PrivacyTombstone::fromArray($row);
            $result[$tombstone->getUserId()] = $tombstone;
        }

        // Merge any out-of-band tombstones not yet in database
        if ($this->persistentStore !== null) {
            foreach ($this->persistentStore->loadAll() as $userId => $tombstone) {
                if (!isset($result[$userId])) {
                    $result[$userId] = $tombstone;
                }
            }
        }

        return $result;
    }

    /**
     * Synchronize persistent out-of-band store into operational database table.
     * Essential after a database backup restore has occurred.
     */
    public function syncPersistentStoreToDatabase(): int
    {
        $this->ensureTables();
        if ($this->persistentStore === null) {
            return 0;
        }

        $all = $this->persistentStore->loadAll();
        $synced = 0;

        foreach ($all as $tombstone) {
            $row = $this->db->selectOne(
                sprintf('SELECT id FROM %s WHERE user_id = :uid', $this->tableName),
                ['uid' => $tombstone->getUserId()]
            );

            if ($row === null) {
                $this->db->insert($this->tableName, [
                    'user_id' => $tombstone->getUserId(),
                    'user_email_hash' => $tombstone->getUserEmailHash(),
                    'erasure_type' => $tombstone->getErasureType(),
                    'reason' => $tombstone->getReason(),
                    'erasure_checksum' => $tombstone->getErasureChecksum(),
                    'metadata' => json_encode($tombstone->getMetadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'tombstoned_at' => $tombstone->getTombstonedAt(),
                    'integrity_signature' => $tombstone->getIntegritySignature(),
                ]);
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Synchronize operational database tombstones into persistent out-of-band store.
     */
    public function syncDatabaseToPersistentStore(): int
    {
        $this->ensureTables();
        if ($this->persistentStore === null) {
            return 0;
        }

        $rows = $this->db->select(sprintf('SELECT * FROM %s', $this->tableName));
        $synced = 0;

        foreach ($rows as $row) {
            $tombstone = PrivacyTombstone::fromArray($row);
            if (!$this->persistentStore->hasUserId($tombstone->getUserId())) {
                $this->persistentStore->persist($tombstone);
                $synced++;
            }
        }

        return $synced;
    }
}
