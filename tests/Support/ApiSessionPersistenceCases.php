<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Api\Auth\ApiKeyService;
use Coleza\Api\Logging\ApiRequestLogger;
use Coleza\Api\RateLimit\RateLimiter;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use PHPUnit\Framework\Attributes\DataProvider;

trait ApiSessionPersistenceCases
{
    public static function persistenceServices(): array { return [['key'], ['log'], ['rate'], ['session']]; }
    private function persistenceService(string $name): object
    {
        return match ($name) { 'key' => new ApiKeyService($this->db), 'log' => new ApiRequestLogger($this->db), 'rate' => new RateLimiter($this->db), 'session' => new DatabaseSessionHandler($this->db) };
    }
    #[DataProvider('persistenceServices')]
    public function testInstalledSetupPreservesCallerAndExecutesNoDdl(string $name): void
    {
        $service = $this->persistenceService($name); $service->ensureTable();
        $this->db->statement('CREATE TABLE api_caller_marker (id INT PRIMARY KEY)');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('api_caller_marker', ['id' => 1]); $this->db->enableQueryLog(); $this->db->flushQueryLog();
            $service->ensureTable(); self::assertTrue($this->db->getPdo()->inTransaction());
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM api_caller_marker'));
    }
    public function testInvalidSessionJsonPreservesPreviousPayload(): void
    {
        $handler = new DatabaseSessionHandler($this->db); $handler->write('valid', ['before' => true], 5);
        try { $handler->write('valid', ['bad' => "\xFF"]); self::fail('Invalid UTF-8 persisted'); }
        catch (\JsonException) { self::assertSame(['before' => true], $handler->read('valid')); }
    }
    public function testSessionUpdatePreservesMetadataAndCallerRollback(): void
    {
        $handler = new DatabaseSessionHandler($this->db); $handler->write('valid', ['before' => true], 5, '192.0.2.1', 'Browser');
        $this->db->getPdo()->beginTransaction();
        try {
            $handler->write('valid', ['after' => true]); $row = $this->db->selectOne('SELECT * FROM sessions');
            self::assertSame(5, (int) $row['user_id']); self::assertSame('192.0.2.1', $row['ip_address']); self::assertSame('Browser', $row['user_agent']);
            self::assertSame(['after' => true], $handler->read('valid')); self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame(['before' => true], $handler->read('valid'));
    }
    public static function malformedScopes(): array { return [['"*"'], ['{}'], ['{bad'], ['[true]'], ['[" "]']]; }
    #[DataProvider('malformedScopes')]
    public function testMalformedPersistedScopesCannotAuthenticateWithoutRequiredScope(string $scopes): void
    {
        $keys = new ApiKeyService($this->db); $key = $keys->createKey(1, 'Fixture');
        $this->db->statement('UPDATE api_keys SET scopes = ?', [$scopes]);
        $this->expectException(\Coleza\Foundation\Exceptions\ValidationException::class); $keys->authenticate($key['plain_key']);
    }
    public function testInvalidRatePolicyDoesNotConsumeQuota(): void
    {
        $limiter = new RateLimiter($this->db); $limiter->ensureTable();
        try { $limiter->hit('fixture', 0, 60); self::fail('Zero budget accepted'); }
        catch (\InvalidArgumentException) { self::assertSame([], $this->db->select('SELECT * FROM api_rate_limits')); }
    }
    public function testRateAdmissionRejectsCallerBeforeBudgetWrite(): void
    {
        $limiter = new RateLimiter($this->db); $limiter->ensureTable(); $this->db->getPdo()->beginTransaction();
        try {
            try { $limiter->hit('fixture'); self::fail('Caller can roll back quota'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); self::assertSame([], $this->db->select('SELECT * FROM api_rate_limits')); }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
    }
    public function testExpiredWindowResetsInPlaceAndRejectedAttemptIsPersisted(): void
    {
        $limiter = new RateLimiter($this->db); $limiter->hit('fixture', 2);
        $this->db->statement('UPDATE api_rate_limits SET hits = 100, reset_at = ?', [time() - 1]);
        self::assertSame(1, $limiter->hit('fixture', 2)['remaining']); self::assertSame(0, $limiter->hit('fixture', 2)['remaining']);
        try { $limiter->hit('fixture', 2); self::fail('Over budget accepted'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame(3, (int) $this->db->selectOne('SELECT hits FROM api_rate_limits')['hits']); }
    }
    #[DataProvider('persistenceServices')]
    public function testPartialInstalledSchemaIsNotSilentlyAccepted(string $name): void
    {
        $table = match ($name) { 'key' => 'api_keys', 'log' => 'api_request_logs', 'rate' => 'api_rate_limits', 'session' => 'sessions' };
        $this->db->statement('CREATE TABLE ' . $table . ' (sentinel INT PRIMARY KEY)'); $this->db->insert($table, ['sentinel' => 77]);
        try { $this->persistenceService($name)->ensureTable(); self::fail('Partial schema accepted'); }
        catch (\LogicException) { self::assertSame(77, (int) $this->db->selectOne('SELECT sentinel FROM ' . $table)['sentinel']); }
    }
    public function testUnexpectedUniqueSessionOwnerCannotOverwriteAnotherSession(): void
    {
        $handler = new DatabaseSessionHandler($this->db); $handler->write('first', ['private' => 1], 5);
        $this->db->statement('CREATE UNIQUE INDEX unexpected_session_owner ON sessions (user_id)');
        try { $handler->write('second', ['private' => 2], 5); self::fail('Conflicting identity accepted'); }
        catch (\LogicException) { self::assertSame('first', $this->db->selectOne('SELECT id FROM sessions')['id']); }
        self::assertSame('{"private":1}', $this->db->selectOne('SELECT payload FROM sessions')['payload']);
    }
    public function testSessionStatementFailurePreservesPayloadAndMetadata(): void
    {
        $handler = new DatabaseSessionHandler($this->db); $handler->write('fixture', ['before' => 1], 5, '192.0.2.1', 'Original');
        $before = $this->db->selectOne('SELECT * FROM sessions');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fail_session BEFORE UPDATE ON sessions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'"
            : "CREATE TRIGGER fail_session BEFORE UPDATE ON sessions BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        try { $handler->write('fixture', ['after' => 2], 6, '192.0.2.2', 'Replacement'); self::fail('Trigger failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->db->selectOne('SELECT * FROM sessions')); }
    }
    public function testExpiryAndGarbageCollectionKeepActiveSessions(): void
    {
        $handler = new DatabaseSessionHandler($this->db, 60); $handler->write('expired', ['old' => 1]); $handler->write('active', ['new' => 2]);
        $this->db->statement('UPDATE sessions SET last_activity = ? WHERE id = ?', [time() - 100, 'expired']);
        self::assertSame([], $handler->read('expired')); self::assertSame(['new' => 2], $handler->read('active')); self::assertSame(0, $handler->gc());
    }
    public function testKeyAndLogWritesRemainInsideCallerRollback(): void
    {
        $keys = new ApiKeyService($this->db); $logs = new ApiRequestLogger($this->db); $keys->ensureTable(); $logs->ensureTable();
        $this->db->getPdo()->beginTransaction();
        try { $key = $keys->createKey(5, 'Caller', ['invoices.read']); $keys->authenticate($key['plain_key'], 'invoices.read'); $logs->log('get', '/api/v1/invoices', 200, 5, $key['id']); self::assertTrue($this->db->getPdo()->inTransaction()); }
        finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM api_keys')); self::assertSame([], $this->db->select('SELECT * FROM api_request_logs'));
    }
    public function testFailedWindowResetPreservesPreviousCounter(): void
    {
        $limiter = new RateLimiter($this->db); $limiter->hit('fixture', 2);
        $this->db->statement('UPDATE api_rate_limits SET reset_at = ?', [time() - 1]); $before = $this->db->selectOne('SELECT * FROM api_rate_limits');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fail_rate BEFORE UPDATE ON api_rate_limits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'"
            : "CREATE TRIGGER fail_rate BEFORE UPDATE ON api_rate_limits BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        try { $limiter->hit('fixture', 2); self::fail('Failed reset accepted'); }
        catch (\PDOException) { self::assertSame($before, $this->db->selectOne('SELECT * FROM api_rate_limits')); self::assertFalse($this->db->getPdo()->inTransaction()); }
    }
    public function testApiKeyExpiresAtBoundaryAndBadScopeInputDoesNotCreateKey(): void
    {
        $keys = new ApiKeyService($this->db); $key = $keys->createKey(5, 'Expired', ['*'], time());
        try { $keys->authenticate($key['plain_key']); self::fail('Expired key accepted'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertNull($this->db->selectOne('SELECT last_used_at FROM api_keys')['last_used_at']); }
        try { $keys->createKey(5, 'Malformed', [true]); self::fail('Boolean scope accepted'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertCount(1, $this->db->select('SELECT * FROM api_keys')); }
    }
    #[DataProvider('persistenceServices')]
    public function testLostManagedCallerRefusesBeforeAnyQuery(string $name): void
    {
        $service = $this->persistenceService($name); $service->ensureTable(); $this->db->beginTransaction(); $this->db->getPdo()->rollBack();
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $service->ensureTable(); self::fail('Ended caller reused'); }
        catch (\LogicException) { self::assertSame([], $this->db->getQueryLog()); }
    }
    public function testMissingSessionSchemaInCallerRefusesBeforeDdl(): void
    {
        $this->db->getPdo()->beginTransaction(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            try { (new DatabaseSessionHandler($this->db))->write('fixture', ['payload' => 1]); self::fail('Missing schema bootstrapped in caller'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { $this->db->getPdo()->rollBack(); }
    }
}
