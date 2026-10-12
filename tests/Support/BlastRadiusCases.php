<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Automation\Engine\{AutomationRule, DatabaseAutomationFactory};
use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionRegistry};
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Triggers\{TriggerContext, EventTrigger};
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Persistence\AutomationSchema;
use Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager;

trait BlastRadiusCases
{
    private function installBlastRadius(): void
    {
        AutomationSchema::installSchema($this->db);
        DatabaseRuleVersionManager::installSchema($this->db);
        \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter::installSchema($this->db);
    }
    private function destructiveRule(string $type = 'service.terminate'): AutomationRule
    {
        return new AutomationRule('destructive', 'Destructive', new EventTrigger('event'),
            new IfElseBranch(new FieldCondition('ok', '==', true), [new ActionDefinition('a', $type)]));
    }
    public function testFreshEnginesShareOneBudget(): void
    {
        $this->installBlastRadius();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        for ($i = 0; $i < 7; $i++) {
            DatabaseAutomationFactory::create($this->db, $registry)->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]));
        }
        self::assertSame(5, $calls, 'Reconstruction must not reset the shared destructive budget');
    }
    public function testUncertainFailuresStillConsumeBudgetBeforeCallback(): void
    {
        $this->installBlastRadius();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('Response lost after a possible external effect');
        }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        for ($i = 0; $i < 7; $i++) { $engine->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true])); }
        self::assertSame(5, $calls, 'Unknown external outcomes must not release destructive permits');
    }

    private function limiter(): \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter
    {
        return new \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter($this->db);
    }
    public function testDifferentDestructiveTypesShareBudgetAndDeniedAttemptsDoNotInflateIt(): void
    {
        $this->installBlastRadius();
        $limiter = $this->limiter();
        foreach (['service.terminate', 'server.destroy', 'account.purge', 'domain.delete', 'database.drop'] as $type) {
            self::assertTrue($limiter->tryAcquire($type, 'target'));
        }
        for ($i = 0; $i < 3; $i++) { self::assertFalse($this->limiter()->tryAcquire('service.cancel')); }
        self::assertSame(5, $limiter->getRecentDestructiveCount());
        self::assertCount(5, $this->db->select('SELECT * FROM automation_destructive_permits'));
        self::assertTrue($limiter->getEmergencyPauseManager()->isPaused('service.terminate'));
        self::assertSame('DESTRUCTIVE', $limiter->getEmergencyPauseManager()->getState()->getScope());
        self::assertTrue($limiter->tryAcquire('notification.send'));
        self::assertSame(5, $limiter->getRecentDestructiveCount());
    }
    public function testResumeDoesNotClearBudgetOrRewriteStrongerPause(): void
    {
        $this->installBlastRadius();
        $limiter = $this->limiter();
        for ($i = 0; $i < 5; $i++) { self::assertTrue($limiter->tryAcquire('service.terminate')); }
        $pause = $limiter->getEmergencyPauseManager();
        $pause->pause('operator', 'Full containment', 'ALL');
        self::assertFalse($limiter->tryAcquire('service.terminate'));
        self::assertSame('ALL', $pause->getState()->getScope());
        self::assertSame('operator', $pause->getState()->getPausedBy());
        $pause->resume('operator', 'Reviewed');
        self::assertFalse($limiter->tryAcquire('service.terminate'));
        self::assertSame(5, $limiter->getRecentDestructiveCount());
        self::assertSame('DESTRUCTIVE', $pause->getState()->getScope());
    }
    public function testWindowExpiryUsesDatabaseTimeAndRetainsAudit(): void
    {
        $this->installBlastRadius();
        $now = (int) $this->db->selectOne($this->db->getDriverName() === 'mysql' ? 'SELECT UNIX_TIMESTAMP() AS n' : "SELECT CAST(strftime('%s', 'now') AS INTEGER) AS n")['n'];
        for ($i = 0; $i < 5; $i++) {
            $this->db->insert('automation_destructive_permits', ['id' => 'old_' . $i, 'action_type' => 'service.terminate', 'admitted_at' => $now - 3610]);
        }
        $this->db->insert('automation_destructive_permits', ['id' => 'recent', 'action_type' => 'server.destroy', 'admitted_at' => $now - 3500]);
        self::assertSame(1, $this->limiter()->getRecentDestructiveCount());
        self::assertTrue($this->limiter()->tryAcquire('service.terminate'));
        self::assertSame(2, $this->limiter()->getRecentDestructiveCount());
        self::assertCount(7, $this->db->select('SELECT * FROM automation_destructive_permits'));
    }
    public function testMissingHandlerDryRunAndObserveNeverConsumePermits(): void
    {
        $this->installBlastRadius();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        foreach ([\Coleza\Domain\Automation\Execution\ExecutionMode::DRY_RUN, \Coleza\Domain\Automation\Execution\ExecutionMode::OBSERVE] as $mode) {
            $rule = $this->destructiveRule();
            $rule->setExecutionMode($mode);
            $engine->executeRule($rule, new TriggerContext('event', ['ok' => true]));
        }
        self::assertFalse($engine->executeRule($this->destructiveRule('server.destroy'), new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(0, $calls);
        self::assertSame(0, $this->limiter()->getRecentDestructiveCount());
    }
    public function testCallerTransactionCannotErasePermitAfterExternalEffect(): void
    {
        $this->installBlastRadius();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        $this->db->beginTransaction();
        try {
            $result = $engine->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]));
            self::assertFalse($result->isSuccessful());
            self::assertStringContainsString('outside the caller transaction', $result->getActionResults()[0]->getErrorMessage());
            self::assertSame(0, $calls);
        } finally { $this->db->rollBack(); }
        self::assertSame(0, $this->limiter()->getRecentDestructiveCount());
        self::assertTrue($engine->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(1, $calls);
    }
    public function testRawPdoTransactionIsAlsoRejected(): void
    {
        $this->installBlastRadius();
        $limiter = $this->limiter();
        $this->db->getPdo()->beginTransaction();
        try {
            try { $limiter->tryAcquire('service.terminate'); self::fail('Raw transaction accepted'); }
            catch (\LogicException $error) { self::assertStringContainsString('committed boundary', $error->getMessage()); }
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertSame(0, $limiter->getRecentDestructiveCount());
    }
    public function testPermitIsCommittedBeforeHandlerAndNotReleasedOnReturnedFailure(): void
    {
        $this->installBlastRadius();
        $registry = new ActionRegistry();
        $inTransaction = null; $countAtCallback = null;
        $registry->register(new CallbackActionHandler('service.terminate', function ($action) use (&$inTransaction, &$countAtCallback) {
            $inTransaction = $this->db->inTransaction();
            $countAtCallback = $this->limiter()->getRecentDestructiveCount();
            return \Coleza\Domain\Automation\Actions\ActionResult::failed($action->getId(), $action->getType(), 'uncertain');
        }));
        self::assertFalse(DatabaseAutomationFactory::create($this->db, $registry)->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertFalse($inTransaction);
        self::assertSame(1, $countAtCallback);
        self::assertSame(1, $this->limiter()->getRecentDestructiveCount());
    }
    public function testAdvisoryCheckCannotReserveOrReplaceAtomicAdmission(): void
    {
        $this->installBlastRadius();
        $one = $this->limiter(); $two = $this->limiter();
        for ($i = 0; $i < 4; $i++) { self::assertTrue($one->tryAcquire('service.terminate')); }
        self::assertTrue($one->canExecute('service.terminate'));
        self::assertTrue($two->canExecute('service.terminate'));
        self::assertTrue($one->tryAcquire('service.terminate'));
        self::assertFalse($two->tryAcquire('service.terminate'));
        self::assertSame(5, $two->getRecentDestructiveCount());
    }
    public function testMissingPolicyFailsFactoryClosed(): void
    {
        $this->installBlastRadius();
        $this->db->statement('DELETE FROM automation_blast_policy');
        $this->expectException(\RuntimeException::class);
        DatabaseAutomationFactory::create($this->db, new ActionRegistry());
    }
    public function testInconsistentWorkerPolicyIsRefused(): void
    {
        $this->installBlastRadius();
        $this->expectException(\RuntimeException::class);
        new \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter($this->db, 100, 10);
    }
    public function testPolicyChangeAfterConstructionFailsBeforeHandler(): void
    {
        $this->installBlastRadius();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        $this->db->statement('UPDATE automation_blast_policy SET max_actions = 100 WHERE id = 1');
        self::assertFalse($engine->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(0, $calls);
        self::assertSame(0, $this->limiterCount());
    }
    private function limiterCount(): int
    {
        return count($this->db->select('SELECT id FROM automation_destructive_permits'));
    }
    private function failingTrigger(string $table): void
    {
        $event = $table === 'automation_destructive_permits' ? 'INSERT' : 'UPDATE';
        $sql = $this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_blast_write BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture refusal'"
            : "CREATE TRIGGER reject_blast_write BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'fixture refusal'); END";
        $this->db->statement($sql);
    }
    public function testPermitInsertFailureCannotInvokeHandler(): void
    {
        $this->installBlastRadius();
        $this->failingTrigger('automation_destructive_permits');
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        self::assertFalse($engine->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(0, $calls);
        self::assertSame(0, $this->limiterCount());
        $this->db->statement('DROP TRIGGER reject_blast_write');
        self::assertTrue($engine->executeRule($this->destructiveRule(), new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(1, $calls);
    }
    public function testPauseWriteFailureCannotGrantSixthPermit(): void
    {
        $this->installBlastRadius();
        $limiter = $this->limiter();
        for ($i = 0; $i < 5; $i++) { self::assertTrue($limiter->tryAcquire('service.terminate')); }
        $this->failingTrigger('automation_emergency_pause');
        try { $limiter->tryAcquire('service.terminate'); self::fail('Pause write failure ignored'); }
        catch (\PDOException) { self::assertSame(5, $limiter->getRecentDestructiveCount()); }
        self::assertFalse($limiter->getEmergencyPauseManager()->isPaused());
        $this->db->statement('DROP TRIGGER reject_blast_write');
        self::assertFalse($limiter->tryAcquire('service.terminate'));
        self::assertTrue($limiter->getEmergencyPauseManager()->isPaused('service.terminate'));
    }
    public function testMissingGlobalLockCannotAdmit(): void
    {
        $this->installBlastRadius();
        $limiter = $this->limiter();
        $this->db->statement('DELETE FROM automation_state_lock');
        $this->expectException(\RuntimeException::class);
        $limiter->tryAcquire('service.terminate');
    }
    public function testRepeatedMigrationPreservesPolicyPermitsAndPause(): void
    {
        $this->installBlastRadius();
        $limiter = $this->limiter();
        self::assertTrue($limiter->tryAcquire('service.terminate', 'service_42'));
        $limiter->getEmergencyPauseManager()->pause('operator', 'Investigate', 'ALL');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000015_automation_destructive_budget.php';
        $migration->up($this->db);
        $migration->up($this->db);
        self::assertSame(1, $this->limiter()->getRecentDestructiveCount());
        self::assertSame('service_42', $this->db->selectOne('SELECT target_id FROM automation_destructive_permits')['target_id']);
        self::assertSame('ALL', $this->limiter()->getEmergencyPauseManager()->getState()->getScope());
        $this->db->statement('UPDATE automation_blast_policy SET max_actions = 7 WHERE id = 1');
        $migration->up($this->db);
        self::assertSame(7, (int) $this->db->selectOne('SELECT max_actions FROM automation_blast_policy')['max_actions']);
    }
    public function testMissingLedgerRequiresMigrationAndNoRuntimeDdl(): void
    {
        $this->installBlastRadius();
        $this->db->statement('DROP TABLE automation_destructive_permits');
        $this->expectException(\PDOException::class);
        DatabaseAutomationFactory::create($this->db, new ActionRegistry());
    }
    public function testApprovedPathUsesSharedBudgetBeforeDestructiveHandler(): void
    {
        $this->installBlastRadius();
        for ($i = 0; $i < 5; $i++) { self::assertTrue($this->limiter()->tryAcquire('server.destroy')); }
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        $pending = $engine->getApprovalManager()->createRequest('r', 'Approved destructive', new TriggerContext('event'),
            [new ActionDefinition('a', 'service.terminate')], new \Coleza\Domain\Automation\Approval\ApprovalRequirement(), ['delay_seconds' => 0]);
        self::assertFalse($engine->executeApprovedRequest($pending->getId(), 'operator')->isSuccessful());
        self::assertSame(0, $calls);
        self::assertSame(5, $this->limiter()->getRecentDestructiveCount());
    }
    public function testDueDelayedPathUsesSharedBudgetBeforeDestructiveHandler(): void
    {
        $this->installBlastRadius();
        for ($i = 0; $i < 5; $i++) { self::assertTrue($this->limiter()->tryAcquire('server.destroy')); }
        $entry = new \Coleza\Domain\Automation\Delay\DelayedExecution('due_destructive', 'r', 'Due destructive', new TriggerContext('event'),
            [new ActionDefinition('a', 'service.terminate')], new \DateTimeImmutable('-1 hour'));
        $this->db->insert('automation_pending', ['id' => $entry->getId(), 'kind' => 'delay', 'status' => 'SCHEDULED',
            'payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode(\Coleza\Domain\Automation\Persistence\AutomationCodec::pending($entry))]);
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('service.terminate', function () use (&$calls) { $calls++; return []; }));
        $results = DatabaseAutomationFactory::create($this->db, $registry)->dispatchDueDelayedExecutions();
        self::assertCount(1, $results);
        self::assertFalse($results[$entry->getId()]->isSuccessful());
        self::assertSame(0, $calls);
        self::assertSame(5, $this->limiter()->getRecentDestructiveCount());
    }
    public function testNonDestructiveExecutionContinuesAfterDestructiveContainment(): void
    {
        $this->installBlastRadius();
        for ($i = 0; $i < 5; $i++) { self::assertTrue($this->limiter()->tryAcquire('server.destroy')); }
        self::assertFalse($this->limiter()->tryAcquire('service.terminate'));
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('notification.send', function () use (&$calls) { $calls++; return []; }));
        self::assertTrue(DatabaseAutomationFactory::create($this->db, $registry)->executeRule($this->destructiveRule('notification.send'),
            new TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(1, $calls);
        self::assertSame(5, $this->limiter()->getRecentDestructiveCount());
    }
    public function testUpgradeFromPreBudgetSchemaPreservesPendingVersionAndPause(): void
    {
        // The actual pre-15 shape has no budget tables; runtime factory must not silently create them.
        AutomationSchema::installSchema($this->db);
        DatabaseRuleVersionManager::installSchema($this->db);
        $pending = (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db))->createRequest('legacy_r', 'Existing approval',
            new TriggerContext('event', ['service_id' => 42]), [new ActionDefinition('a', 'service.terminate')],
            new \Coleza\Domain\Automation\Approval\ApprovalRequirement(), ['delay_seconds' => 0]);
        $version = (new DatabaseRuleVersionManager($this->db))->recordVersion($this->destructiveRule(), 'author', 'Before budget upgrade');
        $pause = new \Coleza\Domain\Automation\Safety\DatabaseEmergencyPauseManager($this->db);
        $pause->pause('operator', 'Upgrade containment', 'ALL');
        $before = [$this->db->select('SELECT * FROM automation_pending'), $this->db->select('SELECT * FROM automation_rule_versions'), $pause->getState()->toArray()];
        try { DatabaseAutomationFactory::create($this->db, new ActionRegistry()); self::fail('Factory silently upgraded missing budget'); }
        catch (\PDOException) { self::assertNotEmpty($before[0]); }
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000015_automation_destructive_budget.php';
        $migration->up($this->db);
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        self::assertSame($before, [$this->db->select('SELECT * FROM automation_pending'), $this->db->select('SELECT * FROM automation_rule_versions'), $pause->getState()->toArray()]);
        self::assertSame($pending->toArray(), $engine->getApprovalManager()->findById($pending->getId())->toArray());
        self::assertSame($version->toArray(), $engine->getVersionManager()->getVersion($this->destructiveRule()->getId(), 1)->toArray());
        self::assertSame(0, $engine->getBlastRadiusLimiter()->getRecentDestructiveCount());
        self::assertFalse($engine->getBlastRadiusLimiter()->tryAcquire('service.terminate'));
    }
}
