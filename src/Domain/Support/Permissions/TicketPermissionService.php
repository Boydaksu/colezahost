<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Permissions;

use Coleza\Domain\Support\Tickets\Ticket;
use Coleza\Foundation\Exceptions\ValidationException;

final class TicketPermissionService
{
    public function canView(Ticket $ticket, SupportUserContext $user): bool
    {
        // 1. Superadmin has global access
        if ($user->isSuperAdmin()) {
            return true;
        }

        // 2. Staff user access
        if ($user->isStaff()) {
            if (!$user->hasPermission('tickets.view')) {
                return false;
            }

            // Department scoping for agents with assigned departments
            $agentDepts = $user->getDepartmentIds();
            if (count($agentDepts) > 0 && !in_array($ticket->getDepartmentId(), $agentDepts, true)) {
                return false;
            }

            return true;
        }

        // 3. Organization-scoped ticket
        if ($ticket->getOrganizationId() !== null) {
            // Strict barrier: Cross-organization access is strictly forbidden
            if ($user->getActiveOrganizationId() !== $ticket->getOrganizationId()) {
                return false;
            }

            // Organization owner/admin or member with view_all can view any ticket in their organization
            if ($user->canViewAllOrgTickets()) {
                return true;
            }

            // Standard organization member can only view tickets they created
            return $user->getUserId() === $ticket->getUserId();
        }

        // 4. Personal/individual ticket
        return $user->getUserId() === $ticket->getUserId();
    }

    public function canReply(Ticket $ticket, SupportUserContext $user): bool
    {
        if (!$this->canView($ticket, $user)) {
            return false;
        }

        if ($user->isStaff()) {
            return $user->hasPermission('tickets.reply');
        }

        // Customers can reply to tickets they can view
        return true;
    }

    public function canManage(Ticket $ticket, SupportUserContext $user): bool
    {
        if (!$this->canView($ticket, $user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isStaff()) {
            return $user->hasPermission('tickets.manage') || $user->hasPermission('*');
        }

        // Organization owner/admin can manage tickets within their organization
        if ($ticket->getOrganizationId() !== null && $user->getActiveOrganizationId() === $ticket->getOrganizationId()) {
            if ($user->isOrgOwnerOrAdmin()) {
                return true;
            }
        }

        // Ticket creator can close/reopen their own ticket
        return $user->getUserId() === $ticket->getUserId();
    }

    public function assertCanView(Ticket $ticket, SupportUserContext $user): void
    {
        if (!$this->canView($ticket, $user)) {
            throw new ValidationException(
                ['authorization' => "Access denied: you do not have permission to view ticket '{$ticket->getTicketNumber()}'."],
                'Ticket access forbidden'
            );
        }
    }

    public function assertCanReply(Ticket $ticket, SupportUserContext $user): void
    {
        if (!$this->canReply($ticket, $user)) {
            throw new ValidationException(
                ['authorization' => "Access denied: you do not have permission to reply to ticket '{$ticket->getTicketNumber()}'."],
                'Ticket reply forbidden'
            );
        }
    }

    public function assertCanManage(Ticket $ticket, SupportUserContext $user): void
    {
        if (!$this->canManage($ticket, $user)) {
            throw new ValidationException(
                ['authorization' => "Access denied: you do not have permission to manage ticket '{$ticket->getTicketNumber()}'."],
                'Ticket management forbidden'
            );
        }
    }

    /**
     * @param array<int, Ticket> $tickets
     * @return array<int, Ticket>
     */
    public function filterAuthorizedTickets(array $tickets, SupportUserContext $user): array
    {
        return array_values(array_filter($tickets, fn (Ticket $t) => $this->canView($t, $user)));
    }

    /**
     * Prepares parameters for TicketService::listTickets so queries enforce multi-tenant boundaries at the database level.
     *
     * @return array<string, mixed>
     */
    public function getAuthorizedQueryFilters(SupportUserContext $user): array
    {
        if ($user->isSuperAdmin()) {
            return [];
        }

        if ($user->isStaff()) {
            // Staff sees all tickets
            return [];
        }

        if ($user->getActiveOrganizationId() !== null) {
            $orgId = $user->getActiveOrganizationId();
            if ($user->canViewAllOrgTickets()) {
                return ['organization_id' => $orgId];
            }

            return [
                'organization_id' => $orgId,
                'user_id' => $user->getUserId(),
            ];
        }

        // Individual user
        return ['user_id' => $user->getUserId()];
    }
}
