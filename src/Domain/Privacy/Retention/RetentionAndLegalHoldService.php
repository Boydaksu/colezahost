<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Retention;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class RetentionAndLegalHoldService
{
    private string $policiesTable = 'privacy_retention_policies';
    private string $holdsTable = 'privacy_legal_holds';

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

        $sqlPolicies = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                policy_key VARCHAR(50) NOT NULL UNIQUE,
                category VARCHAR(50) NOT NULL,
                retention_days INT NOT NULL,
                action_on_expiry VARCHAR(30) NOT NULL DEFAULT \'anonymize\',
                is_statutory TINYINT(1) NOT NULL DEFAULT 0,
                description TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->policiesTable,
            $autoInc
        );
        $this->db->statement($sqlPolicies);

        $sqlHolds = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                hold_reference VARCHAR(50) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                reason TEXT NOT NULL,
                scope_type VARCHAR(30) NOT NULL,
                scope_id INT NULL,
                scope_identifier VARCHAR(255) NULL,
                issued_by_authority VARCHAR(150) NOT NULL,
                created_by_staff_id INT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                released_at TIMESTAMP NULL,
                released_by_staff_id INT NULL,
                release_notes TEXT NULL,
                metadata_json TEXT NULL
            )',
            $this->holdsTable,
            $autoInc
        );
        $this->db->statement($sqlHolds);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_privacy_hold_active ON {$this->holdsTable} (is_active, scope_type, scope_id)");
            } catch (\Throwable) {
                // Ignore if indices exist
            }
        }

        $this->seedDefaultPolicies();
    }

    public function seedDefaultPolicies(): void
    {
        $defaults = [
            [
                'policy_key' => 'financial_documents',
                'category' => 'finance',
                'retention_days' => 2555, // ~7 years
                'action_on_expiry' => 'archive',
                'is_statutory' => 1,
                'description' => 'Invoices, payment transactions, and fiscal receipts preserved for 7 years per statutory tax and commercial accounting law.',
            ],
            [
                'policy_key' => 'audit_logs',
                'category' => 'audit',
                'retention_days' => 365,
                'action_on_expiry' => 'anonymize',
                'is_statutory' => 1,
                'description' => 'Platform administrative and security audit events retained for 1 year.',
            ],
            [
                'policy_key' => 'support_tickets',
                'category' => 'support',
                'retention_days' => 730,
                'action_on_expiry' => 'anonymize',
                'is_statutory' => 0,
                'description' => 'Closed customer support tickets and correspondence retained for 2 years.',
            ],
            [
                'policy_key' => 'security_events',
                'category' => 'security',
                'retention_days' => 180,
                'action_on_expiry' => 'anonymize',
                'is_statutory' => 0,
                'description' => 'Fraud signals, rate limit bursts, and login failures retained for 180 days.',
            ],
            [
                'policy_key' => 'ephemeral_sessions',
                'category' => 'sessions',
                'retention_days' => 30,
                'action_on_expiry' => 'delete',
                'is_statutory' => 0,
                'description' => 'Expired login sessions and temporary verification tokens purged after 30 days.',
            ],
        ];

        foreach ($defaults as $policy) {
            $existing = $this->db->selectOne(
                "SELECT id FROM {$this->policiesTable} WHERE policy_key = :key LIMIT 1",
                ['key' => $policy['policy_key']]
            );

            if ($existing === null) {
                $this->db->insert($this->policiesTable, $policy);
            }
        }
    }

    public function getPolicy(string $policyKey): ?RetentionPolicy
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            "SELECT * FROM {$this->policiesTable} WHERE policy_key = :key LIMIT 1",
            ['key' => trim($policyKey)]
        );

        return $row !== null ? $this->hydratePolicy($row) : null;
    }

    /**
     * @return array{policy: RetentionPolicy, is_expired: bool, expires_at: DateTimeImmutable}
     */
    public function evaluateRetention(
        string $policyKey,
        DateTimeImmutable $recordDate,
        ?DateTimeImmutable $now = null
    ): array {
        $policy = $this->getPolicy($policyKey);
        if ($policy === null) {
            throw new ValidationException(['policy_key' => [sprintf('Retention policy "%s" not found.', $policyKey)]], sprintf('Retention policy "%s" not found.', $policyKey));
        }

        $expiresAt = $policy->getExpirationDate($recordDate);
        $isExpired = $policy->isExpired($recordDate, $now);

        return [
            'policy' => $policy,
            'is_expired' => $isExpired,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function placeLegalHold(
        string $holdReference,
        string $title,
        string $reason,
        LegalHoldScopeType $scopeType,
        string $issuedByAuthority,
        int $createdByStaffId,
        ?int $scopeId = null,
        ?string $scopeIdentifier = null,
        array $metadata = []
    ): LegalHold {
        $this->ensureTables();

        $trimmedRef = trim($holdReference);
        if ($trimmedRef === '') {
            throw new ValidationException(['hold_reference' => ['Legal hold reference cannot be empty.']], 'Legal hold reference cannot be empty.');
        }

        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new ValidationException(['title' => ['Legal hold title cannot be empty.']], 'Legal hold title cannot be empty.');
        }

        $now = new DateTimeImmutable();
        $data = [
            'hold_reference' => $trimmedRef,
            'title' => $trimmedTitle,
            'reason' => trim($reason),
            'scope_type' => $scopeType->value,
            'scope_id' => $scopeId,
            'scope_identifier' => $scopeIdentifier !== null ? trim($scopeIdentifier) : null,
            'issued_by_authority' => trim($issuedByAuthority),
            'created_by_staff_id' => $createdByStaffId,
            'is_active' => 1,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'released_at' => null,
            'released_by_staff_id' => null,
            'release_notes' => null,
            'metadata_json' => !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ];

        $id = (int) $this->db->insert($this->holdsTable, $data);

        return new LegalHold(
            id: $id,
            holdReference: $trimmedRef,
            title: $trimmedTitle,
            reason: trim($reason),
            scopeType: $scopeType,
            scopeId: $scopeId,
            scopeIdentifier: $scopeIdentifier,
            issuedByAuthority: trim($issuedByAuthority),
            createdByStaffId: $createdByStaffId,
            isActive: true,
            createdAt: $now,
            releasedAt: null,
            releasedByStaffId: null,
            releaseNotes: null,
            metadata: $metadata
        );
    }

    public function releaseLegalHold(int $holdId, int $staffId, string $releaseNotes): LegalHold
    {
        $this->ensureTables();

        $trimmedNotes = trim($releaseNotes);
        if ($trimmedNotes === '') {
            throw new ValidationException(['release_notes' => ['Legal hold release justification notes are required.']], 'Legal hold release justification notes are required.');
        }

        $hold = $this->getHoldOrFail($holdId);
        if (!$hold->isActive()) {
            throw new ValidationException(['hold' => [sprintf('Legal hold #%d is already released.', $holdId)]], sprintf('Legal hold #%d is already released.', $holdId));
        }

        $now = new DateTimeImmutable();
        $this->db->statement(
            "UPDATE {$this->holdsTable}
             SET is_active = 0, released_at = :at, released_by_staff_id = :by, release_notes = :notes
             WHERE id = :id",
            [
                'at' => $now->format('Y-m-d H:i:s'),
                'by' => $staffId,
                'notes' => $trimmedNotes,
                'id' => $holdId,
            ]
        );

        return $this->getHoldOrFail($holdId);
    }

    public function getHold(int $holdId): ?LegalHold
    {
        $this->ensureTables();

        $row = $this->db->selectOne("SELECT * FROM {$this->holdsTable} WHERE id = :id LIMIT 1", ['id' => $holdId]);
        return $row !== null ? $this->hydrateHold($row) : null;
    }

    public function getHoldOrFail(int $holdId): LegalHold
    {
        $hold = $this->getHold($holdId);
        if ($hold === null) {
            throw new ValidationException(['hold' => [sprintf('Legal hold #%d not found.', $holdId)]], sprintf('Legal hold #%d not found.', $holdId));
        }
        return $hold;
    }

    /**
     * @return list<LegalHold>
     */
    public function getActiveLegalHolds(): array
    {
        $this->ensureTables();

        $rows = $this->db->select("SELECT * FROM {$this->holdsTable} WHERE is_active = 1 ORDER BY id DESC");
        return array_map(fn (array $r) => $this->hydrateHold($r), $rows);
    }

    /**
     * @return list<LegalHold>
     */
    public function getActiveHoldsForUser(int $userId): array
    {
        $all = $this->getActiveLegalHolds();
        return array_values(array_filter($all, fn (LegalHold $h) => $h->matchesUser($userId)));
    }

    public function isSubjectUnderLegalHold(int $userId, ?int $orgId = null, ?string $resourceIdentifier = null): bool
    {
        $activeHolds = $this->getActiveLegalHolds();

        foreach ($activeHolds as $hold) {
            if ($hold->matchesUser($userId)) {
                return true;
            }
            if ($orgId !== null && $hold->matchesOrganization($orgId)) {
                return true;
            }
            if ($resourceIdentifier !== null && $hold->matchesResource($resourceIdentifier)) {
                return true;
            }
        }

        return false;
    }

    public function assertCanPurgeOrErase(int $userId, ?int $orgId = null): void
    {
        $activeHolds = $this->getActiveHoldsForUser($userId);
        if (!empty($activeHolds)) {
            $first = $activeHolds[0];
            throw new ValidationException(
                ['legal_hold' => [sprintf(
                    'Subject user #%d is subject to active Legal Hold "%s" (Authority: %s). Data destruction or erasure strictly prohibited.',
                    $userId,
                    $first->getHoldReference(),
                    $first->getIssuedByAuthority()
                )]],
                sprintf('Data destruction or erasure strictly prohibited: subject user #%d is subject to active Legal Hold "%s" (%s).', $userId, $first->getHoldReference(), $first->getIssuedByAuthority())
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePolicy(array $row): RetentionPolicy
    {
        return new RetentionPolicy(
            policyKey: (string) $row['policy_key'],
            category: (string) $row['category'],
            retentionDays: (int) $row['retention_days'],
            actionOnExpiry: (string) $row['action_on_expiry'],
            isStatutory: (bool) $row['is_statutory'],
            description: (string) $row['description']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateHold(array $row): LegalHold
    {
        $meta = !empty($row['metadata_json'])
            ? json_decode((string) $row['metadata_json'], true) ?? []
            : [];

        return new LegalHold(
            id: (int) $row['id'],
            holdReference: (string) $row['hold_reference'],
            title: (string) $row['title'],
            reason: (string) $row['reason'],
            scopeType: LegalHoldScopeType::from((string) $row['scope_type']),
            scopeId: isset($row['scope_id']) ? (int) $row['scope_id'] : null,
            scopeIdentifier: isset($row['scope_identifier']) ? (string) $row['scope_identifier'] : null,
            issuedByAuthority: (string) $row['issued_by_authority'],
            createdByStaffId: (int) $row['created_by_staff_id'],
            isActive: (bool) $row['is_active'],
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            releasedAt: !empty($row['released_at']) ? new DateTimeImmutable((string) $row['released_at']) : null,
            releasedByStaffId: isset($row['released_by_staff_id']) ? (int) $row['released_by_staff_id'] : null,
            releaseNotes: isset($row['release_notes']) ? (string) $row['release_notes'] : null,
            metadata: $meta
        );
    }
}
