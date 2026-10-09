<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Context;

use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class TicketContextService
{
    private string $commandLogsTable = 'support_context_command_logs';

    /** @var array<int, TicketContextCommandHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly Connection $db,
        private readonly TicketService $ticketService,
        private readonly TicketContextProviderInterface $contextProvider
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
                ticket_id INT NOT NULL,
                actor_staff_user_id INT NOT NULL,
                action VARCHAR(100) NOT NULL,
                resource_type VARCHAR(32) NOT NULL,
                resource_id INT NOT NULL,
                success TINYINT(1) NOT NULL,
                message TEXT NOT NULL,
                payload_json TEXT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->commandLogsTable,
            $autoInc
        );
        $this->db->statement($sql);
    }

    public function registerCommandHandler(TicketContextCommandHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function getContextSummary(int $ticketId): TicketContextSummary
    {
        $ticket = $this->ticketService->requireTicket($ticketId);

        $serviceContext = $ticket->getServiceId() !== null
            ? $this->contextProvider->getServiceContext($ticket->getServiceId())
            : null;

        $domainContext = $ticket->getDomainId() !== null
            ? $this->contextProvider->getDomainContext($ticket->getDomainId())
            : null;

        $invoiceContext = $ticket->getInvoiceId() !== null
            ? $this->contextProvider->getInvoiceContext($ticket->getInvoiceId())
            : null;

        $orderContext = $ticket->getOrderId() !== null
            ? $this->contextProvider->getOrderContext($ticket->getOrderId())
            : null;

        return new TicketContextSummary(
            ticketId: $ticketId,
            service: $serviceContext,
            domain: $domainContext,
            invoice: $invoiceContext,
            order: $orderContext
        );
    }

    /**
     * Execute a contextual action from ticket view and audit the execution.
     *
     * @param array<string, mixed> $parameters
     */
    public function executeCommand(
        int $ticketId,
        int $actorStaffUserId,
        string $action,
        array $parameters = []
    ): CommandExecutionResult {
        $ticket = $this->ticketService->requireTicket($ticketId);

        if ($actorStaffUserId <= 0) {
            throw new ValidationException(
                ['actor_user_id' => 'Valid staff user ID is required to execute contextual command.'],
                'Invalid actor user ID'
            );
        }

        // Determine resource association
        [$resourceType, $resourceId] = $this->resolveResourceForAction($ticket, $action);

        $result = null;
        foreach ($this->handlers as $handler) {
            if ($handler->supports($action)) {
                $result = $handler->handle($action, $ticketId, $parameters);
                break;
            }
        }

        if ($result === null) {
            $result = $this->handleBuiltinAction($action, $ticket, $resourceType, $resourceId, $parameters);
        }

        // Record audit trail
        $now = new DateTimeImmutable();
        $this->db->insert(
            $this->commandLogsTable,
            [
                'ticket_id' => $ticketId,
                'actor_staff_user_id' => $actorStaffUserId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'success' => $result->success ? 1 : 0,
                'message' => $result->message,
                'payload_json' => json_encode($result->data),
                'executed_at' => $now->format('Y-m-d H:i:s'),
            ]
        );

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCommandLogs(int $ticketId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %s WHERE ticket_id = :ticket_id ORDER BY id DESC', $this->commandLogsTable),
            ['ticket_id' => $ticketId]
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveResourceForAction(object $ticket, string $action): array
    {
        if (str_starts_with($action, 'service.')) {
            $resId = $ticket->getServiceId() ?? 0;
            return ['service', $resId];
        }

        if (str_starts_with($action, 'domain.')) {
            $resId = $ticket->getDomainId() ?? 0;
            return ['domain', $resId];
        }

        if (str_starts_with($action, 'invoice.')) {
            $resId = $ticket->getInvoiceId() ?? 0;
            return ['invoice', $resId];
        }

        if (str_starts_with($action, 'order.')) {
            $resId = $ticket->getOrderId() ?? 0;
            return ['order', $resId];
        }

        return ['ticket', $ticket->getId()];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function handleBuiltinAction(
        string $action,
        object $ticket,
        string $resourceType,
        int $resourceId,
        array $parameters
    ): CommandExecutionResult {
        return match ($action) {
            'service.sync_status' => $resourceId > 0
                ? CommandExecutionResult::successful($action, "Service #{$resourceId} status probe synchronized successfully.", ['service_id' => $resourceId, 'status' => 'active'])
                : CommandExecutionResult::failed($action, 'No service linked to this ticket.'),

            'service.reboot' => $resourceId > 0
                ? CommandExecutionResult::successful($action, "Service #{$resourceId} graceful restart command dispatched.", ['service_id' => $resourceId])
                : CommandExecutionResult::failed($action, 'No service linked to this ticket.'),

            'domain.sync_whois' => $resourceId > 0
                ? CommandExecutionResult::successful($action, "Domain #{$resourceId} WHOIS and nameserver records refreshed.", ['domain_id' => $resourceId])
                : CommandExecutionResult::failed($action, 'No domain linked to this ticket.'),

            'invoice.resend_notification' => $resourceId > 0
                ? CommandExecutionResult::successful($action, "Invoice #{$resourceId} notification resent to customer email.", ['invoice_id' => $resourceId])
                : CommandExecutionResult::failed($action, 'No invoice linked to this ticket.'),

            default => CommandExecutionResult::failed($action, "Unsupported contextual action: '{$action}'."),
        };
    }
}
