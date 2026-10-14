<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait AutomationSecretCases
{
    private function secretAutomation(): \Coleza\Domain\Automation\Engine\AutomationEngine
    {
        \Coleza\Tests\Support\AutomationStorageFixture::install($this->db);
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($this->db);
        \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter::installSchema($this->db);
        return \Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($this->db, new \Coleza\Domain\Automation\Actions\ActionRegistry(), null, SecretFixtureKey::encryptor());
    }
    public function testPendingSecretsAreEncryptedAndRestartPreservesExecutionData(): void
    {
        $engine = $this->secretAutomation();
        $context = new \Coleza\Domain\Automation\Triggers\TriggerContext('fixture', ['password' => 'automation-synthetic-password', 'unlabelled' => 'automation-synthetic-unlabelled', 'amount' => 1.0], correlationId: 'fixture-correlation');
        $actions = [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'fixture', ['token' => 'automation-synthetic-token'])];
        $entry = $engine->getApprovalManager()->createRequest('rule', 'Fixture', $context, $actions, new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
        $stored = json_encode($this->db->select('SELECT * FROM automation_pending'));
        foreach (['automation-synthetic-password', 'automation-synthetic-unlabelled', 'automation-synthetic-token'] as $secret) { self::assertStringNotContainsString($secret, $stored); }
        $fresh = new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, SecretFixtureKey::encryptor());
        self::assertSame($context->all(), $fresh->findById($entry->getId())->getContext()->all());
        self::assertSame($actions[0]->getParameters(), $fresh->findById($entry->getId())->getActionsToExecute()[0]->getParameters());
    }
    private function confidentialEntry(): \Coleza\Domain\Automation\Approval\PendingApproval
    {
        return $this->secretAutomation()->getApprovalManager()->createRequest('rule', 'Fixture',
            new \Coleza\Domain\Automation\Triggers\TriggerContext('fixture', ['password' => 'automation-synthetic-password']),
            [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'fixture', ['token' => 'automation-synthetic-token'])],
            new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
    }
    private function automationSecretRows(): array
    {
        $rows = []; foreach (['automation_pending', 'automation_run_history', 'automation_rule_versions', 'vault_secrets', 'security_audit_logs'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table}"); } return $rows;
    }
    private function confidentialRule(): \Coleza\Domain\Automation\Engine\AutomationRule
    {
        return new \Coleza\Domain\Automation\Engine\AutomationRule('secret-rule', 'Fixture', new \Coleza\Domain\Automation\Triggers\EventTrigger('fixture'),
            new \Coleza\Domain\Automation\Branching\IfElseBranch(new \Coleza\Domain\Automation\Conditions\FieldCondition('enabled', '==', true),
                [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'fixture', ['password' => 'rule-synthetic-password'])]));
    }
    public function testHistoryAndRuleSnapshotsAreEncryptedAndRestartExactly(): void
    {
        $engine = $this->secretAutomation();
        $version = $engine->getVersionManager()->recordVersion($this->confidentialRule(), 'actor', 'Fixture');
        $record = new \Coleza\Domain\Automation\Execution\AutomationRunRecord('secret-run', 'secret-rule', 'Fixture', 1, 'fixture', 'corr',
            \Coleza\Domain\Automation\Execution\ExecutionMode::ACTIVE, \Coleza\Domain\Automation\Execution\RunStatus::SUCCESS, true, 'THEN',
            [['token' => 'history-synthetic-token']], ['password' => 'history-synthetic-password']);
        $engine->getHistoryRepository()->save($record);
        $stored = json_encode([$this->db->select('SELECT * FROM automation_run_history'), $this->db->select('SELECT * FROM automation_rule_versions')]);
        foreach (['rule-synthetic-password', 'history-synthetic-password', 'history-synthetic-token'] as $secret) { self::assertStringNotContainsString($secret, $stored); }
        self::assertSame($version->toArray(), (new \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($this->db, SecretFixtureKey::encryptor()))->getVersion('secret-rule', 1)->toArray());
        self::assertSame($record->toArray(), (new \Coleza\Domain\Automation\Execution\DatabaseRunHistoryRepository($this->db, SecretFixtureKey::encryptor()))->findById('secret-run')->toArray());
        $before = $this->automationSecretRows();
        self::assertSame([], (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db, SecretFixtureKey::encryptor()))->listUnresolved());
        self::assertSame($before, $this->automationSecretRows());
    }
    public function testWrongKeyCannotReadOrChangePendingState(): void
    {
        $entry = $this->confidentialEntry(); $before = $this->automationSecretRows();
        $wrong = new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate()));
        foreach ([fn() => $wrong->findById($entry->getId()), fn() => $wrong->approve($entry->getId(), 'actor')] as $action) {
            try { $action(); self::fail('Wrong key accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->automationSecretRows()); }
        }
    }
    public function testSplicedOwnerReferenceIsRejected(): void
    {
        $a = $this->confidentialEntry();
        $manager = new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, SecretFixtureKey::encryptor());
        $b = $manager->createRequest('rule', 'Other', new \Coleza\Domain\Automation\Triggers\TriggerContext('fixture'), [], new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
        $ref = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$a->getId()])['payload'];
        $this->db->update('automation_pending', ['payload' => $ref], 'id = :id', ['id' => $b->getId()]);
        $this->expectException(\RuntimeException::class); $manager->findById($b->getId());
    }
    public function testLateOwnerFailureRollsBackVaultAndAuditOnAddAndMutation(): void
    {
        $entry = $this->confidentialEntry(); $before = $this->automationSecretRows();
        foreach (['INSERT', 'UPDATE'] as $verb) {
            $trigger = 'automation_secret_fail_' . strtolower($verb);
            $this->db->statement($this->db->getDriverName() === 'mysql'
                ? "CREATE TRIGGER {$trigger} BEFORE {$verb} ON automation_pending FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner failure'"
                : "CREATE TRIGGER {$trigger} BEFORE {$verb} ON automation_pending BEGIN SELECT RAISE(ABORT, 'owner failure'); END");
        }
        $manager = new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, SecretFixtureKey::encryptor());
        foreach ([fn() => $manager->createRequest('rule', 'Other', new \Coleza\Domain\Automation\Triggers\TriggerContext('fixture'), [], new \Coleza\Domain\Automation\Approval\ApprovalRequirement()),
            fn() => $manager->approve($entry->getId(), 'actor')] as $action) {
            try { $action(); self::fail('Owner failure ignored'); } catch (\PDOException) { self::assertSame($before, $this->automationSecretRows()); }
        }
    }
    public static function secretAutomationCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('secretAutomationCallers')]
    public function testParentRollbackIncludesPendingVaultAndAudit(string $caller): void
    {
        $engine = $this->secretAutomation(); $before = $this->automationSecretRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try {
            $entry = $engine->getApprovalManager()->createRequest('rule', 'Fixture', new \Coleza\Domain\Automation\Triggers\TriggerContext('fixture', ['token' => 'synthetic-token']), [], new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
            $engine->getApprovalManager()->approve($entry->getId(), 'actor'); self::assertTrue($this->db->inTransaction());
        } finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
        self::assertSame($before, $this->automationSecretRows());
    }
    public function testLegacyUpgradeIsExplicitAtomicAndRejectsConflictingOwners(): void
    {
        $entry = $this->confidentialEntry();
        $json = \Coleza\Domain\Automation\Persistence\AutomationCodec::encode(\Coleza\Domain\Automation\Persistence\AutomationCodec::pending($entry));
        $this->db->update('automation_pending', ['payload' => $json], 'id = :id', ['id' => $entry->getId()]);
        $upgrade = new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($this->db, SecretFixtureKey::encryptor());
        $before = $this->automationSecretRows();
        foreach ([fn() => $upgrade->upgrade(), fn() => $upgrade->upgrade(true), fn() => (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, SecretFixtureKey::encryptor()))->findById($entry->getId())] as $action) {
            try { $action(); self::fail('Unconfirmed/conflicting legacy data accepted'); } catch (\LogicException|\RuntimeException) { self::assertSame($before, $this->automationSecretRows()); }
        }
        $this->db->statement('DELETE FROM vault_secrets');
        $this->db->update('automation_pending', ['payload' => '{broken'], 'id = :id', ['id' => $entry->getId()]);
        $before = $this->automationSecretRows();
        try { $upgrade->upgrade(true); self::fail('Malformed legacy JSON accepted'); } catch (\JsonException) { self::assertSame($before, $this->automationSecretRows()); }
        $this->db->update('automation_pending', ['payload' => $json], 'id = :id', ['id' => $entry->getId()]);
        self::assertSame(1, $upgrade->upgrade(true)); self::assertSame(0, $upgrade->upgrade(true));
        self::assertSame($entry->getContext()->all(), (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db, SecretFixtureKey::encryptor()))->findById($entry->getId())->getContext()->all());
    }
    public static function immutableSecretOwners(): array { return [['history'], ['version']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('immutableSecretOwners')]
    public function testLateImmutableOwnerFailureLeavesNoVaultOrAuditOrVersionChange(string $kind): void
    {
        $engine = $this->secretAutomation(); $before = $this->automationSecretRows();
        $table = $kind === 'history' ? 'automation_run_history' : 'automation_rule_versions';
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER immutable_secret_fail BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner failure'"
            : "CREATE TRIGGER immutable_secret_fail BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'owner failure'); END");
        $rule = $this->confidentialRule(); $version = $rule->getVersion();
        try {
            if ($kind === 'version') { $engine->getVersionManager()->recordVersion($rule, 'actor', 'Fixture'); }
            else { $engine->getHistoryRepository()->save(new \Coleza\Domain\Automation\Execution\AutomationRunRecord('run', 'rule', 'Fixture', 1, 'fixture', 'corr',
                \Coleza\Domain\Automation\Execution\ExecutionMode::ACTIVE, \Coleza\Domain\Automation\Execution\RunStatus::SUCCESS, true, 'THEN', contextSummary: ['password' => 'synthetic-password'])); }
            self::fail('Immutable owner failure ignored');
        } catch (\PDOException) { self::assertSame($before, $this->automationSecretRows()); self::assertSame($version, $rule->getVersion()); }
    }
    public function testFactoryWithMissingConfiguredKeyFailsBeforeAnyEffect(): void
    {
        $this->secretAutomation(); $before = $this->automationSecretRows(); $saved = [];
        foreach (['APP_KEY', 'APP_KEY_FILE'] as $name) { $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null]; putenv($name); unset($_ENV[$name], $_SERVER[$name]); }
        try {
            try { \Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($this->db, new \Coleza\Domain\Automation\Actions\ActionRegistry()); self::fail('Missing configured key accepted'); }
            catch (\RuntimeException|\InvalidArgumentException) { self::assertSame($before, $this->automationSecretRows()); }
        } finally {
            foreach ($saved as $name => [$env, $arrayEnv, $serverEnv]) { $env === false ? putenv($name) : putenv($name . '=' . $env); if ($arrayEnv !== null) { $_ENV[$name] = $arrayEnv; } if ($serverEnv !== null) { $_SERVER[$name] = $serverEnv; } }
        }
    }
}
