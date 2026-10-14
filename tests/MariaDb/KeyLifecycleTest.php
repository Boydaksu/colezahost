<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class KeyLifecycleTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\KeyLifecycleCases;
    protected function tearDown(): void { $this->cleanupLifecycleKeys(); parent::tearDown(); }

    public function testRotationSerializesAgainstThreeIndependentSecretWriters(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); [$file, $newKey] = $this->replacementFile();
        $results = $this->runParallel('keyRotationRace', 0, $file);
        $rotations = array_values(array_filter($results, static fn(array $r): bool => $r['kind'] === 'rotate'));
        self::assertCount(1, $rotations); self::assertTrue($rotations[0]['accepted']);
        $accepted = count(array_filter($results, static fn(array $r): bool => $r['kind'] === 'write' && $r['accepted']));
        self::assertSame($newKey->fingerprint(), (new \Coleza\Domain\Vault\KeyLifecycleService($this->db, $newKey))->verify()['key_id']);
        $audits = $this->db->select("SELECT * FROM security_audit_logs WHERE target_resource = 'provider:race:token' AND event_type IN ('VAULT_SECRET_CREATED', 'VAULT_SECRET_ROTATED')");
        self::assertCount($accepted, $audits);
        $value = (new \Coleza\Domain\Vault\VaultService($this->db, $newKey))->getSecret('provider:race', 'token');
        $accepted === 0 ? self::assertNull($value) : self::assertStringStartsWith('race-synthetic-', $value);
    }
    public function testFourSeparateProcessesCannotWriteUsingRetiredKey(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); [$file] = $this->replacementFile();
        $service->rotateToFile($file, dirname(__DIR__, 2), true); $before = $this->lifecycleRows();
        $results = $this->runParallel('keyStaleWriter', 0);
        self::assertCount(4, $results);
        self::assertSame(0, count(array_filter($results, static fn(array $r): bool => $r['accepted'])));
        self::assertSame($before, $this->lifecycleRows());
    }

    public function testMaintenanceCliBindsRotatesAndVerifiesAfterProcessRestart(): void
    {
        $this->lifecycleFixture(); [$file] = $this->replacementFile();
        $dsn = getenv('COLEZA_TEST_MARIADB_DSN');
        self::assertSame(1, preg_match('/host=([^;]+);port=([0-9]+)/', $dsn, $matches));
        $environment = array_merge(getenv(), ['DB_HOST' => $matches[1], 'DB_PORT' => $matches[2], 'DB_DATABASE' => $this->database,
            'DB_USERNAME' => getenv('COLEZA_TEST_MARIADB_USER'), 'DB_PASSWORD' => getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            'APP_KEY' => \Coleza\Tests\Support\SecretFixtureKey::configured(), 'APP_KEY_FILE' => '']);
        $run = function (array $arguments) use (&$environment): array {
            $command = [PHP_BINARY, '-c', php_ini_loaded_file(), dirname(__DIR__, 2) . '/tools/application-key-maintenance.php', ...$arguments];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
            self::assertIsResource($process); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($process), $out, $err];
        };
        foreach ([['bind', '--maintenance'], ['verify'], ['rotate', $file, '--maintenance']] as $arguments) {
            [$code, $out, $err] = $run($arguments); self::assertSame(0, $code, $err); self::assertSame('', $err);
            self::assertStringNotContainsString($environment['APP_KEY'], $out);
            self::assertArrayHasKey('key_id', json_decode($out, true, flags: JSON_THROW_ON_ERROR));
        }
        [$code, $out] = $run(['verify']); self::assertSame(1, $code); self::assertSame('', $out);
        $environment['APP_KEY'] = ''; $environment['APP_KEY_FILE'] = $file;
        [$code, $out, $err] = $run(['verify']); self::assertSame(0, $code, $err); self::assertSame('', $err);
        self::assertArrayHasKey('counts', json_decode($out, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testNonTransactionalBindingTableCannotAuthorizeAWriter(): void
    {
        [$service, , $vault] = $this->lifecycleFixture(); $service->initialize(true);
        $this->db->statement('ALTER TABLE system_settings ENGINE=MyISAM'); $before = $this->lifecycleRows();
        $error = null; try { $vault->setSecret('provider:unsafe', 'token', 'unsafe-synthetic-secret'); } catch (\LogicException $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertSame($before, $this->lifecycleRows());
    }
}
