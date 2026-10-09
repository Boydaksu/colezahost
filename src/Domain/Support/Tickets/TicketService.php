<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Tickets;

use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class TicketService
{
    private string $ticketsTable = 'support_tickets';
    private string $messagesTable = 'support_ticket_messages';

    public function __construct(
        private readonly Connection $db,
        private readonly DepartmentService $departmentService
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlTickets = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                ticket_number VARCHAR(32) NOT NULL UNIQUE,
                organization_id INT NULL,
                user_id INT NOT NULL,
                department_id INT NOT NULL,
                assigned_to INT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'open\',
                priority VARCHAR(32) NOT NULL DEFAULT \'medium\',
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
            $this->ticketsTable,
            $autoInc
        );
        $this->db->statement($sqlTickets);

        try {
            $this->db->statement(sprintf('ALTER TABLE %s ADD COLUMN invoice_id INT NULL', $this->ticketsTable));
        } catch (\Throwable) {
        }

        try {
            $this->db->statement(sprintf('ALTER TABLE %s ADD COLUMN order_id INT NULL', $this->ticketsTable));
        } catch (\Throwable) {
        }

        $sqlMessages = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
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
            $this->messagesTable,
            $autoInc
        );
        $this->db->statement($sqlMessages);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createTicket(array $data): Ticket
    {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(
                ['user_id' => 'Valid creator user ID is required.'],
                'Invalid ticket author'
            );
        }

        $departmentId = (int) ($data['department_id'] ?? 0);
        $department = $this->departmentService->requireDepartment($departmentId);
        if (!$department->isActive()) {
            throw new ValidationException(
                ['department_id' => "Cannot create ticket in inactive department '{$department->getName()}'."],
                'Department inactive'
            );
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            throw new ValidationException(
                ['subject' => 'Ticket subject is required and cannot be empty.'],
                'Invalid ticket subject'
            );
        }

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            throw new ValidationException(
                ['message' => 'Ticket initial message is required and cannot be empty.'],
                'Invalid ticket message'
            );
        }

        $priority = (string) ($data['priority'] ?? TicketPriority::MEDIUM);
        TicketPriority::assertValid($priority);

        $status = (string) ($data['status'] ?? TicketStatus::OPEN);
        if (!TicketStatus::isValid($status)) {
            throw new ValidationException(
                ['status' => "Invalid initial ticket status: '{$status}'."],
                'Invalid ticket status'
            );
        }

        $organizationId = isset($data['organization_id']) && $data['organization_id'] !== null
            ? (int) $data['organization_id']
            : null;

        $serviceId = isset($data['service_id']) && $data['service_id'] !== null
            ? (int) $data['service_id']
            : null;

        $domainId = isset($data['domain_id']) && $data['domain_id'] !== null
            ? (int) $data['domain_id']
            : null;

        $invoiceId = isset($data['invoice_id']) && $data['invoice_id'] !== null
            ? (int) $data['invoice_id']
            : null;

        $orderId = isset($data['order_id']) && $data['order_id'] !== null
            ? (int) $data['order_id']
            : null;

        $assignedTo = isset($data['assigned_to']) && $data['assigned_to'] !== null
            ? (int) $data['assigned_to']
            : null;

        if ($assignedTo !== null && !$this->departmentService->isAgentInDepartment($departmentId, $assignedTo)) {
            throw new ValidationException(
                ['assigned_to' => "Assigned agent {$assignedTo} does not belong to department {$departmentId}."],
                'Invalid assigned agent'
            );
        }

        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        $ticketNumber = $this->generateUniqueTicketNumber();

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use (
            $ticketNumber,
            $organizationId,
            $userId,
            $departmentId,
            $assignedTo,
            $status,
            $priority,
            $subject,
            $serviceId,
            $domainId,
            $invoiceId,
            $orderId,
            $message,
            $metadata,
            $now,
            $nowStr
        ): Ticket {
            $ticketId = (int) $this->db->insert(
                $this->ticketsTable,
                [
                    'ticket_number' => $ticketNumber,
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'department_id' => $departmentId,
                    'assigned_to' => $assignedTo,
                    'status' => $status,
                    'priority' => $priority,
                    'subject' => $subject,
                    'service_id' => $serviceId,
                    'domain_id' => $domainId,
                    'invoice_id' => $invoiceId,
                    'order_id' => $orderId,
                    'last_reply_at' => $nowStr,
                    'last_reply_user_id' => $userId,
                    'last_reply_by_staff' => 0,
                    'closed_at' => null,
                    'metadata_json' => json_encode($metadata),
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]
            );

            // Create initial conversation message
            $this->db->insert(
                $this->messagesTable,
                [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'is_staff' => 0,
                    'is_internal_note' => 0,
                    'message' => $message,
                    'metadata_json' => json_encode([]),
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]
            );

            return new Ticket(
                id: $ticketId,
                ticketNumber: $ticketNumber,
                userId: $userId,
                departmentId: $departmentId,
                subject: $subject,
                status: $status,
                priority: $priority,
                organizationId: $organizationId,
                assignedTo: $assignedTo,
                serviceId: $serviceId,
                domainId: $domainId,
                invoiceId: $invoiceId,
                orderId: $orderId,
                lastReplyAt: $now,
                lastReplyUserId: $userId,
                lastReplyByStaff: false,
                closedAt: null,
                metadata: $metadata,
                createdAt: $now,
                updatedAt: $now
            );
        });
    }

    /**
     * Add a public reply (customer reply or staff answer).
     *
     * @param array<string, mixed> $metadata
     */
    public function addReply(
        int $ticketId,
        int $userId,
        string $message,
        bool $isStaff = false,
        ?string $newStatus = null,
        array $metadata = []
    ): TicketMessage {
        $ticket = $this->requireTicket($ticketId);

        $trimmedMessage = trim($message);
        if ($trimmedMessage === '') {
            throw new ValidationException(
                ['message' => 'Reply message cannot be empty.'],
                'Invalid reply message'
            );
        }

        if ($userId <= 0) {
            throw new ValidationException(
                ['user_id' => 'Valid reply user ID is required.'],
                'Invalid reply author'
            );
        }

        $currentStatus = $ticket->getStatus();
        $targetStatus = $currentStatus;
        $closedAt = $ticket->getClosedAt();

        if (!$isStaff) {
            // Customer replied
            if ($ticket->isClosed() || TicketStatus::isResolved($currentStatus)) {
                // Reopen ticket
                $targetStatus = TicketStatus::CUSTOMER_REPLY;
                $closedAt = null;
            } else {
                $targetStatus = TicketStatus::CUSTOMER_REPLY;
            }
        } else {
            // Staff replied
            if ($newStatus !== null) {
                TicketStatus::assertValidTransition($currentStatus, $newStatus);
                $targetStatus = $newStatus;
            } else {
                $targetStatus = TicketStatus::ANSWERED;
            }

            if ($targetStatus === TicketStatus::CLOSED) {
                $closedAt = new DateTimeImmutable();
            } elseif ($ticket->isClosed() && $targetStatus !== TicketStatus::CLOSED) {
                $closedAt = null;
            }
        }

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use (
            $ticketId,
            $userId,
            $trimmedMessage,
            $isStaff,
            $targetStatus,
            $closedAt,
            $metadata,
            $now,
            $nowStr
        ): TicketMessage {
            $msgId = (int) $this->db->insert(
                $this->messagesTable,
                [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'is_staff' => $isStaff ? 1 : 0,
                    'is_internal_note' => 0,
                    'message' => $trimmedMessage,
                    'metadata_json' => json_encode($metadata),
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]
            );

            $this->db->update(
                $this->ticketsTable,
                [
                    'status' => $targetStatus,
                    'last_reply_at' => $nowStr,
                    'last_reply_user_id' => $userId,
                    'last_reply_by_staff' => $isStaff ? 1 : 0,
                    'closed_at' => $closedAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $nowStr,
                ],
                'id = :where_id',
                ['where_id' => $ticketId]
            );

            return new TicketMessage(
                id: $msgId,
                ticketId: $ticketId,
                userId: $userId,
                message: $trimmedMessage,
                isStaff: $isStaff,
                isInternalNote: false,
                metadata: $metadata,
                createdAt: $now,
                updatedAt: $now
            );
        });
    }

    /**
     * Add a private staff internal note (invisible to customer).
     *
     * @param array<string, mixed> $metadata
     */
    public function addInternalNote(
        int $ticketId,
        int $staffUserId,
        string $message,
        array $metadata = []
    ): TicketMessage {
        $this->requireTicket($ticketId);

        $trimmedMessage = trim($message);
        if ($trimmedMessage === '') {
            throw new ValidationException(
                ['message' => 'Internal note message cannot be empty.'],
                'Invalid internal note message'
            );
        }

        if ($staffUserId <= 0) {
            throw new ValidationException(
                ['user_id' => 'Valid staff user ID is required.'],
                'Invalid staff author'
            );
        }

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use (
            $ticketId,
            $staffUserId,
            $trimmedMessage,
            $metadata,
            $now,
            $nowStr
        ): TicketMessage {
            $msgId = (int) $this->db->insert(
                $this->messagesTable,
                [
                    'ticket_id' => $ticketId,
                    'user_id' => $staffUserId,
                    'is_staff' => 1,
                    'is_internal_note' => 1,
                    'message' => $trimmedMessage,
                    'metadata_json' => json_encode($metadata),
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]
            );

            // Update ticket updated_at without touching customer-facing last_reply_at or status
            $this->db->update(
                $this->ticketsTable,
                ['updated_at' => $nowStr],
                'id = :where_id',
                ['where_id' => $ticketId]
            );

            return new TicketMessage(
                id: $msgId,
                ticketId: $ticketId,
                userId: $staffUserId,
                message: $trimmedMessage,
                isStaff: true,
                isInternalNote: true,
                metadata: $metadata,
                createdAt: $now,
                updatedAt: $now
            );
        });
    }

    /**
     * Retrieve the ticket conversation stream.
     *
     * @return array<int, TicketMessage>
     */
    public function getConversation(int $ticketId, bool $includeInternalNotes = false): array
    {
        $this->requireTicket($ticketId);

        $sql = sprintf(
            'SELECT * FROM %s WHERE ticket_id = :ticket_id %s ORDER BY id ASC',
            $this->messagesTable,
            $includeInternalNotes ? '' : 'AND is_internal_note = 0'
        );

        $rows = $this->db->select($sql, ['ticket_id' => $ticketId]);

        return array_map(fn (array $r) => TicketMessage::fromArray($r), $rows);
    }

    public function getTicket(int $id): ?Ticket
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->ticketsTable),
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        return Ticket::fromArray($row);
    }

    public function getTicketByNumber(string $ticketNumber): ?Ticket
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE ticket_number = :tn', $this->ticketsTable),
            ['tn' => trim($ticketNumber)]
        );

        if ($row === null) {
            return null;
        }

        return Ticket::fromArray($row);
    }

    public function requireTicket(int $id): Ticket
    {
        $ticket = $this->getTicket($id);
        if ($ticket === null) {
            throw new ValidationException(
                ['ticket_id' => "Ticket with ID {$id} not found."],
                'Ticket not found'
            );
        }

        return $ticket;
    }

    public function updateStatus(int $ticketId, string $newStatus, ?int $actorUserId = null): Ticket
    {
        $ticket = $this->requireTicket($ticketId);

        TicketStatus::assertValidTransition($ticket->getStatus(), $newStatus);

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $closedAt = $ticket->getClosedAt();
        if ($newStatus === TicketStatus::CLOSED) {
            $closedAt = $now;
        } elseif ($ticket->isClosed() && $newStatus !== TicketStatus::CLOSED) {
            $closedAt = null;
        }

        $this->db->update(
            $this->ticketsTable,
            [
                'status' => $newStatus,
                'closed_at' => $closedAt?->format('Y-m-d H:i:s'),
                'updated_at' => $nowStr,
            ],
            'id = :where_id',
            ['where_id' => $ticketId]
        );

        return $this->requireTicket($ticketId);
    }

    public function updatePriority(int $ticketId, string $newPriority, ?int $actorUserId = null): Ticket
    {
        $this->requireTicket($ticketId);

        TicketPriority::assertValid($newPriority);

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->db->update(
            $this->ticketsTable,
            [
                'priority' => $newPriority,
                'updated_at' => $nowStr,
            ],
            'id = :where_id',
            ['where_id' => $ticketId]
        );

        return $this->requireTicket($ticketId);
    }

    public function assignTicket(int $ticketId, ?int $agentUserId): Ticket
    {
        $ticket = $this->requireTicket($ticketId);

        if ($agentUserId !== null && $agentUserId > 0) {
            if (!$this->departmentService->isAgentInDepartment($ticket->getDepartmentId(), $agentUserId)) {
                throw new ValidationException(
                    ['assigned_to' => "Agent {$agentUserId} is not active in department {$ticket->getDepartmentId()}."],
                    'Invalid agent assignment'
                );
            }
        } else {
            $agentUserId = null;
        }

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->db->update(
            $this->ticketsTable,
            [
                'assigned_to' => $agentUserId,
                'updated_at' => $nowStr,
            ],
            'id = :where_id',
            ['where_id' => $ticketId]
        );

        return $this->requireTicket($ticketId);
    }

    public function transferDepartment(int $ticketId, int $newDepartmentId, ?int $newAgentUserId = null): Ticket
    {
        $ticket = $this->requireTicket($ticketId);
        $department = $this->departmentService->requireDepartment($newDepartmentId);

        if (!$department->isActive()) {
            throw new ValidationException(
                ['department_id' => "Cannot transfer ticket to inactive department '{$department->getName()}'."],
                'Target department inactive'
            );
        }

        if ($newAgentUserId !== null && $newAgentUserId > 0) {
            if (!$this->departmentService->isAgentInDepartment($newDepartmentId, $newAgentUserId)) {
                throw new ValidationException(
                    ['assigned_to' => "Agent {$newAgentUserId} does not belong to target department {$newDepartmentId}."],
                    'Invalid agent assignment'
                );
            }
        } else {
            $newAgentUserId = null;
        }

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->db->update(
            $this->ticketsTable,
            [
                'department_id' => $newDepartmentId,
                'assigned_to' => $newAgentUserId,
                'updated_at' => $nowStr,
            ],
            'id = :where_id',
            ['where_id' => $ticketId]
        );

        return $this->requireTicket($ticketId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Ticket>
     */
    public function listTickets(array $filters = []): array
    {
        $conditions = [];
        $bindings = [];

        if (!empty($filters['organization_id'])) {
            $conditions[] = 'organization_id = :org_id';
            $bindings['org_id'] = (int) $filters['organization_id'];
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = 'user_id = :user_id';
            $bindings['user_id'] = (int) $filters['user_id'];
        }

        if (!empty($filters['department_id'])) {
            $conditions[] = 'department_id = :dep_id';
            $bindings['dep_id'] = (int) $filters['department_id'];
        }

        if (array_key_exists('assigned_to', $filters)) {
            if ($filters['assigned_to'] === null) {
                $conditions[] = 'assigned_to IS NULL';
            } else {
                $conditions[] = 'assigned_to = :assigned_to';
                $bindings['assigned_to'] = (int) $filters['assigned_to'];
            }
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'status = :status';
            $bindings['status'] = (string) $filters['status'];
        }

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $placeholders = [];
            foreach (array_values($filters['statuses']) as $idx => $st) {
                $paramKey = 'status_' . $idx;
                $placeholders[] = ':' . $paramKey;
                $bindings[$paramKey] = (string) $st;
            }
            if (count($placeholders) > 0) {
                $conditions[] = 'status IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (!empty($filters['priority'])) {
            $conditions[] = 'priority = :priority';
            $bindings['priority'] = (string) $filters['priority'];
        }

        if (!empty($filters['service_id'])) {
            $conditions[] = 'service_id = :service_id';
            $bindings['service_id'] = (int) $filters['service_id'];
        }

        if (!empty($filters['domain_id'])) {
            $conditions[] = 'domain_id = :domain_id';
            $bindings['domain_id'] = (int) $filters['domain_id'];
        }

        if (!empty($filters['invoice_id'])) {
            $conditions[] = 'invoice_id = :invoice_id';
            $bindings['invoice_id'] = (int) $filters['invoice_id'];
        }

        if (!empty($filters['order_id'])) {
            $conditions[] = 'order_id = :order_id';
            $bindings['order_id'] = (int) $filters['order_id'];
        }

        $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = sprintf('SELECT * FROM %s %s ORDER BY id DESC', $this->ticketsTable, $whereClause);

        $rows = $this->db->select($sql, $bindings);

        return array_map(fn (array $r) => Ticket::fromArray($r), $rows);
    }

    public function linkResource(int $ticketId, string $resourceType, int $resourceId): Ticket
    {
        $this->requireTicket($ticketId);

        $column = match ($resourceType) {
            'service' => 'service_id',
            'domain' => 'domain_id',
            'invoice' => 'invoice_id',
            'order' => 'order_id',
            default => throw new ValidationException(
                ['resource_type' => "Unsupported resource type: '{$resourceType}'."],
                'Invalid resource type'
            ),
        };

        if ($resourceId <= 0) {
            throw new ValidationException(
                ['resource_id' => 'Valid resource ID is required.'],
                'Invalid resource ID'
            );
        }

        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(
            $this->ticketsTable,
            [
                $column => $resourceId,
                'updated_at' => $nowStr,
            ],
            'id = :where_id',
            ['where_id' => $ticketId]
        );

        return $this->requireTicket($ticketId);
    }

    public function unlinkResource(int $ticketId, string $resourceType): Ticket
    {
        $this->requireTicket($ticketId);

        $column = match ($resourceType) {
            'service' => 'service_id',
            'domain' => 'domain_id',
            'invoice' => 'invoice_id',
            'order' => 'order_id',
            default => throw new ValidationException(
                ['resource_type' => "Unsupported resource type: '{$resourceType}'."],
                'Invalid resource type'
            ),
        };

        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(
            $this->ticketsTable,
            [
                $column => null,
                'updated_at' => $nowStr,
            ],
            'id = :where_id',
            ['where_id' => $ticketId]
        );

        return $this->requireTicket($ticketId);
    }

    private function generateUniqueTicketNumber(): string
    {
        $prefix = 'TIC-' . date('Ymd') . '-';
        for ($i = 0; $i < 10; $i++) {
            $randomPart = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $candidate = $prefix . $randomPart;
            $existing = $this->getTicketByNumber($candidate);
            if ($existing === null) {
                return $candidate;
            }
        }

        // Fallback with microsecond entropy
        return $prefix . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }
}
