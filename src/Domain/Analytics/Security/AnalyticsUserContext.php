<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Security;

final class AnalyticsUserContext
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        private readonly int $userId,
        private readonly bool $isStaff = false,
        private readonly bool $isSuperAdmin = false,
        private readonly ?int $organizationId = null,
        private readonly ?string $organizationRole = null,
        private readonly array $permissions = []
    ) {
    }

    public static function forSuperAdmin(int $userId = 1): self
    {
        return new self(
            userId: $userId,
            isStaff: true,
            isSuperAdmin: true,
            permissions: ['*']
        );
    }

    /**
     * @param list<string> $permissions
     */
    public static function forStaff(int $userId, array $permissions = []): self
    {
        return new self(
            userId: $userId,
            isStaff: true,
            isSuperAdmin: false,
            permissions: $permissions
        );
    }

    /**
     * @param list<string> $permissions
     */
    public static function forOrgUser(
        int $userId,
        int $organizationId,
        string $role = 'member',
        array $permissions = []
    ): self {
        return new self(
            userId: $userId,
            isStaff: false,
            isSuperAdmin: false,
            organizationId: $organizationId,
            organizationRole: $role,
            permissions: $permissions
        );
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

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getOrganizationRole(): ?string
    {
        return $this->organizationRole;
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }

        if (in_array('*', $this->permissions, true)) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }
}
