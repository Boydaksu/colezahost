<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait DomainSecretCases
{
    private function domainSecrets(): array
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $domains = new \Coleza\Domain\Domains\DomainService($this->db, null, SecretFixtureKey::encryptor());
        $ops = new \Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($this->db, SecretFixtureKey::encryptor()); $ops->ensureTable();
        return [$domains, $ops];
    }
    public function testEppAndOperationDataAreEncryptedAndRestartExactly(): void
    {
        [$domains, $ops] = $this->domainSecrets();
        $domain = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $domains->setEppCode($domain->getId(), 'synthetic-epp-secret');
        $op = $ops->create(['domain_id' => $domain->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['epp_code' => 'synthetic-epp-secret', 'unlabelled' => 'synthetic-input-secret', 'amount' => 1.0]]);
        $ops->markSucceeded($op->getId(), 'remote', ['token' => 'synthetic-result-secret']);
        $stored = json_encode([$this->db->select('SELECT * FROM domains'), $this->db->select('SELECT * FROM domain_registrar_operations')]);
        foreach (['synthetic-epp-secret', 'synthetic-input-secret', 'synthetic-result-secret'] as $secret) { self::assertStringNotContainsString($secret, $stored); }
        self::assertSame('synthetic-epp-secret', (new \Coleza\Domain\Domains\DomainService($this->db, null, SecretFixtureKey::encryptor()))->findDomainById($domain->getId())->getEppCode());
        self::assertSame(['epp_code' => 'synthetic-epp-secret', 'unlabelled' => 'synthetic-input-secret', 'amount' => 1.0], $ops->findById($op->getId())->getPayload());
        self::assertSame(['token' => 'synthetic-result-secret'], $ops->findByIdempotencyKey('fixture')->getResult());
    }
    private function domainRows(): array
    {
        $rows = []; foreach (['domains', 'domain_registrar_operations', 'domain_timeline_events', 'vault_secrets', 'security_audit_logs'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); } return $rows;
    }
    public function testMetadataUpdatePreservesEppReferenceAndExplicitClearDeletesSecret(): void
    {
        [$domains] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $code = str_repeat('synthetic-epp-', 80); $domains->setEppCode($d->getId(), $code);
        $before = $this->db->select('SELECT * FROM vault_secrets');
        $domains->updateDomain($d->getId(), ['whois_privacy' => true]);
        self::assertSame($code, $domains->findDomainById($d->getId())->getEppCode());
        self::assertSame($before, $this->db->select('SELECT * FROM vault_secrets'));
        self::assertNull($domains->updateDomain($d->getId(), ['epp_code' => null])->getEppCode());
        self::assertSame([], $this->db->select('SELECT * FROM vault_secrets'));
    }
    public static function domainOwners(): array { return [['epp'], ['operation']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('domainOwners')]
    public function testSplicedReferencesAreRejected(string $kind): void
    {
        [$domains, $ops] = $this->domainSecrets();
        $a = $domains->createDomain(['user_id' => 7, 'domain' => 'a.example']); $b = $domains->createDomain(['user_id' => 7, 'domain' => 'b.example']);
        if ($kind === 'epp') {
            $domains->setEppCode($a->getId(), 'synthetic-epp-a'); $domains->setEppCode($b->getId(), 'synthetic-epp-b');
            $ref = $this->db->selectOne('SELECT epp_code FROM domains WHERE id = ?', [$a->getId()])['epp_code'];
            $this->db->update('domains', ['epp_code' => $ref], 'id = :id', ['id' => $b->getId()]);
            $this->expectException(\RuntimeException::class); $domains->findDomainById($b->getId());
        } else {
            $op = $ops->create(['domain_id' => $a->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['token' => 'synthetic-token']]);
            $this->db->update('domain_registrar_operations', ['domain_id' => $b->getId()], 'id = :id', ['id' => $op->getId()]);
            $this->expectException(\RuntimeException::class); $ops->findById($op->getId());
        }
    }
    public function testLateTimelineFailureRollsBackEppVaultAndSecurityAudit(): void
    {
        [$domains] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $domains->setEppCode($d->getId(), 'original-synthetic-epp');
        $before = $this->domainRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER domain_timeline_fail BEFORE INSERT ON domain_timeline_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'timeline failure'"
            : "CREATE TRIGGER domain_timeline_fail BEFORE INSERT ON domain_timeline_events BEGIN SELECT RAISE(ABORT, 'timeline failure'); END");
        try { $domains->setEppCode($d->getId(), 'replacement-synthetic-epp'); self::fail('Timeline failure ignored'); } catch (\PDOException) { self::assertSame($before, $this->domainRows()); }
    }
    public function testLateOperationFailureRollsBackNewOwnerAndResultStateVaultAndAudits(): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['token' => 'synthetic-input']]);
        $before = $this->domainRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER domain_operation_fail BEFORE UPDATE ON domain_registrar_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner failure'"
            : "CREATE TRIGGER domain_operation_fail BEFORE UPDATE ON domain_registrar_operations BEGIN SELECT RAISE(ABORT, 'owner failure'); END");
        foreach ([fn() => $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'other', 'payload' => ['token' => 'synthetic-other']]),
            fn() => $ops->markSucceeded($op->getId(), 'remote', ['token' => 'synthetic-result'])] as $action) {
            try { $action(); self::fail('Owner failure ignored'); } catch (\PDOException) { self::assertSame($before, $this->domainRows()); }
        }
    }
    public static function domainCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('domainCallers')]
    public function testParentRollbackIncludesEppAndOperations(string $caller): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $before = $this->domainRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try {
            $domains->setEppCode($d->getId(), 'synthetic-epp');
            $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['token' => 'synthetic-input']]);
            $ops->markSucceeded($op->getId(), 'remote', ['token' => 'synthetic-result']); self::assertTrue($this->db->inTransaction());
        } finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
        self::assertSame($before, $this->domainRows());
    }
    public function testWrongKeyCannotReadOrReplaceEppAndResult(): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $domains->setEppCode($d->getId(), 'synthetic-epp');
        $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['token' => 'synthetic-input']]);
        $key = \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate());
        $wrongDomains = new \Coleza\Domain\Domains\DomainService($this->db, null, $key); $wrongOps = new \Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($this->db, $key);
        $before = $this->domainRows();
        foreach ([fn() => $wrongDomains->findDomainById($d->getId()), fn() => $wrongDomains->setEppCode($d->getId(), 'replacement'), fn() => $wrongOps->markSucceeded($op->getId(), 'remote', ['token' => 'replacement'])] as $action) {
            try { $action(); self::fail('Wrong key accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->domainRows()); }
        }
    }
    public function testLegacyUpgradeRequiresProvenanceAndMalformedBatchIsAtomic(): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture']);
        $this->db->update('domains', ['epp_code' => 'legacy-synthetic-epp'], 'id = :id', ['id' => $d->getId()]);
        $this->db->update('domain_registrar_operations', ['payload_json' => '{"token":"legacy-synthetic-input"}', 'result_json' => '{broken'], 'id = :id', ['id' => $op->getId()]);
        $before = $this->domainRows(); $upgrade = new \Coleza\Domain\Domains\DomainSecretStore($this->db, SecretFixtureKey::encryptor());
        try { $domains->findDomainById($d->getId()); self::fail('Plaintext EPP fallback accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->domainRows()); }
        try { $upgrade->upgrade(); self::fail('Unconfirmed upgrade accepted'); } catch (\LogicException) { self::assertSame($before, $this->domainRows()); }
        try { $upgrade->upgrade(true); self::fail('Malformed JSON accepted'); } catch (\JsonException) { self::assertSame($before, $this->domainRows()); }
        $this->db->update('domain_registrar_operations', ['result_json' => '{"token":"legacy-synthetic-result"}'], 'id = :id', ['id' => $op->getId()]);
        self::assertSame(3, $upgrade->upgrade(true)); self::assertSame(0, $upgrade->upgrade(true));
        self::assertSame('legacy-synthetic-epp', $domains->findDomainById($d->getId())->getEppCode()); self::assertSame(['token' => 'legacy-synthetic-result'], $ops->findById($op->getId())->getResult());
    }
    public function testCachedSuccessDoesNotRepeatCallbackAndDiagnosticsHideSecrets(): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $service = new \Coleza\Domain\Domains\Registrar\Reconciliation\IdempotentDomainOperationService($ops, new \Coleza\Domain\Domains\Registrar\DomainRegistrarService($domains, new \Coleza\Domain\Domains\Registrar\RegistrarRegistry()), $domains);
        $calls = 0; $callback = function () use (&$calls): \Coleza\Domain\Domains\Registrar\RegistrarOperationResult { $calls++; return \Coleza\Domain\Domains\Registrar\RegistrarOperationResult::success('transfer', 'secret.example', 'remote', '2028-01-01', ['token' => 'synthetic-result-token']); };
        $service->executeIdempotent($d->getId(), 'transfer', 'fixture', $callback); $service->executeIdempotent($d->getId(), 'transfer', 'fixture', $callback); self::assertSame(1, $calls);
        $event = $domains->recordTimelineEvent($d->getId(), 'diagnostic', 'Failed with synthetic-epp', ['epp_code' => 'synthetic-epp']);
        self::assertStringNotContainsString('synthetic-epp', $event->getDescription()); self::assertSame('********', $event->getPayload()['epp_code']);
        $raw = '{"epp_code":"legacy-synthetic-epp"}';
        $this->db->update('domain_timeline_events', ['description' => 'Legacy legacy-synthetic-epp', 'payload_json' => $raw], 'id = :id', ['id' => $event->getId()]);
        self::assertStringNotContainsString('legacy-synthetic-epp', json_encode(array_map(static fn($entry) => [$entry->getDescription(), $entry->getPayload()], $domains->getTimeline($d->getId()))));
        self::assertSame($raw, $this->db->selectOne('SELECT payload_json FROM domain_timeline_events WHERE id = ?', [$event->getId()])['payload_json']);
    }
    public function testConfiguredKeyIsRequiredBeforeEppOrOperationWrites(): void
    {
        [$domains] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $before = $this->domainRows(); $saved = [];
        foreach (['APP_KEY', 'APP_KEY_FILE'] as $name) { $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null]; putenv($name); unset($_ENV[$name], $_SERVER[$name]); }
        try {
            $plainDomains = new \Coleza\Domain\Domains\DomainService($this->db);
            $plainOps = new \Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($this->db);
            foreach ([fn() => $plainDomains->setEppCode($d->getId(), 'synthetic-epp'), fn() => $plainOps->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture'])] as $action) {
                try { $action(); self::fail('Missing key accepted'); } catch (\RuntimeException|\InvalidArgumentException) { self::assertSame($before, $this->domainRows()); }
            }
        } finally {
            foreach ($saved as $name => [$env, $arrayEnv, $serverEnv]) { $env === false ? putenv($name) : putenv($name . '=' . $env); if ($arrayEnv !== null) { $_ENV[$name] = $arrayEnv; } if ($serverEnv !== null) { $_SERVER[$name] = $serverEnv; } }
        }
    }
    public function testLegacyUpgradeCannotOverwriteAnExistingVaultOwner(): void
    {
        [$domains] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $domains->setEppCode($d->getId(), 'original-synthetic-epp');
        $this->db->update('domains', ['epp_code' => 'legacy-synthetic-epp'], 'id = :id', ['id' => $d->getId()]); $before = $this->domainRows();
        try { (new \Coleza\Domain\Domains\DomainSecretStore($this->db, SecretFixtureKey::encryptor()))->upgrade(true); self::fail('Conflicting owner accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->domainRows()); }
    }
}
