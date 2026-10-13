<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Identity\Auth\AuthService;
use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Domain\Identity\TwoFactor\{TwoFactorService, TotpEngine};
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

trait IdentityLifecycleCases
{
    private function identityFixture(): array
    {
        $sessions = new DatabaseSessionHandler($this->db); $hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $auth = new AuthService($this->db, $hasher, $sessions); $id = $auth->register('fixture@example.test', 'Original123!', 'Fixture');
        $sessions->write('original-session', ['user_id' => $id], $id);
        $totp = new TotpEngine(); $two = new TwoFactorService($this->db, $totp, $hasher); $two->ensureTables();
        return [$auth, $sessions, $id, $two, $totp];
    }
    public function testInstalledIdentitySetupDoesNotCommitCallerOrExecuteDdl(): void
    {
        [$auth, , , $two] = $this->identityFixture(); $this->db->getPdo()->beginTransaction();
        try {
            $this->db->enableQueryLog(); $this->db->flushQueryLog(); $auth->ensureTables(); $two->ensureTables();
            self::assertTrue($this->db->getPdo()->inTransaction());
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
    }
    public function testActiveTwoFactorCannotBeDisabledByRestartingSetup(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $codes = $two->confirmSetup($id, $totp->generateCode($setup['secret']));
        $before = $this->db->selectOne('SELECT * FROM user_two_factor');
        try { $two->beginSetup($id, 'fixture@example.test'); self::fail('Active 2FA disabled'); }
        catch (ValidationException) { self::assertSame($before, $this->db->selectOne('SELECT * FROM user_two_factor')); self::assertTrue($two->redeemRecoveryCode($id, $codes[0])); }
    }
    public function testRepeatedConfirmationDoesNotReplaceRecoveryCodes(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $code = $totp->generateCode($setup['secret']); $two->confirmSetup($id, $code);
        $before = $this->db->select('SELECT * FROM user_recovery_codes ORDER BY id');
        try { $two->confirmSetup($id, $code); self::fail('Enabled setup reconfirmed'); }
        catch (ValidationException) { self::assertSame($before, $this->db->select('SELECT * FROM user_recovery_codes ORDER BY id')); }
    }
    public function testRevokedSessionCannotBeResurrectedByOldWriterAfterGc(): void
    {
        [$auth, $sessions, $id] = $this->identityFixture(); $token = $auth->createPasswordResetToken('fixture@example.test'); $auth->resetPassword('fixture@example.test', $token, 'Replacement123!'); $sessions->gc();
        try { (new DatabaseSessionHandler($this->db))->write('original-session', ['user_id' => $id], $id); self::fail('Revoked session resurrected'); }
        catch (ValidationException) { self::assertSame([], $sessions->read('original-session')); }
        $sessions->write('new-session', ['user_id' => $id], $id); self::assertNotEmpty($sessions->read('new-session'));
    }
    public function testResetInsideRawCallerPreservesRollbackBoundary(): void
    {
        [$auth, $sessions] = $this->identityFixture(); $token = $auth->createPasswordResetToken('fixture@example.test');
        $this->db->getPdo()->beginTransaction();
        try { $auth->resetPassword('fixture@example.test', $token, 'Replacement123!'); self::assertTrue($this->db->getPdo()->inTransaction()); self::assertSame([], $sessions->read('original-session')); }
        finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertNotEmpty($auth->authenticate('fixture@example.test', 'Original123!')); self::assertNotEmpty($sessions->read('original-session'));
    }
    public function testExpiredResetAtExactBoundaryCannotChangePassword(): void
    {
        [$auth] = $this->identityFixture(); $token = $auth->createPasswordResetToken('fixture@example.test'); $this->db->statement('UPDATE password_resets SET expires_at = ?', [time()]);
        $this->expectException(ValidationException::class); $auth->resetPassword('fixture@example.test', $token, 'Replacement123!');
    }
    public function testFailedResetTokenReplacementPreservesOldToken(): void
    {
        [$auth] = $this->identityFixture(); $auth->createPasswordResetToken('fixture@example.test'); $before = $this->db->select('SELECT * FROM password_resets');
        $this->failInsert('password_resets');
        try { $auth->createPasswordResetToken('fixture@example.test'); self::fail('Insert failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->db->select('SELECT * FROM password_resets')); }
    }
    private function failInsert(string $table): void
    {
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fail_identity_insert BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'"
            : "CREATE TRIGGER fail_identity_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    }
    public static function identityAggregates(): array
    {
        $cases = []; foreach (['reset', 'verify', 'confirm', 'replaceVerification'] as $op) { foreach (['standalone', 'raw', 'managed'] as $caller) { $cases[$op . ':' . $caller] = [$op, $caller]; } } return $cases;
    }
    private function aggregateFixture(string $operation): array
    {
        [$auth, $sessions, $id, $two, $totp] = $this->identityFixture();
        $callback = match ($operation) {
            'reset' => (function () use ($auth): callable { $token = $auth->createPasswordResetToken('fixture@example.test'); return fn() => $auth->resetPassword('fixture@example.test', $token, 'Replacement123!'); })(),
            'verify' => (function () use ($auth, $id): callable { $token = $auth->createEmailVerificationToken($id); return fn() => $auth->verifyEmail($id, $token); })(),
            'confirm' => (function () use ($two, $totp, $id): callable { $setup = $two->beginSetup($id, 'fixture@example.test'); $code = $totp->generateCode($setup['secret']); return fn() => $two->confirmSetup($id, $code); })(),
            'replaceVerification' => (function () use ($auth, $id): callable { $auth->createEmailVerificationToken($id); return fn() => $auth->createEmailVerificationToken($id); })(),
        };
        return [$callback, $this->identityRows()];
    }
    private function identityRows(): array
    {
        $rows = []; foreach (['users', 'sessions', 'password_resets', 'email_verifications', 'user_two_factor', 'user_recovery_codes', 'user_trusted_devices', 'session_revocations'] as $table) { $rows[$table] = $this->db->select('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'session_revocations' ? 'session_id' : 'id')); } return $rows;
    }
    #[DataProvider('identityAggregates')]
    public function testChildFailureKeepsAggregateAndUnrelatedCallerWrites(string $operation, string $caller): void
    {
        [$callback, $before] = $this->aggregateFixture($operation); $this->db->statement('CREATE TABLE identity_caller_marker (id INT PRIMARY KEY)');
        if ($operation === 'confirm') {
            $this->db->statement($this->db->getDriverName() === 'mysql'
                ? "CREATE TRIGGER fail_identity_insert BEFORE INSERT ON user_recovery_codes FOR EACH ROW BEGIN IF (SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = NEW.user_id) > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'second code failure'; END IF; END"
                : "CREATE TRIGGER fail_identity_insert BEFORE INSERT ON user_recovery_codes WHEN (SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = NEW.user_id) > 0 BEGIN SELECT RAISE(ABORT, 'second code failure'); END");
        } elseif ($operation === 'replaceVerification') { $this->failInsert('email_verifications'); }
        else {
            $table = $operation === 'reset' ? 'sessions' : 'email_verifications';
            $this->db->statement($this->db->getDriverName() === 'mysql'
                ? "CREATE TRIGGER fail_identity_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'delete failure'"
                : "CREATE TRIGGER fail_identity_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'delete failure'); END");
        }
        if ($caller === 'raw') { $this->db->getPdo()->beginTransaction(); }
        if ($caller === 'managed') { $this->db->beginTransaction(); }
        if ($caller !== 'standalone') { $this->db->insert('identity_caller_marker', ['id' => 1]); }
        try {
            try { $callback(); self::fail('Child failure ignored'); }
            catch (\PDOException) {
                self::assertSame($before, $this->identityRows()); self::assertSame($caller !== 'standalone', $this->db->getPdo()->inTransaction());
                if ($caller !== 'standalone') { self::assertSame(1, (int) $this->db->selectOne('SELECT id FROM identity_caller_marker')['id']); }
            }
            if ($caller === 'raw') { $this->db->getPdo()->commit(); }
            if ($caller === 'managed') { $this->db->commit(); }
        } finally {
            if ($this->db->getPdo()->inTransaction()) { if ($caller === 'managed') { $this->db->rollBack(); } else { $this->db->getPdo()->rollBack(); } }
        }
        self::assertSame($before, $this->identityRows());
    }
    public static function successfulCallerAggregates(): array
    {
        $cases = []; foreach (['reset', 'verify', 'confirm'] as $op) { foreach (['raw', 'managed'] as $caller) { $cases[$op . ':' . $caller] = [$op, $caller]; } } return $cases;
    }
    #[DataProvider('successfulCallerAggregates')]
    public function testSuccessfulAggregateStaysInsideCallerRollback(string $operation, string $caller): void
    {
        [$callback, $before] = $this->aggregateFixture($operation);
        if ($caller === 'raw') { $this->db->getPdo()->beginTransaction(); } else { $this->db->beginTransaction(); }
        try { $callback(); self::assertTrue($this->db->getPdo()->inTransaction()); self::assertNotSame($before, $this->identityRows()); }
        finally { if ($this->db->getPdo()->inTransaction()) { if ($caller === 'raw') { $this->db->getPdo()->rollBack(); } else { $this->db->rollBack(); } } }
        self::assertSame($before, $this->identityRows());
    }
    public function testLoginTotpCounterCanBeUsedOnlyOnce(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $code = $totp->generateCode($setup['secret']); $two->confirmSetup($id, $code);
        self::assertTrue($two->verifyLoginCode($id, $code)); self::assertFalse($two->verifyLoginCode($id, $code)); self::assertCount(1, $this->db->select('SELECT * FROM two_factor_login_steps'));
    }
    public function testPasswordResetInvalidatesPreviouslyTrustedDevice(): void
    {
        [$auth, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $two->confirmSetup($id, $totp->generateCode($setup['secret'])); $device = $two->trustDevice($id);
        self::assertTrue($two->isDeviceTrusted($id, $device)); $token = $auth->createPasswordResetToken('fixture@example.test'); $auth->resetPassword('fixture@example.test', $token, 'Replacement123!');
        self::assertFalse($two->isDeviceTrusted($id, $device)); self::assertTrue($two->isEnabled($id));
    }
    public function testInactiveTwoFactorCannotIssueTrustedDevice(): void
    {
        [, , $id, $two] = $this->identityFixture(); $this->expectException(ValidationException::class); $two->trustDevice($id);
    }
    public function testUnknownAccountResetRequestCreatesNoOrphanToken(): void
    {
        [$auth] = $this->identityFixture(); self::assertSame(64, strlen($auth->createPasswordResetToken('unknown@example.test'))); self::assertSame([], $this->db->select('SELECT * FROM password_resets'));
    }
    public function testDuplicateLegacyResetTokenIsRefusedWithoutPasswordChange(): void
    {
        [$auth] = $this->identityFixture(); $token = $auth->createPasswordResetToken('fixture@example.test'); $row = $this->db->selectOne('SELECT * FROM password_resets'); unset($row['id']); $this->db->insert('password_resets', $row); $before = $this->identityRows();
        try { $auth->resetPassword('fixture@example.test', $token, 'Replacement123!'); self::fail('Duplicate token accepted'); }
        catch (ValidationException) { self::assertSame($before, $this->identityRows()); }
    }
    public function testRevocationLedgerFailureKeepsLiveSession(): void
    {
        [, $sessions] = $this->identityFixture(); $before = $this->identityRows(); $this->failInsert('session_revocations');
        try { $sessions->destroy('original-session'); self::fail('Revocation failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->identityRows()); self::assertNotEmpty($sessions->read('original-session')); }
    }
    public function testDestroyAndGcNeverReuseRevokedId(): void
    {
        [, $sessions, $id] = $this->identityFixture(); $sessions->destroy('original-session'); $sessions->gc();
        self::assertCount(1, $this->db->select('SELECT * FROM session_revocations')); self::assertSame([], $sessions->read('original-session'));
        $this->expectException(ValidationException::class); $sessions->write('original-session', ['user_id' => $id], $id);
    }
    public function testMigration26ReplayPreservesSessionsRevocationsAndLoginSteps(): void
    {
        (new DatabaseSessionHandler($this->db))->ensureTable();
        $this->db->insert('sessions', ['id' => 'legacy', 'user_id' => 7, 'ip_address' => null, 'user_agent' => null, 'payload' => '{"note":"Önceki"}', 'last_activity' => time()]); $before = $this->db->select('SELECT * FROM sessions');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_13_000026_identity_lifecycle.php'; $migration->up($this->db);
        $this->db->insert('session_revocations', ['session_id' => 'revoked', 'revoked_at' => 1234]); $this->db->insert('two_factor_login_steps', ['user_id' => 7, 'last_used_step' => 1234]);
        $migration->up($this->db); self::assertSame($before, $this->db->select('SELECT * FROM sessions')); self::assertCount(1, $this->db->select('SELECT * FROM session_revocations')); self::assertCount(1, $this->db->select('SELECT * FROM two_factor_login_steps'));
        try { $migration->down($this->db); self::fail('Unsafe rollback accepted'); }
        catch (\RuntimeException) { self::assertCount(1, $this->db->select('SELECT * FROM session_revocations')); }
    }
    public function testPartialUpgradeRefusesBeforeCreatingSiblingTables(): void
    {
        $this->db->statement('CREATE TABLE two_factor_login_steps (user_id INT PRIMARY KEY)'); $this->db->insert('two_factor_login_steps', ['user_id' => 7]); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_13_000026_identity_lifecycle.php';
        try { $migration->up($this->db); self::fail('Partial login schema accepted'); }
        catch (\LogicException) { self::assertSame(7, (int) $this->db->selectOne('SELECT user_id FROM two_factor_login_steps')['user_id']); }
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
    }
    public function testDuplicatePreviouslyUsedRecoveryHashCannotBeReused(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $codes = $two->confirmSetup($id, $totp->generateCode($setup['secret'])); self::assertTrue($two->redeemRecoveryCode($id, $codes[0]));
        $row = $this->db->selectOne('SELECT * FROM user_recovery_codes WHERE code_hash = ?', [hash('sha256', $codes[0])]); unset($row['id']); $row['used_at'] = null; $this->db->insert('user_recovery_codes', $row);
        self::assertFalse($two->redeemRecoveryCode($id, $codes[0]));
    }
    public function testInvalidPersistedTwoFactorStateNeverSkipsChallenge(): void
    {
        [, , $id, $two] = $this->identityFixture(); $two->beginSetup($id, 'fixture@example.test'); $this->db->statement('UPDATE user_two_factor SET is_enabled = 2');
        $this->expectException(\LogicException::class); $two->verifyLoginCode($id, '123456');
    }
    public function testMissingIdentityBusinessSchemaInsideCallerDoesNotCreateTables(): void
    {
        $auth = new AuthService($this->db, new PasswordHasher(), new DatabaseSessionHandler($this->db)); $this->db->getPdo()->beginTransaction(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            try { $auth->createPasswordResetToken('fixture@example.test'); self::fail('Schema installed in caller'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testLostManagedIdentityCallerRefusesBeforeQuery(): void
    {
        [$auth] = $this->identityFixture(); $this->db->beginTransaction(); $this->db->getPdo()->rollBack(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $auth->authenticate('fixture@example.test', 'Original123!'); self::fail('Lost caller accepted'); }
        catch (\LogicException) { self::assertSame([], $this->db->getQueryLog()); }
    }
    public function testTrustedDeviceExpiresAtExactBoundary(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $two->confirmSetup($id, $totp->generateCode($setup['secret'])); $device = $two->trustDevice($id); $this->db->statement('UPDATE user_trusted_devices SET expires_at = ?', [time()]);
        self::assertFalse($two->isDeviceTrusted($id, $device));
    }
    public function testFailedTotpStepInsertDoesNotConsumeChallenge(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $code = $totp->generateCode($setup['secret']); $two->confirmSetup($id, $code); $this->failInsert('two_factor_login_steps');
        try { $two->verifyLoginCode($id, $code); self::fail('Challenge insert failure ignored'); }
        catch (\PDOException) { self::assertSame([], $this->db->select('SELECT * FROM two_factor_login_steps')); }
        $this->db->statement('DROP TRIGGER fail_identity_insert'); self::assertTrue($two->verifyLoginCode($id, $code));
    }
    public function testMissingInstalledSessionMutexIsNotSilentlyRepaired(): void
    {
        [, $sessions] = $this->identityFixture(); $before = $this->db->select('SELECT * FROM sessions'); $this->db->statement('DELETE FROM session_lifecycle_lock');
        try { $sessions->destroy('original-session'); self::fail('Missing mutex silently repaired'); }
        catch (\LogicException) { self::assertSame($before, $this->db->select('SELECT * FROM sessions')); self::assertSame([], $this->db->select('SELECT * FROM session_lifecycle_lock')); }
    }
    public function testDisabledStatusOverridesActiveFlag(): void
    {
        [$auth] = $this->identityFixture(); $this->db->statement("ALTER TABLE users ADD COLUMN status VARCHAR(50) DEFAULT 'active'"); $this->db->statement("UPDATE users SET status = 'suspended'");
        $this->expectException(ValidationException::class); $auth->authenticate('fixture@example.test', 'Original123!');
    }
    public function testUnexpectedRevocationUniqueRefusesBeforeDeletingSession(): void
    {
        [, $sessions] = $this->identityFixture(); $before = $this->db->select('SELECT * FROM sessions');
        $this->db->statement('CREATE UNIQUE INDEX unexpected_revocation_time ON session_revocations (revoked_at)');
        try { $sessions->destroy('original-session'); self::fail('Unexpected revocation identity accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->db->select('SELECT * FROM sessions')); self::assertSame([], $this->db->select('SELECT * FROM session_revocations')); }
    }
    public function testUnexpectedLoginStepUniqueRefusesBeforeAcceptingChallenge(): void
    {
        [, , $id, $two, $totp] = $this->identityFixture(); $setup = $two->beginSetup($id, 'fixture@example.test'); $code = $totp->generateCode($setup['secret']); $two->confirmSetup($id, $code);
        $this->db->statement('CREATE UNIQUE INDEX unexpected_login_step ON two_factor_login_steps (last_used_step)');
        try { $two->verifyLoginCode($id, $code); self::fail('Unexpected login step identity accepted'); }
        catch (\LogicException) { self::assertSame([], $this->db->select('SELECT * FROM two_factor_login_steps')); }
    }
    public function testInvalidResetTokenIsRejectedBeforeHashingNewPassword(): void
    {
        [$auth] = $this->identityFixture(); $before = $this->identityRows();
        try { $auth->resetPassword('fixture@example.test', 'invalid', "password\0invalid-for-bcrypt"); self::fail('Invalid token accepted'); }
        catch (ValidationException) { self::assertSame($before, $this->identityRows()); }
    }
}
