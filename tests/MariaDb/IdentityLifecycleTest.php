<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class IdentityLifecycleTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\IdentityLifecycleCases;
    public static function oneTimeActions(): array { return [['identityReset'], ['identityVerify'], ['identityRecovery'], ['identityLogin'], ['identityConfirm']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('oneTimeActions')]
    public function testFourProcessesConsumeExactlyOneChallenge(string $action): void
    {
        [$auth, , $id, $two, $totp] = $this->identityFixture();
        if ($action === 'identityReset') { $token = $auth->createPasswordResetToken('fixture@example.test'); }
        elseif ($action === 'identityVerify') { $token = $auth->createEmailVerificationToken($id); }
        else {
            $setup = $two->beginSetup($id, 'fixture@example.test'); $token = $totp->generateCode($setup['secret']);
            if ($action !== 'identityConfirm') { $codes = $two->confirmSetup($id, $token); if ($action === 'identityRecovery') { $token = $codes[0]; } }
        }
        $results = $this->runParallel($action, $id, $token);
        self::assertCount(1, array_filter($results, static fn(array $r): bool => ($r['accepted'] ?? false) === true));
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
        if ($action === 'identityReset') { self::assertSame([], $this->db->select('SELECT * FROM password_resets')); self::assertSame([], $this->db->select('SELECT * FROM sessions')); }
        if ($action === 'identityConfirm') { self::assertCount(8, $this->db->select('SELECT * FROM user_recovery_codes')); }
        if ($action === 'identityRecovery') { self::assertCount(1, $this->db->select('SELECT * FROM user_recovery_codes WHERE used_at IS NOT NULL')); }
        if ($action === 'identityLogin') { self::assertCount(1, $this->db->select('SELECT * FROM two_factor_login_steps')); }
        if ($action === 'identityVerify') { self::assertNotNull($this->db->selectOne('SELECT email_verified_at FROM users')['email_verified_at']); }
    }
    public function testParallelTokenReplacementLeavesOneCurrentToken(): void
    {
        [, , $id] = $this->identityFixture(); $results = $this->runParallel('identityReplaceReset', $id);
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        $rows = $this->db->select('SELECT * FROM password_resets'); self::assertCount(1, $rows);
        self::assertContains($rows[0]['token_hash'], array_map(static fn(array $r): string => hash('sha256', $r['token']), $results));
    }
    public function testParallelResetAndOldSessionWriterEndRevoked(): void
    {
        [$auth, $sessions, $id] = $this->identityFixture(); $token = $auth->createPasswordResetToken('fixture@example.test');
        $results = $this->runParallel('identityResetAndSessionWrite', $id, $token);
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
        self::assertSame([], $sessions->read('original-session')); self::assertCount(1, $this->db->select('SELECT * FROM session_revocations'));
        self::assertNotEmpty($auth->authenticate('fixture@example.test', 'ConcurrentReplacement123!'));
        $this->expectException(\Coleza\Foundation\Exceptions\ValidationException::class); $sessions->write('original-session', ['user_id' => $id], $id);
    }
    private function peerIdentityConnection(): \Coleza\Foundation\Database\Connection
    {
        return new \Coleza\Foundation\Database\Connection(new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN') . ';dbname=' . $this->database, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD')));
    }
    public function testStaleCallerCannotRehashOldPasswordOverPeerReset(): void
    {
        [$auth] = $this->identityFixture(); $token = $auth->createPasswordResetToken('fixture@example.test'); $other = $this->peerIdentityConnection();
        $auth = new \Coleza\Domain\Identity\Auth\AuthService($this->db, new \Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]), new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db));
        $peer = new \Coleza\Domain\Identity\Auth\AuthService($other, new \Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]), new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($other));
        $this->db->getPdo()->beginTransaction();
        try {
            $old = $this->db->selectOne('SELECT password_hash FROM users')['password_hash']; $peer->resetPassword('fixture@example.test', $token, 'Replacement123!');
            self::assertSame($old, $this->db->selectOne('SELECT password_hash FROM users')['password_hash']);
            try { $auth->authenticate('fixture@example.test', 'Original123!'); self::fail('Stale password accepted'); }
            catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertNotEmpty($auth->authenticate('fixture@example.test', 'Replacement123!'));
    }
    public function testStaleCallerCannotRedeemPeerConsumedRecoveryCode(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $codes = $two->confirmSetup($id, $totp->generateCode($setup['secret']));
        $peer = new \Coleza\Domain\Identity\TwoFactor\TwoFactorService($this->peerIdentityConnection(), new \Coleza\Domain\Identity\TwoFactor\TotpEngine(), new \Coleza\Domain\Identity\Security\PasswordHasher(), \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertCount(8, $this->db->select('SELECT id FROM user_recovery_codes WHERE used_at IS NULL'));
            self::assertTrue($peer->redeemRecoveryCode($id, $codes[0])); self::assertFalse($two->redeemRecoveryCode($id, $codes[0]));
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertFalse($two->redeemRecoveryCode($id, $codes[0]));
    }
    public function testNonTransactionalRevocationLedgerRefusesBeforeSessionDelete(): void
    {
        [, $sessions] = $this->identityFixture(); $before = $this->db->select('SELECT * FROM sessions'); $this->db->statement('ALTER TABLE session_revocations ENGINE=MyISAM');
        try { $sessions->destroy('original-session'); self::fail('MyISAM revocation accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->db->select('SELECT * FROM sessions')); }
    }
    public function testMissingEmailUniqueRefusesBeforeIdentityWrites(): void
    {
        [$auth] = $this->identityFixture(); $this->db->statement('ALTER TABLE users DROP INDEX email');
        try { $auth->createPasswordResetToken('fixture@example.test'); self::fail('Unconstrained identity accepted'); }
        catch (\LogicException) { self::assertSame([], $this->db->select('SELECT * FROM password_resets')); }
    }
    public function testMissingMigration26InRawCallerDoesNotCreateAnyTable(): void
    {
        (new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db))->ensureTable(); $this->db->getPdo()->beginTransaction(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_13_000026_identity_lifecycle.php';
            try { $migration->up($this->db); self::fail('DDL installed in raw caller'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testConcurrentRegistrationCreatesOneUserAndStructuredDuplicateRefusals(): void
    {
        $auth = new \Coleza\Domain\Identity\Auth\AuthService($this->db, new \Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]), new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db)); $auth->ensureTables();
        $results = $this->runParallel('identityRegister', 0);
        self::assertCount(1, array_filter($results, static fn(array $r): bool => ($r['accepted'] ?? false) === true));
        foreach ($results as $result) { self::assertContains($result['status'], ['ok', 'rejected']); }
        self::assertCount(1, $this->db->select('SELECT * FROM users'));
    }
}
