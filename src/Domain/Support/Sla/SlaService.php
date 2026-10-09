<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Sla;

use Coleza\Domain\Support\Tickets\TicketPriority;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Tickets\TicketStatus;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class SlaService
{
    private string $policiesTable = 'support_sla_policies';
    private string $slaTable = 'support_ticket_sla';

    public function __construct(
        private readonly Connection $db,
        private readonly TicketService $ticketService
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
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                department_id INT NULL,
                critical_first_response_minutes INT NOT NULL DEFAULT 60,
                critical_resolution_minutes INT NOT NULL DEFAULT 240,
                high_first_response_minutes INT NOT NULL DEFAULT 240,
                high_resolution_minutes INT NOT NULL DEFAULT 720,
                medium_first_response_minutes INT NOT NULL DEFAULT 720,
                medium_resolution_minutes INT NOT NULL DEFAULT 1440,
                low_first_response_minutes INT NOT NULL DEFAULT 1440,
                low_resolution_minutes INT NOT NULL DEFAULT 2880,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->policiesTable,
            $autoInc
        );
        $this->db->statement($sqlPolicies);

        $sqlSla = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                ticket_id INT NOT NULL UNIQUE,
                policy_id INT NULL,
                first_response_due_at TIMESTAMP NOT NULL,
                resolution_due_at TIMESTAMP NOT NULL,
                first_responded_at TIMESTAMP NULL,
                is_first_response_breached TINYINT(1) NOT NULL DEFAULT 0,
                resolved_at TIMESTAMP NULL,
                is_resolution_breached TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT \'active\',
                paused_at TIMESTAMP NULL,
                total_paused_seconds INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->slaTable,
            $autoInc
        );
        $this->db->statement($sqlSla);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPolicy(array $data): SlaPolicy
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(
                ['name' => 'SLA policy name is required.'],
                'Invalid policy name'
            );
        }

        $isDefault = (bool) ($data['is_default'] ?? false);
        $isActive = (bool) ($data['is_active'] ?? true);
        $departmentId = isset($data['department_id']) && $data['department_id'] !== null ? (int) $data['department_id'] : null;

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        if ($isDefault) {
            // Clear other defaults
            $this->db->update($this->policiesTable, ['is_default' => 0], '1 = 1');
        }

        $id = (int) $this->db->insert(
            $this->policiesTable,
            [
                'name' => $name,
                'description' => $data['description'] ?? null,
                'department_id' => $departmentId,
                'critical_first_response_minutes' => (int) ($data['critical_first_response_minutes'] ?? 60),
                'critical_resolution_minutes' => (int) ($data['critical_resolution_minutes'] ?? 240),
                'high_first_response_minutes' => (int) ($data['high_first_response_minutes'] ?? 240),
                'high_resolution_minutes' => (int) ($data['high_resolution_minutes'] ?? 720),
                'medium_first_response_minutes' => (int) ($data['medium_first_response_minutes'] ?? 720),
                'medium_resolution_minutes' => (int) ($data['medium_resolution_minutes'] ?? 1440),
                'low_first_response_minutes' => (int) ($data['low_first_response_minutes'] ?? 1440),
                'low_resolution_minutes' => (int) ($data['low_resolution_minutes'] ?? 2880),
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'metadata_json' => json_encode($data['metadata'] ?? []),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]
        );

        return new SlaPolicy(
            id: $id,
            name: $name,
            description: $data['description'] ?? null,
            departmentId: $departmentId,
            criticalFirstResponseMinutes: (int) ($data['critical_first_response_minutes'] ?? 60),
            criticalResolutionMinutes: (int) ($data['critical_resolution_minutes'] ?? 240),
            highFirstResponseMinutes: (int) ($data['high_first_response_minutes'] ?? 240),
            highResolutionMinutes: (int) ($data['high_resolution_minutes'] ?? 720),
            mediumFirstResponseMinutes: (int) ($data['medium_first_response_minutes'] ?? 720),
            mediumResolutionMinutes: (int) ($data['medium_resolution_minutes'] ?? 1440),
            lowFirstResponseMinutes: (int) ($data['low_first_response_minutes'] ?? 1440),
            lowResolutionMinutes: (int) ($data['low_resolution_minutes'] ?? 2880),
            isDefault: $isDefault,
            isActive: $isActive,
            metadata: $data['metadata'] ?? [],
            createdAt: $now,
            updatedAt: $now
        );
    }

    public function getPolicy(int $id): ?SlaPolicy
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->policiesTable),
            ['id' => $id]
        );

        return $row !== null ? SlaPolicy::fromArray($row) : null;
    }

    public function resolvePolicyForDepartment(?int $departmentId = null): SlaPolicy
    {
        if ($departmentId !== null) {
            $depRow = $this->db->selectOne(
                sprintf('SELECT * FROM %s WHERE department_id = :dep_id AND is_active = 1 LIMIT 1', $this->policiesTable),
                ['dep_id' => $departmentId]
            );
            if ($depRow !== null) {
                return SlaPolicy::fromArray($depRow);
            }
        }

        $defaultRow = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE is_default = 1 AND is_active = 1 LIMIT 1', $this->policiesTable)
        );

        if ($defaultRow !== null) {
            return SlaPolicy::fromArray($defaultRow);
        }

        // Built-in fallback
        return new SlaPolicy(
            id: 0,
            name: 'Standard Default SLA',
            isDefault: true,
            isActive: true
        );
    }

    /**
     * Initialize SLA timers for a newly created ticket.
     */
    public function initializeTicketSla(
        int $ticketId,
        ?int $policyId = null,
        ?DateTimeImmutable $startTime = null
    ): TicketSla {
        $ticket = $this->ticketService->requireTicket($ticketId);

        $existing = $this->getTicketSla($ticketId);
        if ($existing !== null) {
            return $existing;
        }

        $policy = $policyId !== null ? $this->getPolicy($policyId) : null;
        if ($policy === null) {
            $policy = $this->resolvePolicyForDepartment($ticket->getDepartmentId());
        }

        $start = $startTime ?? new DateTimeImmutable();
        $targets = $policy->getTargetsForPriority($ticket->getPriority());

        $firstResponseDueAt = $start->modify(sprintf('+%d minutes', $targets['first_response_minutes']));
        $resolutionDueAt = $start->modify(sprintf('+%d minutes', $targets['resolution_minutes']));

        $nowStr = $start->format('Y-m-d H:i:s');

        $insertedId = $this->db->insert(
            $this->slaTable,
            [
                'ticket_id' => $ticketId,
                'policy_id' => $policy->getId() > 0 ? $policy->getId() : null,
                'first_response_due_at' => $firstResponseDueAt->format('Y-m-d H:i:s'),
                'resolution_due_at' => $resolutionDueAt->format('Y-m-d H:i:s'),
                'first_responded_at' => null,
                'is_first_response_breached' => 0,
                'resolved_at' => null,
                'is_resolution_breached' => 0,
                'status' => TicketSla::STATUS_ACTIVE,
                'paused_at' => null,
                'total_paused_seconds' => 0,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]
        );

        return new TicketSla(
            id: (int) $insertedId,
            ticketId: $ticketId,
            policyId: $policy->getId() > 0 ? $policy->getId() : null,
            firstResponseDueAt: $firstResponseDueAt,
            resolutionDueAt: $resolutionDueAt,
            firstRespondedAt: null,
            isFirstResponseBreached: false,
            resolvedAt: null,
            isResolutionBreached: false,
            status: TicketSla::STATUS_ACTIVE,
            pausedAt: null,
            totalPausedSeconds: 0,
            createdAt: $start,
            updatedAt: $start
        );
    }

    public function recordFirstResponse(int $ticketId, ?DateTimeImmutable $responseAt = null): ?TicketSla
    {
        $sla = $this->getTicketSla($ticketId);
        if ($sla === null || $sla->getFirstRespondedAt() !== null) {
            return $sla;
        }

        $now = $responseAt ?? new DateTimeImmutable();
        $isBreached = $now > $sla->getFirstResponseDueAt();

        $this->db->update(
            $this->slaTable,
            [
                'first_responded_at' => $now->format('Y-m-d H:i:s'),
                'is_first_response_breached' => $isBreached ? 1 : 0,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
            'id = :where_id',
            ['where_id' => $sla->getId()]
        );

        return $this->getTicketSla($ticketId);
    }

    public function recordResolution(int $ticketId, ?DateTimeImmutable $resolvedAt = null): ?TicketSla
    {
        $sla = $this->getTicketSla($ticketId);
        if ($sla === null) {
            return null;
        }

        $now = $resolvedAt ?? new DateTimeImmutable();
        $isBreached = $now > $sla->getResolutionDueAt();
        $finalStatus = ($isBreached || $sla->isFirstResponseBreached()) ? TicketSla::STATUS_BREACHED : TicketSla::STATUS_MET;

        $this->db->update(
            $this->slaTable,
            [
                'resolved_at' => $now->format('Y-m-d H:i:s'),
                'is_resolution_breached' => $isBreached ? 1 : 0,
                'status' => $finalStatus,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
            'id = :where_id',
            ['where_id' => $sla->getId()]
        );

        return $this->getTicketSla($ticketId);
    }

    public function pauseSla(int $ticketId, ?DateTimeImmutable $pausedAt = null): ?TicketSla
    {
        $sla = $this->getTicketSla($ticketId);
        if ($sla === null || $sla->isPaused() || $sla->getStatus() !== TicketSla::STATUS_ACTIVE) {
            return $sla;
        }

        $now = $pausedAt ?? new DateTimeImmutable();

        $this->db->update(
            $this->slaTable,
            [
                'status' => TicketSla::STATUS_PAUSED,
                'paused_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
            'id = :where_id',
            ['where_id' => $sla->getId()]
        );

        return $this->getTicketSla($ticketId);
    }

    public function resumeSla(int $ticketId, ?DateTimeImmutable $resumedAt = null): ?TicketSla
    {
        $sla = $this->getTicketSla($ticketId);
        if ($sla === null || !$sla->isPaused() || $sla->getPausedAt() === null) {
            return $sla;
        }

        $now = $resumedAt ?? new DateTimeImmutable();
        $pausedSeconds = max(0, $now->getTimestamp() - $sla->getPausedAt()->getTimestamp());

        $firstResponseDueAt = $sla->getFirstResponseDueAt();
        if ($sla->getFirstRespondedAt() === null) {
            $firstResponseDueAt = $firstResponseDueAt->modify(sprintf('+%d seconds', $pausedSeconds));
        }

        $resolutionDueAt = $sla->getResolutionDueAt()->modify(sprintf('+%d seconds', $pausedSeconds));
        $totalPaused = $sla->getTotalPausedSeconds() + $pausedSeconds;

        $this->db->update(
            $this->slaTable,
            [
                'first_response_due_at' => $firstResponseDueAt->format('Y-m-d H:i:s'),
                'resolution_due_at' => $resolutionDueAt->format('Y-m-d H:i:s'),
                'status' => TicketSla::STATUS_ACTIVE,
                'paused_at' => null,
                'total_paused_seconds' => $totalPaused,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
            'id = :where_id',
            ['where_id' => $sla->getId()]
        );

        return $this->getTicketSla($ticketId);
    }

    /**
     * Scan active tickets for SLA target breaches and optionally auto-escalate priority.
     */
    public function evaluateBreaches(
        ?DateTimeImmutable $currentTime = null,
        bool $autoEscalatePriority = false
    ): SlaBreachResult {
        $now = $currentTime ?? new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        // Fetch all active SLAs
        $sql = sprintf(
            'SELECT * FROM %s WHERE status = :status',
            $this->slaTable
        );
        $rows = $this->db->select($sql, ['status' => TicketSla::STATUS_ACTIVE]);

        $firstResponseBreaches = [];
        $resolutionBreaches = [];
        $escalatedTickets = [];

        foreach ($rows as $row) {
            $sla = TicketSla::fromArray($row);
            $ticketId = $sla->getTicketId();
            $ticket = $this->ticketService->getTicket($ticketId);
            if ($ticket === null || $ticket->isClosed() || TicketStatus::isResolved($ticket->getStatus())) {
                continue;
            }

            $slaUpdated = false;
            $isFrBreached = $sla->isFirstResponseBreached();
            $isResBreached = $sla->isResolutionBreached();

            // 1. First response check
            if ($sla->getFirstRespondedAt() === null && !$isFrBreached) {
                if ($now > $sla->getFirstResponseDueAt()) {
                    $isFrBreached = true;
                    $firstResponseBreaches[] = $ticketId;
                    $slaUpdated = true;
                }
            }

            // 2. Resolution check
            if ($sla->getResolvedAt() === null && !$isResBreached) {
                if ($now > $sla->getResolutionDueAt()) {
                    $isResBreached = true;
                    $resolutionBreaches[] = $ticketId;
                    $slaUpdated = true;
                }
            }

            if ($slaUpdated) {
                $this->db->update(
                    $this->slaTable,
                    [
                        'is_first_response_breached' => $isFrBreached ? 1 : 0,
                        'is_resolution_breached' => $isResBreached ? 1 : 0,
                        'status' => TicketSla::STATUS_BREACHED,
                        'updated_at' => $nowStr,
                    ],
                    'id = :where_id',
                    ['where_id' => $sla->getId()]
                );

                // Auto escalation if enabled
                if ($autoEscalatePriority) {
                    $currPriority = $ticket->getPriority();
                    $nextPriority = match ($currPriority) {
                        TicketPriority::LOW => TicketPriority::MEDIUM,
                        TicketPriority::MEDIUM => TicketPriority::HIGH,
                        TicketPriority::HIGH => TicketPriority::CRITICAL,
                        default => null,
                    };

                    if ($nextPriority !== null) {
                        $this->ticketService->updatePriority($ticketId, $nextPriority);
                        $escalatedTickets[] = $ticketId;
                    }
                }
            }
        }

        return new SlaBreachResult(
            evaluatedTicketsCount: count($rows),
            firstResponseBreaches: $firstResponseBreaches,
            resolutionBreaches: $resolutionBreaches,
            escalatedTickets: $escalatedTickets
        );
    }

    public function getTicketSla(int $ticketId): ?TicketSla
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE ticket_id = :ticket_id', $this->slaTable),
            ['ticket_id' => $ticketId]
        );

        return $row !== null ? TicketSla::fromArray($row) : null;
    }
}
