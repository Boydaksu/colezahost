<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Permissions;

final class SupportUserContext
{
    /**
     * @param array<int, string> $permissions
     * @param array<int, int> $departmentIds
     */
    public function __construct(
        private readonly int $userId,
        private readonly bool $isStaff = false,
        private readonly bool $isSuperAdmin = false,
        private readonly ?int $activeOrganizationId = null,
        private readonly ?string $organizationRole = null,
        private readonly array $permissions = [],
        private readonly array $departmentIds = []
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function isStaff(): bool
    {
        return $this->isStaff;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    public function getActiveOrganizationId(): ?int
    {
        return $this->activeOrganizationId;
    }

    public function getOrganizationRole(): ?string
    {
        return $this->organizationRole;
    }

    /**
     * @return array<int, string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return array<int, int>
     */
    public function getDepartmentIds(): array
    {
        return $this->departmentIds;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin || in_array('*', $this->permissions, true)) {
            return true;
        }

        if (in_array($permission, $this->permissions, true)) {
            return true;
        }

        // Wildcard prefix match, e.g. 'org.*' covers 'org.tickets.all'
        foreach ($this->permissions as $p) {
            if (str_ends_with($p, '.*')) {
                $prefix = substr($p, 0, -2);
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isOrgOwnerOrAdmin(): bool
    {
        return in_array($this->organizationRole, ['owner', 'admin'], true);
    }

    public function canViewAllOrgTickets(): bool
    {
        if ($this->isOrgOwnerOrAdmin()) {
            return true;
        }

        return $this->hasPermission('org.tickets.all') || $this->hasPermission('org.*');
    }

    public static function superAdmin(int $userId): self
    {
        return new self(
            userId: $userId,
            isStaff: true,
            isSuperAdmin: true,
            permissions: ['*']
        );
    }

    /**
     * @param array<int, int> $departmentIds
     * @param array<int, string> $permissions
     */
    public static function supportAgent(
        int $userId,
        array $departmentIds = [],
        array $permissions = ['tickets.view', 'tickets.reply']
    ): self {
        return new self(
            userId: $userId,
            isStaff: true,
            isSuperAdmin: false,
            permissions: $permissions,
            departmentIds: $departmentIds
        );
    }

    public static function orgOwner(int $userId, int $organizationId): self
    {
        return new self(
            userId: $userId,
            isStaff: false,
            isSuperAdmin: false,
            activeOrganizationId: $organizationId,
            organizationRole: 'owner',
            permissions: ['org.*']
        );
    }

    public static function orgAdmin(int $userId, int $organizationId): self
    {
        return new self(
            userId: $userId,
            isStaff: false,
            isSuperAdmin: false,
            activeOrganizationId: $organizationId,
            organizationRole: 'admin',
            permissions: ['org.tickets.all', 'org.tickets.create']
        );
    }

    public static function orgMember(int $userId, int $organizationId, bool $canViewAll = false): self
    {
        $perms = ['org.tickets.create', 'org.tickets.own'];
        if ($canViewAll) {
            $perms[] = 'org.tickets.all';
        }

        return new self(
            userId: $userId,
            isStaff: false,
            isSuperAdmin: false,
            activeOrganizationId: $organizationId,
            organizationRole: 'member',
            permissions: $perms
        );
    }

    public static function individual(int $userId): self
    {
        return new self(
            userId: $userId,
            isStaff: false,
            isSuperAdmin: false,
            permissions: ['tickets.view_own', 'tickets.reply_own']
        );
    }
}
