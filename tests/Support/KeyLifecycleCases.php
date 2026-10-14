<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Vault\{KeyLifecycleService, KeyBinding, SecretCipher, VaultService, ApplicationKey, PrivateKeyFile};

trait KeyLifecycleCases
{
    private array $keyDirectories = [];
    private function lifecycleFixture(): array
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $key = SecretFixtureKey::encryptor();
        $vault = new VaultService($this->db, $key, new \Coleza\Domain\Identity\Audit\AuditLogger($this->db));
        $vault->setSecret('provider:fixture', 'token', 'provider-synthetic-secret');
        $this->db->insert('vault_secrets', ['namespace' => 'legacy', 'secret_key' => 'token', 'encrypted_value' => $key->encrypt('legacy-synthetic-secret'), 'version' => 7]);
        $email = new \Coleza\Domain\Installer\EmailSetupService($key);
        $email->configureEmail($this->db, new \Coleza\Domain\Installer\EmailTransportConfig(host: 'smtp.example.test', password: 'smtp-synthetic-secret'));
        $two = new \Coleza\Domain\Identity\TwoFactor\TwoFactorService($this->db, new \Coleza\Domain\Identity\TwoFactor\TotpEngine(), new \Coleza\Domain\Identity\Security\PasswordHasher(), $key);
        $totp = $two->beginSetup(7, 'fixture@example.test')['secret'];
        $servers = new \Coleza\Domain\Servers\Services\ServerService($this->db, $key);
        $data = ['name' => 'Fixture', 'hostname' => 'lifecycle.example.test', 'ip_address' => '127.0.0.1', 'provider_slug' => 'fixture', 'auth_secret' => 'server-synthetic-secret'];
        $server = $servers->createServer($data);
        $data['hostname'] = 'legacy.example.test'; unset($data['auth_secret']);
        $legacy = $servers->createServer($data);
        $this->db->update('servers', ['auth_secret_encrypted' => (new SecretCipher($key))->seal('legacy-server-synthetic-secret', 'server:legacy.example.test')], 'id = :id', ['id' => $legacy->getId()]);
        $hooks = new \Coleza\Domain\Webhooks\WebhookService($this->db->getPdo(), $key);
        $hook = $hooks->registerEndpoint('https://example.test/key-lifecycle');
        return [new KeyLifecycleService($this->db, $key), $key, $vault, $email, $two, $totp, $server->getId(), $hook->getId(), $hook->getSecret()];
    }
    private function replacementFile(): array
    {
        $directory = sys_get_temp_dir() . '/coleza-keylife-test-' . bin2hex(random_bytes(12));
        $this->keyDirectories[] = $directory;
        $path = $directory . '/application.key'; $root = dirname(__DIR__, 2);
        PrivateKeyFile::provision($path, $root);
        return [$path, ApplicationKey::encryptor(PrivateKeyFile::load($path, $root))];
    }
    private function cleanupLifecycleKeys(): void
    {
        foreach ($this->keyDirectories as $directory) {
            if (is_file($directory . '/application.key')) { unlink($directory . '/application.key'); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }
    private function lifecycleRows(): array
    {
        $rows = [];
        foreach (['system_settings', 'vault_secrets', 'servers', 'user_two_factor', 'webhook_endpoints', 'security_audit_logs'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); }
        return $rows;
    }
    public function testBoundDatabaseRejectsWriterUsingAnotherKeyBeforeChanges(): void
    {
        [, $key] = $this->lifecycleFixture();
        $this->db->insert('system_settings', ['setting_key' => KeyBinding::SETTING, 'setting_value' => (new SecretCipher($key))->seal(KeyBinding::PROBE, KeyBinding::SCOPE), 'is_encrypted' => 1]);
        $before = $this->lifecycleRows();
        try { (new VaultService($this->db, ApplicationKey::encryptor(ApplicationKey::generate())))->setSecret('provider:wrong', 'token', 'wrong-synthetic-secret'); self::fail('Wrong-key writer accepted'); }
        catch (\RuntimeException) { self::assertSame($before, $this->lifecycleRows()); }
    }
    public function testBindingIsIdempotentAndDeepVerificationIsReadOnly(): void
    {
        [$service, $key] = $this->lifecycleFixture();
        $result = $service->initialize(true); $before = $this->lifecycleRows();
        self::assertSame($result, $service->initialize(true)); self::assertSame($before, $this->lifecycleRows());
        $this->db->enableQueryLog(); self::assertSame($result, $service->verify());
        foreach ($this->db->getQueryLog() as $query) { self::assertMatchesRegularExpression('/^(SELECT|PRAGMA)\b/i', $query['query']); }
        self::assertSame(['vault' => 4, 'smtp' => 1, 'totp' => 1, 'legacy_server' => 1, 'references' => 2], $result['counts']);
        self::assertSame($key->fingerprint(), $result['key_id']); self::assertSame($before, $this->lifecycleRows());
    }
    public function testRotationPreservesEverySecretAndFencesAllOldSecretWriters(): void
    {
        [$service, , $oldVault, $oldEmail, $oldTwo, $totp, $serverId, $hookId, $hookSecret] = $this->lifecycleFixture();
        $service->initialize(true); [$file, $newKey] = $this->replacementFile();
        $result = $service->rotateToFile($file, dirname(__DIR__, 2), true);
        self::assertSame($newKey->fingerprint(), $result['key_id']);
        $vault = new VaultService($this->db, $newKey);
        self::assertSame('provider-synthetic-secret', $vault->getSecret('provider:fixture', 'token'));
        self::assertSame('legacy-synthetic-secret', $vault->getSecret('legacy', 'token'));
        self::assertSame(8, (int) $this->db->selectOne("SELECT version FROM vault_secrets WHERE namespace = 'legacy'")['version']);
        self::assertSame('smtp-synthetic-secret', (new \Coleza\Domain\Installer\EmailSetupService($newKey))->readConfiguration($this->db)->getPassword());
        self::assertSame($totp, (new SecretCipher($newKey))->open($this->db->selectOne('SELECT secret FROM user_two_factor WHERE user_id = 7')['secret'], 'totp:7'));
        self::assertSame('server-synthetic-secret', (new \Coleza\Domain\Servers\Services\ServerService($this->db, $newKey))->findServerById($serverId)->getAuthSecret());
        self::assertSame($hookSecret, (new \Coleza\Domain\Webhooks\WebhookService($this->db->getPdo(), $newKey))->findEndpoint($hookId)->getSecret());
        self::assertSame('legacy-server-synthetic-secret', (new SecretCipher($newKey))->open($this->db->selectOne("SELECT auth_secret_encrypted FROM servers WHERE hostname = 'legacy.example.test'")['auth_secret_encrypted'], 'server:legacy.example.test'));
        $before = $this->lifecycleRows();
        $actions = [fn() => $oldVault->setSecret('provider:fixture', 'token', 'stale'), fn() => $oldVault->deleteSecret('provider:fixture', 'token'),
            fn() => $oldEmail->configureEmail($this->db, new \Coleza\Domain\Installer\EmailTransportConfig(host: 'smtp.example.test', password: 'stale')),
            fn() => $oldTwo->beginSetup(8, 'stale@example.test')];
        foreach ($actions as $action) { try { $action(); self::fail('Stale key writer accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->lifecycleRows()); } }
        self::assertSame($result['counts'], (new KeyLifecycleService($this->db, $newKey))->verify()['counts']);
    }
    public function testLateAuditFailureRollsBackAllCiphertextsProbeAndVersions(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); [$file, $newKey] = $this->replacementFile();
        $before = $this->lifecycleRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER key_rotation_audit_fail BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'"
            : "CREATE TRIGGER key_rotation_audit_fail BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try { $service->rotateToFile($file, dirname(__DIR__, 2), true); self::fail('Audit failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->lifecycleRows()); }
        self::assertSame($newKey->fingerprint(), ApplicationKey::encryptor(PrivateKeyFile::load($file, dirname(__DIR__, 2)))->fingerprint());
        $service->verify();
    }
    public function testWrongKeyCannotRebindAnExistingDatabase(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); $before = $this->lifecycleRows();
        try { (new KeyLifecycleService($this->db, ApplicationKey::encryptor(ApplicationKey::generate())))->initialize(true); self::fail('Rebinding with wrong key accepted'); }
        catch (\RuntimeException|\LogicException) { self::assertSame($before, $this->lifecycleRows()); }
    }
    public function testPlaintextOrUnknownSettingCannotBeSilentlyBound(): void
    {
        [$service] = $this->lifecycleFixture(); $this->db->statement("UPDATE system_settings SET setting_value = 'legacy plaintext' WHERE setting_key = 'mail.password'");
        $before = $this->lifecycleRows();
        try { $service->initialize(true); self::fail('Plaintext accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->lifecycleRows()); }
    }
    public function testCorruptedCipherPreventsAllRotationWrites(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); [$file] = $this->replacementFile();
        $this->db->statement("UPDATE user_two_factor SET secret = 'enc:v1:broken'"); $before = $this->lifecycleRows();
        try { $service->rotateToFile($file, dirname(__DIR__, 2), true); self::fail('Corrupt secret accepted'); }
        catch (\RuntimeException|\LogicException) { self::assertSame($before, $this->lifecycleRows()); }
    }
    public static function lifecycleCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleCallers')]
    public function testLifecycleRefusesCallerTransactions(string $caller): void
    {
        [$service] = $this->lifecycleFixture(); $before = $this->lifecycleRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try {
            foreach ([fn() => $service->initialize(true), fn() => $service->rotateToFile('unused.key', dirname(__DIR__, 2), true)] as $action) {
                $error = null; try { $action(); } catch (\LogicException $caught) { $error = $caught; }
                self::assertNotNull($error); self::assertTrue($this->db->getPdo()->inTransaction()); self::assertSame($before, $this->lifecycleRows());
            }
        }
        finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
    }
    public function testMaintenanceAdmissionIsRequired(): void
    {
        [$service] = $this->lifecycleFixture(); $before = $this->lifecycleRows();
        try { $service->initialize(); self::fail('Maintenance admission ignored'); }
        catch (\LogicException) { self::assertSame($before, $this->lifecycleRows()); }
    }

    public function testUnknownEncryptedScopeAndBrokenOwnerReferencePreventBinding(): void
    {
        [$service] = $this->lifecycleFixture();
        $this->db->insert('system_settings', ['setting_key' => 'unknown.secret', 'setting_value' => 'opaque', 'is_encrypted' => 1]);
        $before = $this->lifecycleRows();
        $error = null; try { $service->initialize(true); } catch (\LogicException $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertSame($before, $this->lifecycleRows());
        $this->db->statement("DELETE FROM system_settings WHERE setting_key = 'unknown.secret'");
        $this->db->statement("UPDATE servers SET auth_secret_encrypted = 'vault:v1:wrong-owner' WHERE hostname = 'lifecycle.example.test'");
        $before = $this->lifecycleRows();
        $error = null; try { $service->initialize(true); } catch (\RuntimeException $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertSame($before, $this->lifecycleRows());
    }
    public function testLegacyCronNeedsExplicitHashUpgradeBeforeBinding(): void
    {
        [$service] = $this->lifecycleFixture();
        $this->db->insert('system_settings', ['setting_key' => 'cron.token', 'setting_value' => 'legacy-cron-synthetic-secret', 'is_encrypted' => 1]);
        $error = null; try { $service->initialize(true); } catch (\LogicException $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertNull(KeyBinding::row($this->db));
        $cron = new \Coleza\Domain\Installer\CronSetupService(); self::assertSame(1, $cron->upgradeLegacyToken($this->db, true));
        $service->initialize(true); [$file] = $this->replacementFile(); $service->rotateToFile($file, dirname(__DIR__, 2), true);
        self::assertTrue($cron->validateCronToken($this->db, 'legacy-cron-synthetic-secret'));
    }

    public function testRotationRequiresAnExistingDifferentDurableReplacementFile(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); $before = $this->lifecycleRows();
        $error = null; try { $service->rotateToFile('missing.key', dirname(__DIR__, 2), true); } catch (\RuntimeException $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertSame($before, $this->lifecycleRows());
        [$file] = $this->replacementFile(); file_put_contents($file, SecretFixtureKey::configured() . "\n");
        $error = null; try { $service->rotateToFile($file, dirname(__DIR__, 2), true); } catch (\LogicException $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertSame($before, $this->lifecycleRows());
    }

    public function testRotationLogsAndMetadataContainNoPlaintextOrKeyMaterial(): void
    {
        [$service] = $this->lifecycleFixture(); $service->initialize(true); [$file] = $this->replacementFile();
        $key = PrivateKeyFile::load($file, dirname(__DIR__, 2)); $this->db->enableQueryLog();
        $result = $service->rotateToFile($file, dirname(__DIR__, 2), true);
        $serialized = json_encode([$result, $this->db->getQueryLog(), $this->db->select('SELECT payload FROM security_audit_logs')], JSON_THROW_ON_ERROR);
        foreach ([$key, SecretFixtureKey::configured(), 'provider-synthetic-secret', 'smtp-synthetic-secret', 'legacy-server-synthetic-secret'] as $secret) { self::assertStringNotContainsString($secret, $serialized); }
    }

    public function testEmptyCronTokenCannotBeCreatedOrAuthenticate(): void
    {
        $this->lifecycleFixture(); $cron = new \Coleza\Domain\Installer\CronSetupService();
        $error = null; try { $cron->setupCron($this->db, ''); } catch (\InvalidArgumentException $caught) { $error = $caught; }
        self::assertNotNull($error);
        $this->db->insert('system_settings', ['setting_key' => 'cron.token', 'setting_value' => $cron::HASH_PREFIX . hash('sha256', ''), 'is_encrypted' => 0]);
        self::assertFalse($cron->validateCronToken($this->db, '')); self::assertFalse($cron->validateCronToken($this->db, '   '));
    }
}
