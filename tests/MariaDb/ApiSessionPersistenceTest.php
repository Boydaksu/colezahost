<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ApiSessionPersistenceTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ApiSessionPersistenceCases;
    public static function rateWindows(): array { return [['new', 2, 2], ['last', 3, 1], ['expired', 2, 2]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('rateWindows')]
    public function testConcurrentAdmissionUsesExactBudget(string $window, int $budget, int $accepted): void
    {
        $limiter = new \Coleza\Api\RateLimit\RateLimiter($this->db); $limiter->ensureTable();
        if ($window === 'last') { $limiter->hit('parallel', $budget); $limiter->hit('parallel', $budget); }
        if ($window === 'expired') { $this->db->insert('api_rate_limits', ['rate_key' => 'parallel', 'hits' => 100, 'reset_at' => time() - 1]); }
        $results = $this->runParallel('apiRateAdmission', $budget);
        self::assertCount($accepted, array_filter($results, static fn(array $r): bool => $r['status'] === 'ok'));
        foreach ($results as $result) { if ($result['status'] !== 'ok') { self::assertSame('Coleza\\Foundation\\Exceptions\\ValidationException', $result['class']); } }
        self::assertSame($window === 'last' ? 6 : 4, (int) $this->db->selectOne('SELECT hits FROM api_rate_limits')['hits']);
    }
    public function testConcurrentFirstSessionWritesStoreOneCompletePayload(): void
    {
        (new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db))->ensureTable();
        $results = $this->runParallel('apiSessionWrite', 1);
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        $rows = $this->db->select('SELECT * FROM sessions'); self::assertCount(1, $rows);
        $payload = json_decode($rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $rows[0]['user_id'], $payload['worker']); self::assertSame('Worker ' . $payload['worker'], $rows[0]['user_agent']);
    }
    public function testStaleExpiredSessionReadCannotDeletePeerRenewal(): void
    {
        $handler = new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db, 60); $handler->write('fixture', ['old' => 1]);
        $this->db->statement('UPDATE sessions SET last_activity = ?', [time() - 100]);
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertSame('{"old":1}', $this->db->selectOne('SELECT payload FROM sessions')['payload']);
            $other = new \Coleza\Foundation\Database\Connection(new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN') . ';dbname=' . $this->database, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD')));
            (new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($other, 60))->write('fixture', ['renewed' => 2]);
            $handler->read('fixture'); self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertSame(['renewed' => 2], $handler->read('fixture'));
    }
    public function testNonTransactionalRateTableIsRefusedBeforeWrite(): void
    {
        $limiter = new \Coleza\Api\RateLimit\RateLimiter($this->db); $limiter->ensureTable(); $this->db->statement('ALTER TABLE api_rate_limits ENGINE=MyISAM');
        $this->expectException(\LogicException::class); $limiter->hit('fixture');
    }
    public function testMissingApiSchemaCannotInstallInsideRawCaller(): void
    {
        $this->db->getPdo()->beginTransaction();
        try {
            try { (new \Coleza\Api\Auth\ApiKeyService($this->db))->ensureTable(); self::fail('Missing table installed'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); self::assertNull($this->db->selectOne("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'api_keys'")); }
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testMissingUniqueHashIsRefusedBeforeAuthentication(): void
    {
        $keys = new \Coleza\Api\Auth\ApiKeyService($this->db); $key = $keys->createKey(5, 'Fixture');
        $this->db->statement('ALTER TABLE api_keys DROP INDEX key_hash');
        try { $keys->authenticate($key['plain_key']); self::fail('Unconstrained hash accepted'); }
        catch (\LogicException) { self::assertNull($this->db->selectOne('SELECT last_used_at FROM api_keys')['last_used_at']); }
    }
}
