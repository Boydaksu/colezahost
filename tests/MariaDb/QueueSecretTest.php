<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class QueueSecretTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\QueueSecretCases;
    public function testNonTransactionalQueueCannotCreateAnOrphanVaultPayload(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage(); $this->db->statement('ALTER TABLE failed_jobs ENGINE=MyISAM'); $before = $this->queueRows();
        try { $queue->push(new \Coleza\Tests\Support\QueueSecretJob()); self::fail('Nontransactional queue accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->queueRows()); }
    }
    public function testCachedQueueWithOldParentSnapshotRejectsTheCurrentKeyBinding(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage();
        $this->db->statement('CREATE TABLE system_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(128) UNIQUE, setting_value TEXT, is_encrypted INT) ENGINE=InnoDB');
        $binding = \Coleza\Domain\Vault\KeyBinding::class;
        $this->db->insert('system_settings', ['setting_key' => $binding::SETTING, 'setting_value' => (new \Coleza\Domain\Vault\SecretCipher(\Coleza\Tests\Support\SecretFixtureKey::encryptor()))->seal($binding::PROBE, $binding::SCOPE), 'is_encrypted' => 1]);
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('USE `' . $this->database . '`'); $peer = new \Coleza\Foundation\Database\Connection($pdo);
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM system_settings');
            $replacement = \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate());
            $peer->statement('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?', [(new \Coleza\Domain\Vault\SecretCipher($replacement))->seal($binding::PROBE, $binding::SCOPE), $binding::SETTING]);
            try { $queue->push(new \Coleza\Tests\Support\QueueSecretJob()); self::fail('Old snapshot writer accepted'); }
            catch (\RuntimeException) { self::assertTrue($this->db->inTransaction()); }
        } finally { $this->db->rollBack(); }
        self::assertSame(0, $queue->size()); self::assertSame([], $peer->select("SELECT * FROM vault_secrets WHERE namespace = 'queue'"));
    }
    public function testMaintenanceCliNeedsExplicitAdmissionAndSurvivesProcessRestart(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage();
        $this->db->insert('jobs', ['queue' => 'default', 'payload' => serialize(new \Coleza\Tests\Support\QueueSecretJob(['password' => 'synthetic-cli-secret'])), 'available_at' => 0]);
        self::assertSame(1, preg_match('/host=([^;]+);port=([0-9]+)/', (string) getenv('COLEZA_TEST_MARIADB_DSN'), $matches));
        $env = array_merge(getenv(), ['DB_HOST' => $matches[1], 'DB_PORT' => $matches[2], 'DB_DATABASE' => $this->database,
            'DB_USERNAME' => getenv('COLEZA_TEST_MARIADB_USER'), 'DB_PASSWORD' => getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            'APP_KEY' => \Coleza\Tests\Support\SecretFixtureKey::configured(), 'APP_KEY_FILE' => '']);
        $run = function (array $arguments) use ($env): array {
            $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), dirname(__DIR__, 2) . '/tools/queue-secret-maintenance.php', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
            self::assertIsResource($process); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($process), $out, $err];
        };
        [$code, $out, $err] = $run(['--maintenance']); self::assertSame(1, $code); self::assertSame('', $out); self::assertStringNotContainsString('synthetic-cli-secret', $err);
        foreach ([1, 0] as $count) {
            [$code, $out, $err] = $run(['--maintenance', '--confirmed-trusted-jobs']);
            self::assertSame(0, $code, $err); self::assertSame('', $err); self::assertSame(['upgraded_jobs' => $count], json_decode($out, true, flags: JSON_THROW_ON_ERROR));
        }
        self::assertSame('synthetic-cli-secret', $this->secretQueue()->pop()->getJob()->data['password']);
    }
}
