<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
use Coleza\Domain\Identity\Organization\OrganizationService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Exceptions\ValidationException;
final class OrganizationAuthorizationTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\OrganizationAuthorizationCases;
    public function testFourProcessesConsumeInvitationExactlyOnce(): void
    {
        [$service, , $org] = $this->organizations(); $token = $service->inviteMember(1, $org, 'member@example.test');
        $results = $this->runParallel('organizationAccept', 2, $token);
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
        self::assertCount(1, array_filter($results, static fn(array $r): bool => ($r['accepted'] ?? false) === true));
        self::assertCount(1, $this->db->select('SELECT * FROM organization_members WHERE user_id = 2'));
        self::assertCount(1, $this->db->select("SELECT * FROM security_audit_logs WHERE event_type = 'organization.invitation_accepted'"));
    }
    public function testFourConcurrentReplacementsLeaveExactlyOneUsableInvitation(): void
    {
        [$service, , $org] = $this->organizations(); $results = $this->runParallel('organizationInvite', $org);
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        $rows = $this->db->select('SELECT * FROM organization_invitations'); self::assertCount(1, $rows);
        $accepted = 0;
        foreach ($results as $result) {
            try { $service->acceptInvitation(2, 'member@example.test', $result['token']); $accepted++; } catch (ValidationException) { }
        }
        self::assertSame(1, $accepted);
    }
    private function peerOrganizationService(): OrganizationService
    {
        $db = new \Coleza\Foundation\Database\Connection(new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN') . ';dbname=' . $this->database, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD')));
        return new OrganizationService($db, new DatabaseSessionHandler($db));
    }
    public function testStaleCallerCannotReuseConsumedInvitation(): void
    {
        [$service, , $org] = $this->organizations(); $token = $service->inviteMember(1, $org, 'member@example.test'); $peer = $this->peerOrganizationService();
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertNull($this->db->selectOne('SELECT accepted_at FROM organization_invitations')['accepted_at']);
            $peer->acceptInvitation(2, 'member@example.test', $token);
            try { $service->acceptInvitation(2, 'member@example.test', $token); self::fail('Stale consume accepted'); } catch (ValidationException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertCount(1, $this->db->select('SELECT * FROM organization_members WHERE user_id = 2'));
    }
    public function testStaleCallerCannotManageAfterDemotionOrResolveRemovedMembership(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 4, 'admin'); $this->join($service, $org, 2);
        $service->switchOrganization('member-session', 2, $org); $peer = $this->peerOrganizationService();
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertSame('admin', $this->db->selectOne('SELECT role FROM organization_members WHERE user_id = 4')['role']);
            $peer->changeMemberRole(1, $org, 4, 'member'); $peer->removeMember(1, $org, 2);
            try { $service->inviteMember(4, $org, 'attacker@example.test'); self::fail('Stale admin authorized'); } catch (ValidationException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
            try { $service->resolveActiveOrganization('member-session', 2); self::fail('Removed member resolved'); } catch (ValidationException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testNonTransactionalAggregateTableRefusesBeforeEffects(): void
    {
        [$service, , $org] = $this->organizations();
        foreach (['organizations', 'organization_members', 'organization_invitations', 'security_audit_logs', 'users'] as $table) {
            $this->db->statement("ALTER TABLE {$table} ENGINE=MyISAM"); $before = $this->snapshotOrganizations();
            try { $service->inviteMember(1, $org, 'member@example.test'); self::fail('Nontransactional aggregate accepted'); } catch (\LogicException) { self::assertSame($before, $this->snapshotOrganizations()); }
            $this->db->statement("ALTER TABLE {$table} ENGINE=InnoDB");
        }
    }
    public function testMissingCompositeMembershipIdentityRefusesBeforeEffects(): void
    {
        [$service, , $org] = $this->organizations(); $this->db->statement('ALTER TABLE organization_members DROP INDEX organization_id'); $before = $this->snapshotOrganizations();
        try { $service->inviteMember(1, $org, 'member@example.test'); self::fail('Missing membership identity accepted'); } catch (\LogicException) { self::assertSame($before, $this->snapshotOrganizations()); }
    }
    public function testInstalledSchemaInsideCallerExecutesNoDdlAndKeepsRollback(): void
    {
        [$service] = $this->organizations(); $this->db->getPdo()->beginTransaction(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            $service->ensureTables(); self::assertTrue($this->db->getPdo()->inTransaction());
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { $this->db->getPdo()->rollBack(); }
    }

    public function testConcurrentDemotionAndInvitesCannotLeaveDelegatedGrant(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 4, 'admin');
        $results = $this->runParallel('organizationDemoteInvite', $org);
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
        self::assertSame('member', $this->db->selectOne('SELECT role FROM organization_members WHERE user_id = 4')['role']);
        self::assertSame([], $this->db->select('SELECT * FROM organization_invitations WHERE accepted_at IS NULL'));
    }
    public function testConcurrentRemovalAndSelectionCannotRetainMembershipAuthority(): void
    {
        [$service, , $org] = $this->organizations(); $this->join($service, $org, 2);
        $results = $this->runParallel('organizationRemoveSelect', $org);
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
        self::assertSame([], $service->getUserOrganizations(2));
        try {
            self::assertNull($service->resolveActiveOrganization('member-session', 2));
        } catch (ValidationException) { self::assertSame([], $this->db->select('SELECT * FROM organization_members WHERE user_id = 2')); }
    }
}
