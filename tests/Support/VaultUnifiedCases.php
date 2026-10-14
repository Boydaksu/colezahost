<?php

declare(strict_types=1);
namespace Coleza\Tests\Support;
trait VaultUnifiedCases
{
    private function unifiedFixture(): array
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $key = \Coleza\Tests\Support\SecretFixtureKey::encryptor();
        $audit = new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);
        return [new \Coleza\Domain\Vault\VaultService($this->db, $key, $audit), $key];
    }
    public function testVaultCipherCannotMoveBetweenNamespaces(): void
    {
        [$vault] = $this->unifiedFixture(); $vault->setSecret('provider:first', 'api_token', 'first-synthetic-secret'); $vault->setSecret('provider:second', 'api_token', 'second-synthetic-secret');
        $value = $this->db->selectOne("SELECT encrypted_value FROM vault_secrets WHERE namespace = 'provider:first'")['encrypted_value'];
        $this->db->statement("UPDATE vault_secrets SET encrypted_value = ? WHERE namespace = 'provider:second'", [$value]);
        $this->expectException(\RuntimeException::class); $vault->getSecret('provider:second', 'api_token');
    }
    public function testAuditFailureRollsBackSecretRotation(): void
    {
        [$vault] = $this->unifiedFixture(); $vault->setSecret('provider:first', 'api_token', 'original-synthetic-secret'); $before = $this->db->select('SELECT * FROM vault_secrets');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER unified_audit_fail BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'"
            : "CREATE TRIGGER unified_audit_fail BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try { $vault->setSecret('provider:first', 'api_token', 'replacement-synthetic-secret'); self::fail('Audit failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->db->select('SELECT * FROM vault_secrets')); }
    }
    public function testServerSecretUsesVaultReference(): void
    {
        [, $key] = $this->unifiedFixture(); $servers = new \Coleza\Domain\Servers\Services\ServerService($this->db, $key);
        $server = $servers->createServer(['name' => 'Fixture', 'hostname' => 'unified.example.test', 'ip_address' => '127.0.0.1', 'provider_slug' => 'fixture', 'auth_secret' => 'server-synthetic-secret']);
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM vault_secrets')['n']);
        self::assertSame('server-synthetic-secret', $server->getAuthSecret());
        self::assertStringStartsWith('vault:v1:', $this->db->selectOne('SELECT auth_secret_encrypted FROM servers')['auth_secret_encrypted']);
    }
    public function testWebhookSecretUsesVaultReference(): void
    {
        [, $key] = $this->unifiedFixture(); $hooks = new \Coleza\Domain\Webhooks\WebhookService($this->db->getPdo(), $key);
        $endpoint = $hooks->registerEndpoint('https://example.test/hook');
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM vault_secrets')['n']);
        self::assertNotSame($endpoint->getSecret(), $this->db->selectOne('SELECT secret FROM webhook_endpoints')['secret']);
        self::assertSame($endpoint->getSecret(), $hooks->findEndpoint($endpoint->getId())->getSecret());
    }
    private function unifiedRows(): array
    {
        return [$this->db->select('SELECT * FROM vault_secrets ORDER BY id'), $this->db->select('SELECT * FROM security_audit_logs ORDER BY id')];
    }
    public static function unifiedCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('unifiedCallers')]
    public function testVaultWriteAndAuditStayInsideCallerRollback(string $caller): void
    {
        [$vault] = $this->unifiedFixture(); $before = $this->unifiedRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try { $vault->setSecret('provider:rollback', 'token', 'caller-synthetic-secret'); self::assertTrue($this->db->getPdo()->inTransaction()); }
        finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
        self::assertSame($before, $this->unifiedRows());
    }
    public function testLegacyVaultCipherResealPreservesVersionAndReplays(): void
    {
        [$vault, $key] = $this->unifiedFixture();
        $this->db->insert('vault_secrets', ['namespace' => 'legacy', 'secret_key' => 'token', 'encrypted_value' => $key->encrypt('legacy-synthetic-secret'), 'version' => 9]);
        self::assertSame('legacy-synthetic-secret', $vault->getSecret('legacy', 'token'));
        self::assertSame(1, $vault->upgradeLegacyCiphertexts()); $after = $this->db->select('SELECT * FROM vault_secrets');
        self::assertSame(9, (int) $after[0]['version']); self::assertSame(0, $vault->upgradeLegacyCiphertexts());
        self::assertSame($after, $this->db->select('SELECT * FROM vault_secrets'));
        self::assertSame('legacy-synthetic-secret', $vault->getSecret('legacy', 'token'));
    }
    public function testProviderMetadataFailureRollsBackVaultAndAudit(): void
    {
        [$vault] = $this->unifiedFixture(); $service = new \Coleza\Domain\Providers\Settings\ProviderSettingService($this->db, $vault); $before = $this->unifiedRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER unified_provider_fail BEFORE INSERT ON provider_module_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'metadata failure'"
            : "CREATE TRIGGER unified_provider_fail BEFORE INSERT ON provider_module_settings BEGIN SELECT RAISE(ABORT, 'metadata failure'); END");
        try { $service->saveSettings('fixture', ['api_token' => 'metadata-synthetic-secret']); self::fail('Metadata failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->unifiedRows()); }
    }
    public function testServerOwnerFailureRollsBackVaultAndAudit(): void
    {
        [, $key] = $this->unifiedFixture(); $before = $this->unifiedRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER unified_server_fail BEFORE INSERT ON servers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner failure'"
            : "CREATE TRIGGER unified_server_fail BEFORE INSERT ON servers BEGIN SELECT RAISE(ABORT, 'owner failure'); END");
        $servers = new \Coleza\Domain\Servers\Services\ServerService($this->db, $key);
        try { $servers->createServer(['name' => 'Fixture', 'hostname' => 'fail.example.test', 'ip_address' => '127.0.0.1', 'provider_slug' => 'fixture', 'auth_secret' => 'owner-synthetic-secret']); self::fail('Owner failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->unifiedRows()); self::assertSame([], $this->db->select('SELECT * FROM servers')); }
    }
    public function testWebhookCallerRollbackRemovesReferenceSecretAndAudit(): void
    {
        [, $key] = $this->unifiedFixture(); $hooks = new \Coleza\Domain\Webhooks\WebhookService($this->db->getPdo(), $key); $before = $this->unifiedRows();
        $this->db->getPdo()->beginTransaction();
        try { $hooks->registerEndpoint('https://example.test/hook'); self::assertTrue($this->db->getPdo()->inTransaction()); }
        finally { $this->db->getPdo()->rollBack(); }
        self::assertSame([], $this->db->select('SELECT * FROM webhook_endpoints')); self::assertSame($before, $this->unifiedRows());
    }
    private function legacyOwners(): array
    {
        [$vault, $key] = $this->unifiedFixture(); $servers = new \Coleza\Domain\Servers\Services\ServerService($this->db, $key);
        $server = $servers->createServer(['name' => 'Legacy', 'hostname' => 'legacy.example.test', 'ip_address' => '127.0.0.1', 'provider_slug' => 'fixture', 'auth_secret' => null]);
        $cipher = (new \Coleza\Domain\Vault\SecretCipher($key))->seal('legacy-server-secret', 'server:legacy.example.test');
        $this->db->update('servers', ['auth_secret_encrypted' => $cipher], 'id = :id', ['id' => $server->getId()]);
        $hooks = new \Coleza\Domain\Webhooks\WebhookService($this->db->getPdo(), $key);
        $hookId = (int) $this->db->insert('webhook_endpoints', ['target_url' => 'https://example.test/legacy', 'secret' => 'whsec_legacy-synthetic-secret', 'events_json' => '["*"]', 'is_active' => 1, 'created_at' => date('c'), 'updated_at' => date('c')]);
        return [$vault, $key, $servers, $server->getId(), $hooks, $hookId];
    }
    public function testLegacyOwnerUpgradePreservesSecretsAndIsIdempotent(): void
    {
        [, $key, $servers, $id, $hooks, $hookId] = $this->legacyOwners();
        $upgrade = new \Coleza\Domain\Vault\VaultReferenceUpgrade($this->db, $key);
        self::assertSame(2, $upgrade->upgrade(true)); $after = $this->db->select('SELECT * FROM vault_secrets ORDER BY id');
        self::assertSame(0, $upgrade->upgrade()); self::assertSame($after, $this->db->select('SELECT * FROM vault_secrets ORDER BY id'));
        self::assertSame('legacy-server-secret', $servers->findServerById($id)->getAuthSecret());
        self::assertSame('whsec_legacy-synthetic-secret', $hooks->findEndpoint($hookId)->getSecret());
    }
    public function testUnconfirmedLegacyOwnerUpgradeChangesNothing(): void
    {
        [, $key] = $this->legacyOwners(); $before = $this->unifiedRows(); $servers = $this->db->select('SELECT * FROM servers');
        try { (new \Coleza\Domain\Vault\VaultReferenceUpgrade($this->db, $key))->upgrade(); self::fail('Unconfirmed webhook accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->unifiedRows()); self::assertSame($servers, $this->db->select('SELECT * FROM servers')); }
    }
    public function testExtraUniqueIdentityRefusesBeforeVaultMutation(): void
    {
        [$vault] = $this->unifiedFixture(); $this->db->statement('CREATE UNIQUE INDEX unexpected_vault_version ON vault_secrets(version)');
        try { $vault->setSecret('provider:first', 'token', 'bad-identity-secret'); self::fail('Extra identity accepted'); }
        catch (\LogicException) { self::assertSame([], $this->db->select('SELECT * FROM vault_secrets')); }
    }

    public function testRegistrarMetadataFailureRollsBackBothKeysAndAudit(): void
    {
        [$vault] = $this->unifiedFixture(); $manager = new \Coleza\Domain\Domains\Registrar\Vault\VaultRegistrarSettingsManager($this->db, $vault); $manager->ensureTables(); $before = $this->unifiedRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER unified_registrar_fail BEFORE INSERT ON registrar_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'metadata failure'"
            : "CREATE TRIGGER unified_registrar_fail BEFORE INSERT ON registrar_settings BEGIN SELECT RAISE(ABORT, 'metadata failure'); END");
        try { $manager->saveConfiguration(new \Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration('fixture', 'registrar-synthetic-key', 'registrar-synthetic-secret')); self::fail('Metadata failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->unifiedRows()); }
    }
    public function testWebhookAuditFailureRollsBackOwnerAndSecret(): void
    {
        [, $key] = $this->unifiedFixture(); $hooks = new \Coleza\Domain\Webhooks\WebhookService($this->db->getPdo(), $key); $before = $this->unifiedRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER unified_hook_audit_fail BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'"
            : "CREATE TRIGGER unified_hook_audit_fail BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try { $hooks->registerEndpoint('https://example.test/hook'); self::fail('Audit failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->unifiedRows()); self::assertSame([], $this->db->select('SELECT * FROM webhook_endpoints')); }
    }
    public function testLegacyTransferOwnerFailureRollsBackEverySecretAndReference(): void
    {
        [, $key] = $this->legacyOwners(); $before = $this->unifiedRows(); $owners = [$this->db->select('SELECT * FROM servers'), $this->db->select('SELECT * FROM webhook_endpoints')];
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER unified_transfer_fail BEFORE UPDATE ON webhook_endpoints FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner failure'"
            : "CREATE TRIGGER unified_transfer_fail BEFORE UPDATE ON webhook_endpoints BEGIN SELECT RAISE(ABORT, 'owner failure'); END");
        try { (new \Coleza\Domain\Vault\VaultReferenceUpgrade($this->db, $key))->upgrade(true); self::fail('Transfer failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->unifiedRows()); self::assertSame($owners, [$this->db->select('SELECT * FROM servers'), $this->db->select('SELECT * FROM webhook_endpoints')]); }
    }
    public function testServerNullRemovalDeletesVaultSecretInSameOperation(): void
    {
        [, $key] = $this->unifiedFixture(); $servers = new \Coleza\Domain\Servers\Services\ServerService($this->db, $key);
        $server = $servers->createServer(['name' => 'Fixture', 'hostname' => 'remove.example.test', 'ip_address' => '127.0.0.1', 'provider_slug' => 'fixture', 'auth_secret' => 'remove-synthetic-secret']);
        self::assertNull($servers->updateServer($server->getId(), ['auth_secret' => null])->getAuthSecret());
        self::assertSame([], $this->db->select('SELECT * FROM vault_secrets'));
    }

}
