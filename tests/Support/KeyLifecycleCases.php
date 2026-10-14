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
    public function testDomainSecretsSurviveRotationAndDeepVerifyRejectsOwnerSplice(): void
    {
        [$lifecycle, $key] = $this->lifecycleFixture();
        $domains = new \Coleza\Domain\Domains\DomainService($this->db, null, $key);
        $ops = new \Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($this->db, $key); $ops->ensureTable();
        $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $domains->setEppCode($d->getId(), 'domain-synthetic-epp');
        $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['epp_code' => 'input-synthetic-epp']]);
        $ops->markSucceeded($op->getId(), 'remote', ['token' => 'result-synthetic-token']);
        $lifecycle->initialize(true); [$file, $newKey] = $this->replacementFile();
        $result = $lifecycle->rotateToFile($file, dirname(__DIR__, 2), true);
        $fresh = new \Coleza\Domain\Domains\DomainService($this->db, null, $newKey);
        $freshOps = new \Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($this->db, $newKey);
        self::assertSame('domain-synthetic-epp', $fresh->findDomainById($d->getId())->getEppCode());
        self::assertSame(['epp_code' => 'input-synthetic-epp'], $freshOps->findById($op->getId())->getPayload());
        self::assertSame(['token' => 'result-synthetic-token'], $freshOps->findById($op->getId())->getResult());
        $before = $this->db->select('SELECT * FROM domains'); $beforeOps = $this->db->select('SELECT * FROM domain_registrar_operations');
        foreach ([fn() => $domains->setEppCode($d->getId(), 'stale'), fn() => $ops->markProcessing($op->getId())] as $action) {
            try { $action(); self::fail('Old domain writer accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->db->select('SELECT * FROM domains')); self::assertSame($beforeOps, $this->db->select('SELECT * FROM domain_registrar_operations')); }
        }
        $verify = new KeyLifecycleService($this->db, $newKey); self::assertSame($result['counts'], $verify->verify()['counts']);
        $this->db->update('domain_registrar_operations', ['domain_id' => 99], 'id = :id', ['id' => $op->getId()]);
        $this->expectException(\RuntimeException::class); $verify->verify();
    }
    public function testAutomationSecretsSurviveRotationAndDeepVerifyRejectsOwnerSplice(): void
    {
        [$lifecycle, $key] = $this->lifecycleFixture();
        \Coleza\Tests\Support\AutomationStorageFixture::install($this->db);
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($this->db);
        $manager = new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, $key);
        $entry = $manager->createRequest('rule', 'Fixture', new \Coleza\Domain\Automation\Triggers\TriggerContext('event', ['password' => 'automation-synthetic-password']),
            [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'fixture', ['token' => 'automation-synthetic-token'])], new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
        $history = new \Coleza\Domain\Automation\Execution\DatabaseRunHistoryRepository($this->db, $key);
        $record = new \Coleza\Domain\Automation\Execution\AutomationRunRecord('secret-run', 'rule', 'Fixture', 1, 'event', 'corr',
            \Coleza\Domain\Automation\Execution\ExecutionMode::ACTIVE, \Coleza\Domain\Automation\Execution\RunStatus::SUCCESS, true, 'THEN', contextSummary: ['password' => 'history-synthetic-password']);
        $history->save($record);
        $rule = new \Coleza\Domain\Automation\Engine\AutomationRule('rule', 'Fixture', new \Coleza\Domain\Automation\Triggers\EventTrigger('event'),
            new \Coleza\Domain\Automation\Branching\IfElseBranch(new \Coleza\Domain\Automation\Conditions\FieldCondition('enabled', '==', true),
                [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'fixture', ['password' => 'rule-synthetic-password'])]));
        $version = (new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($this->db, $key))->recordVersion($rule, 'actor', 'Fixture');
        $lifecycle->initialize(true); [$file, $newKey] = $this->replacementFile();
        $result = $lifecycle->rotateToFile($file, dirname(__DIR__, 2), true);
        self::assertSame($entry->getContext()->all(), (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, $newKey))->findById($entry->getId())->getContext()->all());
        self::assertSame($record->toArray(), (new \Coleza\Domain\Automation\Execution\DatabaseRunHistoryRepository($this->db, $newKey))->findById('secret-run')->toArray());
        self::assertSame($version->toArray(), (new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($this->db, $newKey))->getVersion('rule', 1)->toArray());
        $before = $this->db->select('SELECT * FROM automation_pending');
        try { $manager->approve($entry->getId(), 'actor'); self::fail('Old automation writer accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->db->select('SELECT * FROM automation_pending')); }
        $verify = new KeyLifecycleService($this->db, $newKey); self::assertSame($result['counts'], $verify->verify()['counts']);
        $this->db->update('automation_pending', ['id' => 'spliced'], 'id = :id', ['id' => $entry->getId()]);
        $this->expectException(\RuntimeException::class); $verify->verify();
    }
    public function testOperationSecretsSurviveRotationAndOwnerCorruptionPreventsVerification(): void
    {
        [$lifecycle, $key] = $this->lifecycleFixture();
        $ops = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db, $key); $ops->ensureTables();
        $op = $ops->queueOperation(42, 'fixture', 'create', ['password' => 'operation-synthetic-password']);
        $ops->recordAttempt($op->getOperationUuid(), \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create', 'ok', '17', ['token' => 'result-synthetic-token']));
        $lifecycle->initialize(true); [$file, $newKey] = $this->replacementFile();
        $lifecycle->rotateToFile($file, dirname(__DIR__, 2), true);
        $fresh = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db, $newKey);
        self::assertSame(['password' => 'operation-synthetic-password'], $fresh->findOperationById($op->getId())->getPayload());
        self::assertSame(['token' => 'result-synthetic-token'], $fresh->findOperationById($op->getId())->getResultData());
        $before = $this->db->select('SELECT * FROM provisioning_operations');
        try { $ops->queueOperation(43, 'fixture', 'create', []); self::fail('Old writer accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->db->select('SELECT * FROM provisioning_operations')); }
        $verify = new KeyLifecycleService($this->db, $newKey); $verify->verify();
        $this->db->update('provisioning_operations', ['service_id' => 99], 'id = :id', ['id' => $op->getId()]);
        $this->expectException(\RuntimeException::class); $verify->verify();
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
