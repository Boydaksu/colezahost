<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Lists;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class FraudListService
{
    private string $table = 'fraud_list_entries';

    public function __construct(
        private readonly Connection $db
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
                list_type VARCHAR(20) NOT NULL,
                entry_type VARCHAR(30) NOT NULL,
                value VARCHAR(255) NOT NULL,
                reason TEXT NOT NULL,
                created_by INT NULL,
                expires_at TIMESTAMP NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );
        $this->db->statement($sql);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_fraud_list_lookup ON {$this->table} (entry_type, is_active)");
            } catch (\Throwable) {
                // Ignore if index exists
            }
        }
    }

    public function addEntry(
        FraudListType $listType,
        FraudListEntryType $entryType,
        string $value,
        string $reason,
        ?int $createdBy = null,
        ?DateTimeImmutable $expiresAt = null
    ): FraudListEntry {
        $this->ensureTables();

        $trimmedValue = trim($value);
        if ($trimmedValue === '') {
            throw new ValidationException(['value' => ['Fraud list value cannot be empty.']], 'Fraud list value cannot be empty.');
        }

        $now = new DateTimeImmutable();
        $data = [
            'list_type' => $listType->value,
            'entry_type' => $entryType->value,
            'value' => $trimmedValue,
            'reason' => trim($reason),
            'created_by' => $createdBy,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'is_active' => 1,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        $id = (int) $this->db->insert($this->table, $data);

        return new FraudListEntry(
            id: $id,
            listType: $listType,
            entryType: $entryType,
            value: $trimmedValue,
            reason: trim($reason),
            createdBy: $createdBy,
            expiresAt: $expiresAt,
            isActive: true,
            createdAt: $now
        );
    }

    public function getEntry(int $id): ?FraudListEntry
    {
        $this->ensureTables();

        $row = $this->db->selectOne("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1", ['id' => $id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @return list<FraudListEntry>
     */
    public function findMatchingEntries(FraudListEntryType $entryType, string $candidateValue): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->table} WHERE entry_type = :entry_type AND is_active = 1",
            ['entry_type' => $entryType->value]
        );

        $matched = [];
        foreach ($rows as $row) {
            $entry = $this->hydrate($row);
            if ($entry->matches($candidateValue)) {
                $matched[] = $entry;
            }
        }

        return $matched;
    }

    /**
     * Determines highest priority list match: DENY > WATCH > ALLOW
     */
    public function checkValue(FraudListEntryType $entryType, string $candidateValue): ?FraudListType
    {
        $entries = $this->findMatchingEntries($entryType, $candidateValue);
        if (empty($entries)) {
            return null;
        }

        $hasDeny = false;
        $hasWatch = false;
        $hasAllow = false;

        foreach ($entries as $entry) {
            match ($entry->getListType()) {
                FraudListType::DENY => $hasDeny = true,
                FraudListType::WATCH => $hasWatch = true,
                FraudListType::ALLOW => $hasAllow = true,
            };
        }

        if ($hasDeny) {
            return FraudListType::DENY;
        }
        if ($hasWatch) {
            return FraudListType::WATCH;
        }
        if ($hasAllow) {
            return FraudListType::ALLOW;
        }

        return null;
    }

    /**
     * Evaluates all applicable dimensions in a RiskContext against active lists.
     *
     * @return array{deny: list<FraudListEntry>, watch: list<FraudListEntry>, allow: list<FraudListEntry>}
     */
    public function checkContext(RiskContext $context): array
    {
        $results = [
            'deny' => [],
            'watch' => [],
            'allow' => [],
        ];

        $candidates = [
            [FraudListEntryType::IP, $context->getClientIp()],
            [FraudListEntryType::EMAIL, $context->getEmail()],
            [FraudListEntryType::COUNTRY, $context->getBillingCountry()],
            [FraudListEntryType::COUNTRY, $context->getGeoIpCountry()],
            [FraudListEntryType::USER_ID, $context->getUserId() !== null ? (string) $context->getUserId() : null],
        ];

        foreach ($candidates as [$type, $val]) {
            if ($val !== null && trim($val) !== '') {
                $matched = $this->findMatchingEntries($type, $val);
                foreach ($matched as $entry) {
                    $results[$entry->getListType()->value][] = $entry;
                }
            }
        }

        return $results;
    }

    public function deactivateEntry(int $id): bool
    {
        $this->ensureTables();
        $this->db->statement("UPDATE {$this->table} SET is_active = 0 WHERE id = :id", ['id' => $id]);
        return true;
    }

    public function deleteEntry(int $id): bool
    {
        $this->ensureTables();
        $this->db->statement("DELETE FROM {$this->table} WHERE id = :id", ['id' => $id]);
        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FraudListEntry
    {
        return new FraudListEntry(
            id: (int) $row['id'],
            listType: FraudListType::from((string) $row['list_type']),
            entryType: FraudListEntryType::from((string) $row['entry_type']),
            value: (string) $row['value'],
            reason: (string) $row['reason'],
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            expiresAt: !empty($row['expires_at']) ? new DateTimeImmutable((string) $row['expires_at']) : null,
            isActive: (bool) $row['is_active'],
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null
        );
    }
}
