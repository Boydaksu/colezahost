<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Identity\Organization\OrganizationService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Domain\Identity\Auth\AuthService;
use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Foundation\Exceptions\ValidationException;
trait OrganizationAuthorizationCases
{
    private function organizations(): array
    {
        $sessions = new DatabaseSessionHandler($this->db);
        (new AuthService($this->db, new PasswordHasher(), $sessions))->ensureTables();
        foreach ([1 => 'owner', 2 => 'member', 3 => 'attacker', 4 => 'admin'] as $id => $name) {
            $this->db->insert('users', ['id' => $id, 'email' => $name . '@example.test', 'name' => $name, 'password_hash' => 'unused', 'is_active' => 1, 'email_verified_at' => '2026-01-01 00:00:00']);
        }
        $service = new OrganizationService($this->db, $sessions);
        $org = $service->createOrganization(1, 'Company', 'company');
        $sessions->write('owner-session', ['marker' => 'preserved'], 1);
        $sessions->write('member-session', ['marker' => 'preserved'], 2);
        return [$service, $sessions, $org];
    }
    public function testInvitationCannotUseSuppliedEmailToJoinAsAnotherAccount(): void
    {
        [$service, , $org] = $this->organizations();
        $token = $service->inviteMember(1, $org, 'member@example.test');
        try { $service->acceptInvitation(3, 'member@example.test', $token); self::fail('Forged email accepted'); }
        catch (ValidationException) { self::assertSame([], $service->getUserOrganizations(3)); self::assertNull($this->db->selectOne('SELECT accepted_at FROM organization_invitations')['accepted_at']); }
    }
    public function testOwnerRoleCannotBeGrantedByInvitation(): void
    {
        [$service, , $org] = $this->organizations();
        $this->expectException(ValidationException::class);
        $service->inviteMember(1, $org, 'member@example.test', 'owner');
    }
    public function testSwitchCannotTakeOverAnotherUsersSession(): void
    {
        [$service, $sessions, $org] = $this->organizations();
        $before = $this->db->selectOne("SELECT * FROM sessions WHERE id = 'member-session'");
        try { $service->switchOrganization('member-session', 1, $org); self::fail('Foreign session changed'); }
        catch (ValidationException) { self::assertSame($before, $this->db->selectOne("SELECT * FROM sessions WHERE id = 'member-session'")); }
    }

    private function join(OrganizationService $service, int $org, int $id, string $role = 'member'): void
    {
        $email = $id === 4 ? 'admin@example.test' : 'member@example.test';
        $service->acceptInvitation($id, $email, $service->inviteMember(1, $org, $email, $role));
    }
    public function testRoleAndRemovalUseCurrentMembershipAndNeverAffectAnotherOrganization(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 2); $this->join($service, $org, 4, 'admin');
        $other = $service->createOrganization(3, 'Other', 'other');
        foreach ([fn() => $service->changeMemberRole(3, $org, 2, 'admin'), fn() => $service->removeMember(3, $org, 2), fn() => $service->changeMemberRole(4, $org, 2, 'admin'), fn() => $service->removeMember(4, $org, 1), fn() => $service->changeMemberRole(1, $org, 1, 'member'), fn() => $service->inviteMember(2, $org, 'attacker@example.test'), fn() => $service->inviteMember(4, $org, 'attacker@example.test', 'admin')] as $attempt) {
            $before = $this->snapshotOrganizations();
            try { $attempt(); self::fail('Unauthorized mutation accepted'); } catch (ValidationException) { self::assertSame($before, $this->snapshotOrganizations()); }
        }
        $service->changeMemberRole(4, $org, 2, 'billing'); self::assertSame('billing', $service->getUserOrganizations(2)[0]['role']);
        $service->changeMemberRole(1, $org, 4, 'member');
        try { $service->removeMember(4, $org, 2); self::fail('Demoted actor accepted'); } catch (ValidationException) { self::assertCount(1, $service->getUserOrganizations(2)); }
        $service->removeMember(1, $org, 2); self::assertSame([], $service->getUserOrganizations(2));
        self::assertSame($other, (int) $service->getUserOrganizations(3)[0]['id']);
    }
    public function testSelectionRechecksCurrentRoleAndRemovalDespiteForgedCachedRole(): void
    {
        [$service, $sessions, $org] = $this->organizations(); $this->join($service, $org, 2, 'admin');
        $service->switchOrganization('member-session', 2, $org);
        $sessions->write('member-session', ['active_organization_id' => $org, 'active_organization_role' => 'owner', 'marker' => 'preserved'], 2);
        $service->changeMemberRole(1, $org, 2, 'billing');
        self::assertSame('billing', $service->resolveActiveOrganization('member-session', 2)['role']);
        self::assertSame('preserved', $sessions->read('member-session')['marker']);
        self::assertArrayNotHasKey('active_organization_role', $sessions->read('member-session'));
        $service->removeMember(1, $org, 2);
        $this->expectException(ValidationException::class); $service->resolveActiveOrganization('member-session', 2);
    }
    public function testMissingExpiredRevokedAndAnonymousSessionsCannotBeSelected(): void
    {
        [$service, $sessions, $org] = $this->organizations();
        $sessions->write('expired', [], 1); $this->db->statement("UPDATE sessions SET last_activity = 0 WHERE id = 'expired'");
        $sessions->write('revoked', [], 1); $sessions->destroy('revoked'); $sessions->write('anonymous', []);
        foreach (['missing', 'expired', 'revoked', 'anonymous'] as $id) {
            $before = $this->snapshotOrganizations();
            try { $service->switchOrganization($id, 1, $org); self::fail('Invalid session accepted'); }
            catch (ValidationException) { self::assertSame($before, $this->snapshotOrganizations()); }
        }
    }
    public function testInvitationRequiresActiveVerifiedCurrentAccountAndExactExpiry(): void
    {
        [$service, , $org] = $this->organizations(); $token = $service->inviteMember(1, $org, 'member@example.test');
        foreach ([['is_active', 0], ['email_verified_at', null], ['email', 'changed@example.test']] as [$column, $value]) {
            $before = $this->db->selectOne('SELECT * FROM users WHERE id = 2'); $this->db->update('users', [$column => $value], 'id = 2');
            try { $service->acceptInvitation(2, 'member@example.test', $token); self::fail('Invalid actor accepted'); }
            catch (ValidationException) { self::assertNull($this->db->selectOne('SELECT accepted_at FROM organization_invitations')['accepted_at']); }
            $this->db->update('users', [$column => $before[$column]], 'id = 2');
        }
        $this->db->statement('UPDATE organization_invitations SET expires_at = ?', [time()]);
        $this->expectException(ValidationException::class); $service->acceptInvitation(2, 'member@example.test', $token);
    }
    public function testInvitationReplacementCancellationAndReplayFailClosed(): void
    {
        [$service, , $org] = $this->organizations(); $old = $service->inviteMember(1, $org, 'member@example.test'); $new = $service->inviteMember(1, $org, 'member@example.test', 'billing');
        try { $service->acceptInvitation(2, 'member@example.test', $old); self::fail('Replaced invitation accepted'); } catch (ValidationException) { self::assertCount(1, $this->db->select('SELECT * FROM organization_invitations')); }
        $service->acceptInvitation(2, 'member@example.test', $new);
        try { $service->acceptInvitation(2, 'member@example.test', $new); self::fail('Invitation replay accepted'); } catch (ValidationException) { self::assertSame('billing', $service->getUserOrganizations(2)[0]['role']); }
        $existing = $service->inviteMember(1, $org, 'member@example.test', 'admin');
        try { $service->acceptInvitation(2, 'member@example.test', $existing); self::fail('Invitation escalated existing membership'); } catch (ValidationException) { self::assertSame('billing', $service->getUserOrganizations(2)[0]['role']); }
        $row = $this->db->selectOne('SELECT id FROM organization_invitations WHERE accepted_at IS NULL');
        $other = $service->createOrganization(3, 'Other', 'other');
        try { $service->cancelInvitation(3, $other, (int) $row['id']); self::fail('Cross tenant cancel accepted'); } catch (ValidationException) { self::assertNotNull($this->db->selectOne('SELECT id FROM organization_invitations WHERE id = ?', [$row['id']])); }
        $service->cancelInvitation(1, $org, (int) $row['id']);
        $this->expectException(ValidationException::class); $service->acceptInvitation(2, 'member@example.test', $existing);
    }
    public function testAdministratorDemotionInvalidatesPendingDelegatedGrants(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 4, 'admin');
        $token = $service->inviteMember(4, $org, 'member@example.test'); $service->changeMemberRole(1, $org, 4, 'member');
        $this->expectException(ValidationException::class); $service->acceptInvitation(2, 'member@example.test', $token);
    }
    public function testInvalidRoleEmailAndLifetimeDoNotReplaceExistingInvitation(): void
    {
        [$service, , $org] = $this->organizations(); $service->inviteMember(1, $org, 'member@example.test');
        foreach ([['member@example.test', 'owner', 60], ['member@example.test', 'super_admin', 60], ['bad-email', 'member', 60], ['member@example.test', 'member', 0], ['member@example.test', 'member', PHP_INT_MAX]] as [$email, $role, $ttl]) {
            $before = $this->snapshotOrganizations();
            try { $service->inviteMember(1, $org, $email, $role, $ttl); self::fail('Invalid grant accepted'); }
            catch (ValidationException|\InvalidArgumentException) { self::assertSame($before, $this->snapshotOrganizations()); }
        }
    }
    public function testForgedOwnerMembershipAndInactiveOrganizationCannotAuthorize(): void
    {
        [$service, , $org] = $this->organizations();
        $this->db->insert('organization_members', ['organization_id' => $org, 'user_id' => 3, 'role' => 'owner']);
        try { $service->inviteMember(3, $org, 'member@example.test'); self::fail('Forged owner accepted'); } catch (ValidationException) { self::assertSame([], $this->db->select('SELECT * FROM organization_invitations')); }
        $this->db->statement("ALTER TABLE organizations ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        $token = $service->inviteMember(1, $org, 'member@example.test'); $this->db->statement("UPDATE organizations SET status = 'suspended'");
        self::assertSame([], $service->getUserOrganizations(1));
        $this->expectException(ValidationException::class); $service->acceptInvitation(2, 'member@example.test', $token);
    }
    private function snapshotOrganizations(): array
    {
        $result = [];
        foreach (['organizations', 'organization_members', 'organization_invitations', 'security_audit_logs', 'sessions', 'session_revocations'] as $table) { $result[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY 1"); }
        return $result;
    }
    public function testAllMutationsRollBackWithRawAndManagedCallers(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 2); $token = $service->inviteMember(1, $org, 'admin@example.test');
        foreach (['raw', 'managed'] as $kind) {
            $before = $this->snapshotOrganizations();
            $operation = function () use ($service, $org, $token): void {
                $service->createOrganization(3, 'Other', 'other'); $service->inviteMember(1, $org, 'attacker@example.test');
                $service->acceptInvitation(4, 'admin@example.test', $token); $service->changeMemberRole(1, $org, 2, 'billing');
                $service->switchOrganization('owner-session', 1, $org); $service->removeMember(1, $org, 2);
                self::assertTrue($this->db->getPdo()->inTransaction());
                throw new \RuntimeException('parent rollback');
            };
            if ($kind === 'raw') {
                $this->db->getPdo()->beginTransaction();
                try { $operation(); } catch (\RuntimeException $e) { self::assertSame('parent rollback', $e->getMessage()); }
                finally { $this->db->getPdo()->rollBack(); }
            } else { try { $this->db->transaction($operation); } catch (\RuntimeException $e) { self::assertSame('parent rollback', $e->getMessage()); } }
            self::assertSame($before, $this->snapshotOrganizations());
        }
    }
    public function testAuditFailureRollsBackEachMutationAndPreservesCaller(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 2); $token = $service->inviteMember(1, $org, 'admin@example.test');
        $invitation = (int) $this->db->selectOne('SELECT id FROM organization_invitations WHERE accepted_at IS NULL')['id'];
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_org_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'"
            : "CREATE TRIGGER reject_org_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        $attempts = [fn() => $service->createOrganization(3, 'Other', 'other'), fn() => $service->inviteMember(1, $org, 'admin@example.test'), fn() => $service->acceptInvitation(4, 'admin@example.test', $token), fn() => $service->changeMemberRole(1, $org, 2, 'admin'), fn() => $service->removeMember(1, $org, 2), fn() => $service->switchOrganization('owner-session', 1, $org), fn() => $service->cancelInvitation(1, $org, $invitation)];
        foreach (['standalone', 'raw', 'managed'] as $mode) {
            foreach ($attempts as $attempt) {
                $before = $this->snapshotOrganizations();
                $operation = function () use ($attempt): void {
                    try { $attempt(); self::fail('Audit failure swallowed'); } catch (\PDOException $e) { self::assertStringContainsString('audit failure', $e->getMessage()); }
                };
                if ($mode === 'standalone') { $operation(); }
                elseif ($mode === 'raw') {
                    $this->db->getPdo()->beginTransaction();
                    try { $operation(); self::assertTrue($this->db->getPdo()->inTransaction()); } finally { $this->db->getPdo()->rollBack(); }
                } else { $this->db->transaction(function () use ($operation): void { $operation(); self::assertTrue($this->db->getPdo()->inTransaction()); }); }
                self::assertSame($before, $this->snapshotOrganizations());
            }
        }
    }
    public function testMissingSchemaInsideCallerDoesNotExecuteDdlOrCommit(): void
    {
        $service = new OrganizationService($this->db); $this->db->getPdo()->beginTransaction();
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            try { $service->createOrganization(1, 'Name', 'name'); self::fail('Runtime schema created inside caller'); } catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testSessionHandlerOnAnotherConnectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrganizationService($this->db, new DatabaseSessionHandler(new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'), 'sqlite')));
    }

    public function testChildInsertFailuresRestoreAggregateWithoutLosingCaller(): void
    {
        [$service, , $org] = $this->organizations(); $token = $service->inviteMember(1, $org, 'member@example.test');
        foreach (['organization_members', 'organization_invitations'] as $table) {
            $this->db->statement($this->db->getDriverName() === 'mysql'
                ? "CREATE TRIGGER reject_org_child BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'child failure'"
                : "CREATE TRIGGER reject_org_child BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'child failure'); END");
            $operations = $table === 'organization_members'
                ? [fn() => $service->createOrganization(3, 'Other', 'other'), fn() => $service->acceptInvitation(2, 'member@example.test', $token)]
                : [fn() => $service->inviteMember(1, $org, 'member@example.test', 'billing')];
            foreach ($operations as $operation) {
                $before = $this->snapshotOrganizations(); $this->db->getPdo()->beginTransaction();
                try {
                    try { $operation(); self::fail('Child failure swallowed'); } catch (\PDOException $e) { self::assertStringContainsString('child failure', $e->getMessage()); }
                    self::assertTrue($this->db->getPdo()->inTransaction()); self::assertSame($before, $this->snapshotOrganizations());
                } finally { $this->db->getPdo()->rollBack(); }
                self::assertSame($before, $this->snapshotOrganizations());
            }
            $this->db->statement('DROP TRIGGER reject_org_child');
        }
    }
    public function testExplicitNullOrSuspendedUserStatusCannotAuthorizeEvenWithActiveFlag(): void
    {
        [$service, , $org] = $this->organizations();
        $this->db->statement("ALTER TABLE users ADD COLUMN status VARCHAR(50) NULL DEFAULT 'active'");
        foreach ([null, 'suspended'] as $status) {
            $this->db->update('users', ['status' => $status], 'id = 1');
            try { $service->inviteMember(1, $org, 'member@example.test'); self::fail('Inconsistent user state accepted'); } catch (ValidationException) { self::assertSame([], $this->db->select('SELECT * FROM organization_invitations')); }
        }
    }
}
