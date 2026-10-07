<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Organization;

use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class OrganizationService
{
    private string $orgsTable = 'organizations';
    private string $membersTable = 'organization_members';
    private string $invitationsTable = 'organization_invitations';

    public function __construct(
        private Connection $db,
        private ?DatabaseSessionHandler $sessionHandler = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Organizations table
        $sqlOrgs = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                owner_user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->orgsTable,
            $autoInc
        );
        $this->db->statement($sqlOrgs);

        // Memberships table
        $sqlMembers = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                organization_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT "member",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(organization_id, user_id)
            )',
            $this->membersTable,
            $autoInc
        );
        $this->db->statement($sqlMembers);

        // Invitations table
        $sqlInvites = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                organization_id INT NOT NULL,
                email VARCHAR(191) NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT "member",
                token_hash VARCHAR(128) NOT NULL,
                expires_at INT NOT NULL,
                accepted_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->invitationsTable,
            $autoInc
        );
        $this->db->statement($sqlInvites);
    }

    /**
     * Create an organization and make creator the owner.
     *
     * @return int Created Organization ID
     */
    public function createOrganization(int $ownerUserId, string $name, string $slug): int
    {
        $this->ensureTables();
        $slug = strtolower(trim($slug));

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE slug = :slug', $this->orgsTable),
            ['slug' => $slug]
        );

        if ($existing !== null) {
            throw new ValidationException(['slug' => ['Organization slug is already in use.']]);
        }

        return $this->db->transaction(function (Connection $db) use ($ownerUserId, $name, $slug): int {
            $orgId = (int) $db->insert($this->orgsTable, [
                'name' => trim($name),
                'slug' => $slug,
                'owner_user_id' => $ownerUserId,
            ]);

            $db->insert($this->membersTable, [
                'organization_id' => $orgId,
                'user_id' => $ownerUserId,
                'role' => 'owner',
            ]);

            return $orgId;
        });
    }

    /**
     * Get all organizations where user is a member.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserOrganizations(int $userId): array
    {
        $this->ensureTables();
        return $this->db->select(
            sprintf(
                'SELECT o.id, o.name, o.slug, o.owner_user_id, m.role 
                 FROM %s o
                 JOIN %s m ON m.organization_id = o.id
                 WHERE m.user_id = :uid
                 ORDER BY o.name ASC',
                $this->orgsTable,
                $this->membersTable
            ),
            ['uid' => $userId]
        );
    }

    /**
     * Switch active organization in session.
     */
    public function switchOrganization(string $sessionId, int $userId, int $targetOrgId): void
    {
        $this->ensureTables();
        // Verify user is a member of target organization
        $membership = $this->db->selectOne(
            sprintf('SELECT role FROM %s WHERE organization_id = :org_id AND user_id = :uid', $this->membersTable),
            ['org_id' => $targetOrgId, 'uid' => $userId]
        );

        if ($membership === null) {
            throw new ValidationException(['organization' => ['User does not have access to this organization.']]);
        }

        if ($this->sessionHandler !== null) {
            $session = $this->sessionHandler->read($sessionId);
            $session['active_organization_id'] = $targetOrgId;
            $session['active_organization_role'] = $membership['role'];
            $this->sessionHandler->write($sessionId, $session, userId: $userId);
        }
    }

    /**
     * Invite user by email to join organization.
     *
     * @return string Plain invitation token to email
     */
    public function inviteMember(int $inviterUserId, int $organizationId, string $email, string $role = 'member', int $ttlSeconds = 604800): string
    {
        $this->ensureTables();
        $email = strtolower(trim($email));

        // Check if inviter is owner or admin in this org
        $inviterRole = $this->db->selectOne(
            sprintf('SELECT role FROM %s WHERE organization_id = :org_id AND user_id = :uid', $this->membersTable),
            ['org_id' => $organizationId, 'uid' => $inviterUserId]
        );

        if ($inviterRole === null || !in_array($inviterRole['role'], ['owner', 'admin'], true)) {
            throw new ValidationException(['permission' => ['Only organization owners and admins can send invitations.']]);
        }

        // Cancel previous pending invites for this email
        $this->db->delete($this->invitationsTable, 'organization_id = :org_id AND email = :email AND accepted_at IS NULL', [
            'org_id' => $organizationId,
            'email' => $email,
        ]);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $this->db->insert($this->invitationsTable, [
            'organization_id' => $organizationId,
            'email' => $email,
            'role' => $role,
            'token_hash' => $tokenHash,
            'expires_at' => time() + $ttlSeconds,
            'accepted_at' => null,
        ]);

        return $plainToken;
    }

    /**
     * Accept invitation using token and add user to organization.
     */
    public function acceptInvitation(int $userId, string $userEmail, string $plainToken): int
    {
        $this->ensureTables();
        $userEmail = strtolower(trim($userEmail));
        $tokenHash = hash('sha256', $plainToken);

        $invite = $this->db->selectOne(
            sprintf(
                'SELECT id, organization_id, email, role, expires_at, accepted_at 
                 FROM %s 
                 WHERE token_hash = :hash',
                $this->invitationsTable
            ),
            ['hash' => $tokenHash]
        );

        if ($invite === null || $invite['accepted_at'] !== null || (int) $invite['expires_at'] < time()) {
            throw new ValidationException(['invitation' => ['Invalid or expired invitation token.']]);
        }

        if (strtolower((string) $invite['email']) !== $userEmail) {
            throw new ValidationException(['invitation' => ['This invitation was issued to another email address.']]);
        }

        $orgId = (int) $invite['organization_id'];
        $role = (string) $invite['role'];

        $this->db->transaction(function (Connection $db) use ($invite, $orgId, $userId, $role): void {
            // Mark invitation accepted
            $db->update(
                $this->invitationsTable,
                ['accepted_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $invite['id']]
            );

            // Insert or update membership
            $existing = $db->selectOne(
                sprintf('SELECT id FROM %s WHERE organization_id = :org_id AND user_id = :uid', $this->membersTable),
                ['org_id' => $orgId, 'uid' => $userId]
            );

            if ($existing === null) {
                $db->insert($this->membersTable, [
                    'organization_id' => $orgId,
                    'user_id' => $userId,
                    'role' => $role,
                ]);
            }
        });

        return $orgId;
    }
}
