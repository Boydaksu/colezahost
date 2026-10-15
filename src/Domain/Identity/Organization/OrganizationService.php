<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity\Organization;
use Coleza\Domain\Identity\IdentityPersistence;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

/** Actor IDs are supplied by the authenticated application boundary, never request parameters. */
final class OrganizationService
{
    private string $orgsTable = 'organizations';
    private string $membersTable = 'organization_members';
    private string $invitationsTable = 'organization_invitations';
    private AuditLogger $audit;
    public function __construct(private Connection $db, private ?DatabaseSessionHandler $sessionHandler = null)
    {
        if ($sessionHandler !== null && !$sessionHandler->usesConnection($db)) { throw new \InvalidArgumentException('Organization and session storage must share a connection.'); }
        $this->audit = new AuditLogger($db);
    }
    public static function schemaRequirements(): array
    {
        return ['organizations' => ['id', 'name', 'slug', 'owner_user_id'],
            'organization_members' => ['id', 'organization_id', 'user_id', 'role'],
            'organization_invitations' => ['id', 'organization_id', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at']];
    }
    public function ensureTables(bool $installation = true): void
    {
        if (IdentityPersistence::installed($this->db, self::schemaRequirements(), $installation, ['organizations' => 'slug'])) {
            $this->assertMembershipIdentity();
            return;
        }
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
        $this->db->statement($sqlOrgs . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

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
        $this->db->statement($sqlMembers . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

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
        $this->db->statement($sqlInvites . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    public function createOrganization(int $ownerUserId, string $name, string $slug): int
    {
        $name = trim($name); $slug = strtolower(trim($slug));
        if ($name === '' || strlen($name) > 150 || !preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug)) { $this->reject('Invalid organization name or slug.'); }
        $this->prepare();
        return IdentityPersistence::run($this->db, function () use ($ownerUserId, $name, $slug): int {
            $this->actor($ownerUserId);
            if ($this->db->selectOne('SELECT id FROM organizations WHERE slug = ?' . $this->lock(), [$slug]) !== null) { $this->reject('Organization slug is already in use.'); }
            $id = (int) $this->db->insert('organizations', ['name' => $name, 'slug' => $slug, 'owner_user_id' => $ownerUserId]);
            $this->db->insert('organization_members', ['organization_id' => $id, 'user_id' => $ownerUserId, 'role' => 'owner']);
            $this->event($ownerUserId, 'created', $id);
            return $id;
        });
    }
    public function getUserOrganizations(int $userId): array
    {
        $this->prepare();
        return IdentityPersistence::run($this->db, function () use ($userId): array {
            $this->actor($userId);
            $rows = $this->db->select('SELECT o.*, m.role FROM organizations o JOIN organization_members m ON m.organization_id = o.id WHERE m.user_id = ? ORDER BY o.name' . $this->lock(), [$userId]);
            return array_values(array_filter($rows, fn(array $row): bool => $this->validOrganization($row) && $this->validMembership($row, $userId, (string) $row['role'])));
        });
    }
    public function inviteMember(int $inviterUserId, int $organizationId, string $email, string $role = 'member', int $ttlSeconds = 604800): string
    {
        $email = $this->email($email); $this->role($role); IdentityPersistence::expiresAt($ttlSeconds); $this->prepare();
        return IdentityPersistence::run($this->db, function () use ($inviterUserId, $organizationId, $email, $role, $ttlSeconds): string {
            $this->actor($inviterUserId); $org = $this->organization($organizationId);
            $authority = $this->manager($org, $inviterUserId);
            if ($role === 'admin' && $authority !== 'owner') { $this->reject('Only the owner may grant administrator membership.'); }
            $plain = bin2hex(random_bytes(32));
            $this->db->delete('organization_invitations', 'organization_id = :org AND email = :email AND accepted_at IS NULL', ['org' => $organizationId, 'email' => $email]);
            $this->db->insert('organization_invitations', ['organization_id' => $organizationId, 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $plain), 'expires_at' => IdentityPersistence::expiresAt($ttlSeconds), 'accepted_at' => null]);
            $this->event($inviterUserId, 'invited', $organizationId, ['role' => $role]);
            return $plain;
        });
    }
    public function acceptInvitation(int $userId, string $userEmail, string $plainToken): int
    {
        $userEmail = $this->email($userEmail);
        if (!preg_match('/^[a-f0-9]{64}$/D', $plainToken)) { $this->reject('Invalid invitation.'); }
        $this->prepare(); $hash = hash('sha256', $plainToken);
        return IdentityPersistence::run($this->db, function () use ($userId, $userEmail, $hash): int {
            $actor = $this->actor($userId);
            if ($this->email((string) $actor['email']) !== $userEmail || $actor['email_verified_at'] === null) { $this->reject('Invitation requires the current verified account email.'); }
            // Discovery only. All authorization and consume fields are reread under the organization lock.
            $hint = $this->db->select('SELECT organization_id FROM organization_invitations WHERE token_hash = ?', [$hash]);
            if (count($hint) !== 1) { $this->reject('Invalid invitation.'); }
            $org = $this->organization((int) $hint[0]['organization_id']);
            $rows = $this->db->select('SELECT * FROM organization_invitations WHERE token_hash = ?' . $this->lock(), [$hash]);
            if (count($rows) !== 1) { $this->reject('Invalid invitation.'); }
            $invite = $rows[0];
            if ((int) $invite['organization_id'] !== (int) $org['id'] || $invite['accepted_at'] !== null || (int) $invite['expires_at'] <= time() || $this->email((string) $invite['email']) !== $userEmail) { $this->reject('Invalid or expired invitation.'); }
            $this->role((string) $invite['role']);
            if ($this->membership($org, $userId) !== null) { $this->reject('Existing membership must be changed explicitly.'); }
            $this->db->insert('organization_members', ['organization_id' => $org['id'], 'user_id' => $userId, 'role' => $invite['role']]);
            $this->db->update('organization_invitations', ['accepted_at' => date('Y-m-d H:i:s')], 'id = :id AND accepted_at IS NULL', ['id' => $invite['id']]);
            $this->event($userId, 'invitation_accepted', (int) $org['id'], ['role' => $invite['role']]);
            return (int) $org['id'];
        });
    }
    public function changeMemberRole(int $actorUserId, int $organizationId, int $memberUserId, string $role): void
    {
        $this->role($role);
        $this->mutateMember($actorUserId, $organizationId, $memberUserId, $role);
    }
    public function removeMember(int $actorUserId, int $organizationId, int $memberUserId): void
    {
        $this->mutateMember($actorUserId, $organizationId, $memberUserId, null);
    }
    private function mutateMember(int $actorUserId, int $organizationId, int $memberUserId, ?string $role): void
    {
        $this->prepare();
        IdentityPersistence::run($this->db, function () use ($actorUserId, $organizationId, $memberUserId, $role): void {
            $this->actor($actorUserId); $org = $this->organization($organizationId); $authority = $this->manager($org, $actorUserId);
            $member = $this->membership($org, $memberUserId);
            if ($member === null || (int) $org['owner_user_id'] === $memberUserId || $member['role'] === 'owner') { $this->reject('Owner or missing membership cannot be changed.'); }
            if ($authority !== 'owner' && ($member['role'] === 'admin' || $role === 'admin' || $actorUserId === $memberUserId)) { $this->reject('Only the owner may manage administrators.'); }
            if ($role === null) { $this->db->delete('organization_members', 'id = :id', ['id' => $member['id']]); }
            else { $this->db->update('organization_members', ['role' => $role], 'id = :id', ['id' => $member['id']]); }
            // Revoke outstanding delegated grants when authority changes; no inviter provenance exists in the legacy schema.
            if ($member['role'] === 'admin' || $role === 'admin') {
                $this->db->delete('organization_invitations', 'organization_id = :org AND accepted_at IS NULL', ['org' => $organizationId]);
            }
            $this->event($actorUserId, $role === null ? 'member_removed' : 'member_role_changed', $organizationId, ['member_user_id' => $memberUserId, 'role' => $role]);
        });
    }
    public function cancelInvitation(int $actorUserId, int $organizationId, int $invitationId): void
    {
        $this->prepare();
        IdentityPersistence::run($this->db, function () use ($actorUserId, $organizationId, $invitationId): void {
            $this->actor($actorUserId); $org = $this->organization($organizationId); $authority = $this->manager($org, $actorUserId);
            $invite = $this->db->selectOne('SELECT * FROM organization_invitations WHERE id = ? AND organization_id = ?' . $this->lock(), [$invitationId, $organizationId]);
            if ($invite === null || $invite['accepted_at'] !== null || ($invite['role'] === 'admin' && $authority !== 'owner')) { $this->reject('Invitation cannot be cancelled.'); }
            $this->db->delete('organization_invitations', 'id = :id', ['id' => $invitationId]);
            $this->event($actorUserId, 'invitation_cancelled', $organizationId);
        });
    }
    public function switchOrganization(string $sessionId, int $userId, int $targetOrgId): void
    {
        $this->prepareSession();
        IdentityPersistence::run($this->db, function () use ($sessionId, $userId, $targetOrgId): void {
            $this->actor($userId); $org = $this->organization($targetOrgId);
            if ($this->membership($org, $userId) === null) { $this->reject('Organization access denied.'); }
            $this->sessionHandler->mutateOwned($sessionId, $userId, static function (array $payload) use ($targetOrgId): array {
                $payload['active_organization_id'] = $targetOrgId;
                unset($payload['active_organization_role']);
                return $payload;
            });
            $this->event($userId, 'selected', $targetOrgId);
        });
    }
    /** Resolve authority from current database membership, never cached session roles. */
    public function resolveActiveOrganization(string $sessionId, int $userId): ?array
    {
        $this->prepareSession();
        return IdentityPersistence::run($this->db, function () use ($sessionId, $userId): ?array {
            $this->actor($userId); $result = null;
            // Hint only, before acquiring session locks. Reread ownership and selection inside mutateOwned.
            $hint = $this->db->selectOne('SELECT payload FROM sessions WHERE id = ?', [$sessionId]);
            $decoded = $hint === null ? null : json_decode((string) $hint['payload'], true);
            $id = is_array($decoded) ? ($decoded['active_organization_id'] ?? null) : null;
            if ($id !== null && (!is_int($id) || $id < 1)) { $this->reject('Invalid organization selection.'); }
            $org = $id === null ? null : $this->organization($id);
            $membership = $org === null ? null : $this->membership($org, $userId);
            $this->sessionHandler->mutateOwned($sessionId, $userId, function (array $payload) use ($id, $org, $membership, &$result): array {
                if (($payload['active_organization_id'] ?? null) !== $id) { $this->reject('Organization selection changed; retry.'); }
                if ($id !== null) {
                    if ($membership === null) { $this->reject('Organization membership no longer valid.'); }
                    $result = ['id' => $id, 'name' => $org['name'], 'slug' => $org['slug'], 'role' => $membership['role']];
                }
                unset($payload['active_organization_role']);
                return $payload;
            });
            return $result;
        });
    }
    private function prepare(): void
    {
        $this->ensureTables(installation: false);
        if (!IdentityPersistence::installed($this->db, ['users' => ['id', 'email', 'is_active', 'email_verified_at']], installation: false)) { throw new \LogicException('Install user identity before organization operations.'); }
        $this->audit->ensureTable();
        IdentityPersistence::installed($this->db, ['security_audit_logs' => ['id', 'actor_user_id', 'event_type']], installation: false);
    }
    private function prepareSession(): void
    {
        if ($this->sessionHandler === null) { throw new \LogicException('Organization selection requires session storage.'); }
        $this->prepare(); $this->sessionHandler->prepareLifecycle();
    }
    private function actor(int $id): array
    {
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = ?' . $this->lock(), [$id]);
        if ($id < 1 || $user === null || (int) $user['is_active'] !== 1 || (array_key_exists('status', $user) && $user['status'] !== 'active')) { $this->reject('Active account required.'); }
        return $user;
    }
    private function organization(int $id): array
    {
        $org = $this->db->selectOne('SELECT * FROM organizations WHERE id = ?' . $this->lock(), [$id]);
        if ($org === null || !$this->validOrganization($org)) { $this->reject('Active organization required.'); }
        return $org;
    }
    private function validOrganization(array $org): bool { return !array_key_exists('status', $org) || $org['status'] === 'active'; }
    private function validMembership(array $org, int $userId, string $role): bool
    {
        return $role === 'owner' ? (int) $org['owner_user_id'] === $userId : (int) $org['owner_user_id'] !== $userId && in_array($role, ['admin', 'member', 'billing', 'technical'], true);
    }
    private function membership(array $org, int $id): ?array
    {
        $member = $this->db->selectOne('SELECT * FROM organization_members WHERE organization_id = ? AND user_id = ?' . $this->lock(), [$org['id'], $id]);
        if ($member !== null && !$this->validMembership($org, $id, (string) $member['role'])) { $this->reject('Inconsistent organization membership.'); }
        return $member;
    }
    private function manager(array $org, int $id): string
    {
        $role = $this->membership($org, $id)['role'] ?? null;
        if (!in_array($role, ['owner', 'admin'], true)) { $this->reject('Organization management denied.'); }
        return $role;
    }
    private function role(string $role): void
    {
        if (!in_array($role, ['admin', 'member', 'billing', 'technical'], true)) { $this->reject('Invalid assignable organization role.'); }
    }
    private function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->reject('Invalid invitation email.'); }
        return $email;
    }
    private function event(int $actor, string $event, int $org, array $payload = []): void
    {
        $this->audit->log($actor, 'organization.' . $event, 'organization:' . $org, payload: $payload);
    }
    private function lock(): string { return IdentityPersistence::lock($this->db); }
    private function reject(string $message): never { throw new ValidationException(['organization' => [$message]]); }
    private function assertMembershipIdentity(): void
    {
        if ($this->db->getDriverName() === 'mysql') {
            $groups = [];
            foreach ($this->db->select("SELECT INDEX_NAME, COLUMN_NAME, SUB_PART FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'organization_members' AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX") as $row) { $groups[$row['INDEX_NAME']][] = $row; }
            foreach ($groups as $group) { if (array_column($group, 'COLUMN_NAME') === ['organization_id', 'user_id'] && array_filter($group, static fn(array $r): bool => $r['SUB_PART'] !== null) === []) { return; } }
        } else {
            foreach ($this->db->select('PRAGMA index_list(organization_members)') as $index) {
                if ((int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) { continue; }
                $name = str_replace("'", "''", $index['name']);
                if (array_column($this->db->select("PRAGMA index_info('{$name}')"), 'name') === ['organization_id', 'user_id']) { return; }
            }
        }
        throw new \LogicException('Organization membership requires its full unique identity.');
    }
}
