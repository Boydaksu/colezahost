<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait ProvisioningSecretCases
{
    private function secretOps(): \Coleza\Domain\Provisioning\Services\ProvisioningOperationService
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $ops = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db, SecretFixtureKey::encryptor());
        $ops->ensureTables(); return $ops;
    }
    public function testPayloadAndResultPersistWithoutPlaintextAndRestartExactly(): void
    {
        $ops = $this->secretOps();
        $payload = ['password' => 'synthetic-operation-password', 'nested' => ['unlabelled' => 'synthetic-unlabelled-secret'], 'amount' => 1.0];
        $op = $ops->queueOperation(42, 'fixture', 'create', $payload);
        $data = ['token' => 'synthetic-result-token', 'account_id' => 17];
        $ops->recordAttempt($op->getOperationUuid(), \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create', 'ok', '17', $data));
        $stored = json_encode($this->db->select('SELECT * FROM provisioning_operations'));
        foreach (['synthetic-operation-password', 'synthetic-unlabelled-secret', 'synthetic-result-token'] as $secret) { self::assertStringNotContainsString($secret, $stored); }
        $fresh = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db, SecretFixtureKey::encryptor());
        self::assertSame($payload, $fresh->findOperationById($op->getId())->getPayload());
        self::assertSame($data, $fresh->findOperationById($op->getId())->getResultData());
    }
    public function testOperationReferenceCannotBeSplicedBetweenOwners(): void
    {
        $ops = $this->secretOps(); $a = $ops->queueOperation(42, 'fixture', 'create', ['password' => 'secret-a']);
        $b = $ops->queueOperation(43, 'fixture', 'create', ['password' => 'secret-b']);
        $ref = $this->db->selectOne('SELECT payload_json FROM provisioning_operations WHERE id = ?', [$a->getId()])['payload_json'];
        $this->db->update('provisioning_operations', ['payload_json' => $ref], 'id = :id', ['id' => $b->getId()]);
        $this->expectException(\RuntimeException::class); $ops->findOperationById($b->getId());
    }
    private function operationRows(): array
    {
        $rows = []; foreach (['provisioning_operations', 'provisioning_audit_logs', 'vault_secrets', 'security_audit_logs'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); } return $rows;
    }
    public function testLateAttemptAuditFailureRollsBackStateResultVaultAndAccessAudit(): void
    {
        $ops = $this->secretOps(); $op = $ops->queueOperation(42, 'fixture', 'create', ['password' => 'synthetic-password']);
        $before = $this->operationRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER operation_audit_fail BEFORE INSERT ON provisioning_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'"
            : "CREATE TRIGGER operation_audit_fail BEFORE INSERT ON provisioning_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try { $ops->recordAttempt($op->getOperationUuid(), \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create', 'ok', '17', ['token' => 'synthetic-token'])); self::fail('Audit failure accepted'); }
        catch (\PDOException) { self::assertSame($before, $this->operationRows()); }
    }
    public static function operationCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('operationCallers')]
    public function testCallerRollbackIncludesOwnerVaultAndAllAudits(string $caller): void
    {
        $ops = $this->secretOps(); $before = $this->operationRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try {
            $op = $ops->queueOperation(42, 'fixture', 'create', ['password' => 'synthetic-password']);
            $ops->recordAttempt($op->getOperationUuid(), \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create', 'ok', '17', ['token' => 'synthetic-token']));
            self::assertTrue($this->db->inTransaction());
        } finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
        self::assertSame($before, $this->operationRows());
    }
    public function testWrongKeyCannotReadOrModifyOperation(): void
    {
        $ops = $this->secretOps(); $op = $ops->queueOperation(42, 'fixture', 'create', ['password' => 'synthetic-password']);
        $before = $this->operationRows();
        $wrong = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db, \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate()));
        foreach ([fn() => $wrong->findOperationById($op->getId()), fn() => $wrong->recordAttempt($op->getOperationUuid(), \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create', 'ok'))] as $action) {
            try { $action(); self::fail('Wrong key accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->operationRows()); }
        }
    }
    public function testMissingConfiguredKeyFailsBeforeQueueWrites(): void
    {
        $this->secretOps(); $before = $this->operationRows(); $saved = [];
        foreach (['APP_KEY', 'APP_KEY_FILE'] as $name) { $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null]; putenv($name); unset($_ENV[$name], $_SERVER[$name]); }
        try {
            $ops = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db);
            try { $ops->queueOperation(42, 'fixture', 'create', ['password' => 'synthetic-password']); self::fail('Missing key accepted'); }
            catch (\RuntimeException|\InvalidArgumentException) { self::assertSame($before, $this->operationRows()); }
        } finally {
            foreach ($saved as $name => [$env, $arrayEnv, $serverEnv]) { $env === false ? putenv($name) : putenv($name . '=' . $env); if ($arrayEnv !== null) { $_ENV[$name] = $arrayEnv; } if ($serverEnv !== null) { $_SERVER[$name] = $serverEnv; } }
        }
    }
    public function testLegacyDataRequiresExplicitUpgradeAndMalformedBatchRollsBack(): void
    {
        $ops = $this->secretOps(); $op = $ops->queueOperation(42, 'fixture', 'create', []);
        $this->db->statement('DELETE FROM vault_secrets');
        $this->db->update('provisioning_operations', ['payload_json' => '{"password":"legacy-synthetic-password"}', 'result_json' => '{broken'], 'id = :id', ['id' => $op->getId()]);
        $before = $this->operationRows();
        try { $ops->findOperationById($op->getId()); self::fail('Runtime plaintext fallback accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->operationRows()); }
        $upgrade = new \Coleza\Domain\Provisioning\Services\ProvisioningPayloadStore($this->db, SecretFixtureKey::encryptor());
        try { $upgrade->upgrade(); self::fail('Unconfirmed upgrade accepted'); } catch (\LogicException) { self::assertSame($before, $this->operationRows()); }
        try { $upgrade->upgrade(true); self::fail('Malformed JSON accepted'); } catch (\JsonException) { self::assertSame($before, $this->operationRows()); }
        $this->db->update('provisioning_operations', ['result_json' => '{"token":"legacy-synthetic-token"}'], 'id = :id', ['id' => $op->getId()]);
        self::assertSame(2, $upgrade->upgrade(true)); self::assertSame(0, $upgrade->upgrade(true));
        self::assertSame(['password' => 'legacy-synthetic-password'], $ops->findOperationById($op->getId())->getPayload());
        self::assertSame(['token' => 'legacy-synthetic-token'], $ops->findOperationById($op->getId())->getResultData());
    }
}
