<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Assignments;

use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class TicketAssignmentService
{
    public const STRATEGY_MANUAL = 'manual';
    public const STRATEGY_ROUND_ROBIN = 'round_robin';
    public const STRATEGY_LEAST_LOADED = 'least_loaded';
    public const STRATEGY_UNASSIGN = 'unassign';

    private string $logsTable = 'support_ticket_assignments_log';
    private string $stateTable = 'support_department_assignment_state';
    private string $ticketsTable = 'support_tickets';

    public function __construct(
        private readonly Connection $db,
        private readonly DepartmentService $departmentService,
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

        $sqlLogs = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                ticket_id INT NOT NULL,
                previous_assigned_to INT NULL,
                new_assigned_to INT NULL,
                assigned_by_user_id INT NULL,
                strategy VARCHAR(32) NOT NULL,
                reason VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->logsTable,
            $autoInc
        );
        $this->db->statement($sqlLogs);

        $sqlState = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                department_id INT PRIMARY KEY,
                last_assigned_user_id INT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->stateTable
        );
        $this->db->statement($sqlState);
    }

    /**
     * Manually assign a ticket to a designated department agent.
     */
    public function assignManual(
        int $ticketId,
        int $agentUserId,
        ?int $actorUserId = null,
        ?string $reason = null
    ): TicketAssignmentLog {
        $ticket = $this->ticketService->requireTicket($ticketId);

        if ($agentUserId <= 0) {
            throw new ValidationException(
                ['agent_user_id' => 'Valid agent user ID is required for assignment.'],
                'Invalid agent ID'
            );
        }

        if (!$this->departmentService->isAgentInDepartment($ticket->getDepartmentId(), $agentUserId)) {
            throw new ValidationException(
                ['agent_user_id' => "Agent {$agentUserId} is not an active member of department {$ticket->getDepartmentId()}."],
                'Agent not in department'
            );
        }

        $previousAssignedTo = $ticket->getAssignedTo();
        if ($previousAssignedTo === $agentUserId) {
            // Already assigned to this agent
            $history = $this->getAssignmentHistory($ticketId);
            if (count($history) > 0) {
                return end($history);
            }
        }

        $this->ticketService->assignTicket($ticketId, $agentUserId);

        return $this->recordLog(
            ticketId: $ticketId,
            previousAssignedTo: $previousAssignedTo,
            newAssignedTo: $agentUserId,
            assignedByUserId: $actorUserId,
            strategy: self::STRATEGY_MANUAL,
            reason: $reason ?? 'Manual assignment'
        );
    }

    /**
     * Unassign a ticket.
     */
    public function unassign(
        int $ticketId,
        ?int $actorUserId = null,
        ?string $reason = null
    ): TicketAssignmentLog {
        $ticket = $this->ticketService->requireTicket($ticketId);
        $previousAssignedTo = $ticket->getAssignedTo();

        $this->ticketService->assignTicket($ticketId, null);

        return $this->recordLog(
            ticketId: $ticketId,
            previousAssignedTo: $previousAssignedTo,
            newAssignedTo: null,
            assignedByUserId: $actorUserId,
            strategy: self::STRATEGY_UNASSIGN,
            reason: $reason ?? 'Ticket unassigned'
        );
    }

    /**
     * Automatically assign a ticket using round-robin rotation among active assignable agents.
     */
    public function assignRoundRobin(
        int $ticketId,
        ?int $actorUserId = null,
        ?string $reason = null
    ): ?TicketAssignmentLog {
        $ticket = $this->ticketService->requireTicket($ticketId);
        $departmentId = $ticket->getDepartmentId();

        $assignableAgents = $this->departmentService->getAssignableAgents($departmentId);
        if (count($assignableAgents) === 0) {
            // No assignable agents currently in department
            return null;
        }

        // Sort by user ID ascending for stable cyclic order
        usort($assignableAgents, fn ($a, $b) => $a->getUserId() <=> $b->getUserId());
        $agentUserIds = array_map(fn ($a) => $a->getUserId(), $assignableAgents);

        // Fetch last assigned agent for this department
        $stateRow = $this->db->selectOne(
            sprintf('SELECT last_assigned_user_id FROM %s WHERE department_id = :dep_id', $this->stateTable),
            ['dep_id' => $departmentId]
        );

        $lastAssigned = $stateRow !== null && $stateRow['last_assigned_user_id'] !== null
            ? (int) $stateRow['last_assigned_user_id']
            : null;

        $targetIndex = 0;
        if ($lastAssigned !== null) {
            $foundIndex = array_search($lastAssigned, $agentUserIds, true);
            if ($foundIndex !== false) {
                $targetIndex = ($foundIndex + 1) % count($agentUserIds);
            }
        }

        $chosenUserId = $agentUserIds[$targetIndex];
        $previousAssignedTo = $ticket->getAssignedTo();

        // Update department state
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        if ($stateRow !== null) {
            $this->db->update(
                $this->stateTable,
                [
                    'last_assigned_user_id' => $chosenUserId,
                    'updated_at' => $now,
                ],
                'department_id = :where_dep',
                ['where_dep' => $departmentId]
            );
        } else {
            $this->db->insert(
                $this->stateTable,
                [
                    'department_id' => $departmentId,
                    'last_assigned_user_id' => $chosenUserId,
                    'updated_at' => $now,
                ]
            );
        }

        $this->ticketService->assignTicket($ticketId, $chosenUserId);

        return $this->recordLog(
            ticketId: $ticketId,
            previousAssignedTo: $previousAssignedTo,
            newAssignedTo: $chosenUserId,
            assignedByUserId: $actorUserId,
            strategy: self::STRATEGY_ROUND_ROBIN,
            reason: $reason ?? 'Round-robin automated assignment'
        );
    }

    /**
     * Automatically assign a ticket to the agent with the lowest number of active tickets.
     */
    public function assignLeastLoaded(
        int $ticketId,
        ?int $actorUserId = null,
        ?string $reason = null
    ): ?TicketAssignmentLog {
        $ticket = $this->ticketService->requireTicket($ticketId);
        $departmentId = $ticket->getDepartmentId();

        $assignableAgents = $this->departmentService->getAssignableAgents($departmentId);
        if (count($assignableAgents) === 0) {
            return null;
        }

        $agentUserIds = array_map(fn ($a) => $a->getUserId(), $assignableAgents);

        // Calculate load for each assignable agent in this department
        $placeholders = [];
        $bindings = ['dep_id' => $departmentId];
        foreach ($agentUserIds as $idx => $uid) {
            $paramKey = 'agent_' . $idx;
            $placeholders[] = ':' . $paramKey;
            $bindings[$paramKey] = $uid;
        }

        $sql = sprintf(
            'SELECT assigned_to, COUNT(*) as active_count
             FROM %s
             WHERE department_id = :dep_id
               AND assigned_to IN (%s)
               AND status NOT IN (\'resolved\', \'closed\')
             GROUP BY assigned_to',
            $this->ticketsTable,
            implode(', ', $placeholders)
        );

        $counts = $this->db->select($sql, $bindings);
        $loadMap = [];
        foreach ($agentUserIds as $uid) {
            $loadMap[$uid] = 0;
        }
        foreach ($counts as $r) {
            if ($r['assigned_to'] !== null) {
                $loadMap[(int) $r['assigned_to']] = (int) $r['active_count'];
            }
        }

        // Find minimum load
        asort($loadMap); // preserves keys, sorts ascending by count
        $chosenUserId = (int) array_key_first($loadMap);
        $previousAssignedTo = $ticket->getAssignedTo();

        $this->ticketService->assignTicket($ticketId, $chosenUserId);

        return $this->recordLog(
            ticketId: $ticketId,
            previousAssignedTo: $previousAssignedTo,
            newAssignedTo: $chosenUserId,
            assignedByUserId: $actorUserId,
            strategy: self::STRATEGY_LEAST_LOADED,
            reason: $reason ?? sprintf('Least-loaded assignment (load: %d)', $loadMap[$chosenUserId])
        );
    }

    /**
     * Automatically dispatch assignment based on policy.
     */
    public function autoAssign(
        int $ticketId,
        string $strategy = self::STRATEGY_ROUND_ROBIN,
        ?int $actorUserId = null
    ): ?TicketAssignmentLog {
        return match ($strategy) {
            self::STRATEGY_LEAST_LOADED => $this->assignLeastLoaded($ticketId, $actorUserId),
            default => $this->assignRoundRobin($ticketId, $actorUserId),
        };
    }

    /**
     * @return array<int, TicketAssignmentLog>
     */
    public function getAssignmentHistory(int $ticketId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE ticket_id = :ticket_id ORDER BY id ASC', $this->logsTable),
            ['ticket_id' => $ticketId]
        );

        return array_map(fn (array $r) => TicketAssignmentLog::fromArray($r), $rows);
    }

    private function recordLog(
        int $ticketId,
        ?int $previousAssignedTo,
        ?int $newAssignedTo,
        ?int $assignedByUserId,
        string $strategy,
        ?string $reason = null
    ): TicketAssignmentLog {
        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $insertedId = $this->db->insert(
            $this->logsTable,
            [
                'ticket_id' => $ticketId,
                'previous_assigned_to' => $previousAssignedTo,
                'new_assigned_to' => $newAssignedTo,
                'assigned_by_user_id' => $assignedByUserId,
                'strategy' => $strategy,
                'reason' => $reason,
                'created_at' => $nowStr,
            ]
        );

        $id = (int) $insertedId;

        return new TicketAssignmentLog(
            id: $id,
            ticketId: $ticketId,
            previousAssignedTo: $previousAssignedTo,
            newAssignedTo: $newAssignedTo,
            assignedByUserId: $assignedByUserId,
            strategy: $strategy,
            reason: $reason,
            createdAt: $now
        );
    }
}
