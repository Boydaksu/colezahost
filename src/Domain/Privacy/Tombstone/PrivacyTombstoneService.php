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
        #[\SensitiveParameter] private readonly ?string $secretKey = null,
        #[\SensitiveParameter] private readonly ?string $salt = null
    ) {
    }

    public function getSalt(): string
    {
        return \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('email', $this->salt);
    }

    public function getSecretKey(): string
    {
        return \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('tombstone', $this->secretKey);
    }

    public function preflightSigningKeys(): void
    {
        if (hash_equals($this->getSecretKey(), $this->getSalt())) {
            throw new \RuntimeException('Privacy email and integrity keys must differ.');
        }
    }

    public function assertTrusted(PrivacyTombstone $record): void
    {
        $this->preflightSigningKeys();
        if (!$record->verifyIntegrity($this->getSecretKey())
            || ($record->getMetadata()['privacy_email_key_id'] ?? null) !== \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::id($this->getSalt())) {
            throw new \RuntimeException('Privacy tombstone integrity or email key verification failed; reconciliation refused.');
        }
    }

    public function ensureTables(): void
    {
        PrivacyTombstoneSchema::ensure($this->db);
    }

    public function recordTombstone(
        int $userId,
        string $email,
        string $erasureType = 'ANONYMIZE',
        string $reason = 'GDPR Right to Erasure',
        string $erasureChecksum = '',
        array $metadata = []
    ): PrivacyTombstone {
        $this->preflightSigningKeys();
        $this->ensureTables();

        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['Valid positive user ID is required.']], 'Invalid user ID.');
        }

        $existing = $this->getTombstoneByUserId($userId);
        if ($existing !== null) {
            $this->assertTrusted($existing);
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
            secretKey: $this->getSecretKey(),
            salt: $this->getSalt()
        );

        $this->db->insert($this->tableName, [
            'user_id' => $tombstone->getUserId(),
            'user_email_hash' => $tombstone->getUserEmailHash(),
            'erasure_type' => $tombstone->getErasureType(),
            'reason' => $tombstone->getReason(),
            'erasure_checksum' => $tombstone->getErasureChecksum(),
            'metadata' => json_encode($tombstone->getMetadata(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
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
        foreach ($this->getAllTombstones() as $record) {
            if ($record->matchesEmail($email, $this->getSalt())) { return true; }
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
                if (isset($result[$userId]) && (\Coleza\Domain\Privacy\Integrity\PrivacySignature::encode($result[$userId]->integrityData()) !== \Coleza\Domain\Privacy\Integrity\PrivacySignature::encode($tombstone->integrityData())
                    || !hash_equals($result[$userId]->getIntegritySignature(), $tombstone->getIntegritySignature()))) {
                    throw new \RuntimeException('Conflicting database and persistent tombstones.');
                }
                if (!isset($result[$userId])) {
                    $result[$userId] = $tombstone;
                }
            }
        }

        foreach ($result as $record) { $this->assertTrusted($record); }
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

        $this->getAllTombstones(); // Verify the entire database and file inventory before inserting anything.
        $all = $this->persistentStore->loadAll();
        foreach ($all as $record) { $this->assertTrusted($record); }
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
                    'metadata' => json_encode($tombstone->getMetadata(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
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

        $this->getAllTombstones(); // Fail before writing a partial persistent inventory.
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
