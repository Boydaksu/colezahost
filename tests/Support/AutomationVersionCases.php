<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Automation\Engine\{AutomationRule, DatabaseAutomationFactory};
use Coleza\Domain\Automation\Triggers\{EventTrigger, ManualTrigger, ScheduleTrigger, TriggerContext};
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\{ConditionGroup, FieldCondition};
use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionRegistry};
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Versioning\{DatabaseRuleVersionManager, RuleSnapshotCodec};
use Coleza\Domain\Automation\Persistence\{AutomationCodec, AutomationSchema};
use Coleza\Domain\Automation\Execution\{AutomationRecoveryInspector, AutomationRunRecord, DatabaseRunHistoryRepository, ExecutionMode, RunStatus};
use Coleza\Domain\Automation\Approval\ApprovalRequirement;

trait AutomationVersionCases
{
    private function versionInstall(): void
    {
        AutomationSchema::installSchema($this->db);
        DatabaseRuleVersionManager::installSchema($this->db);
    }
    private function rule(int $value = 1, $trigger = null, ?ApprovalRequirement $approval = null, int $delay = 0): AutomationRule
    {
        return new AutomationRule('versioned_rule', 'Rule', $trigger ?? new EventTrigger('invoice.*', ['brand' => 7]),
            new IfElseBranch(ConditionGroup::and(new FieldCondition('paid', '==', true), ConditionGroup::or(new FieldCondition('amount', '>', $value), new FieldCondition('vip', '==', true))),
                [new ActionDefinition('then', 'send', ['value' => $value, 'invoice' => '{{ invoice_id }}'])],
                [new ActionDefinition('else', 'send', ['value' => -$value])]),
            priority: 5, description: 'Türkçe kural', metadata: ['policy' => 'fixture'], approvalRequirement: $approval, delaySeconds: $delay);
    }
    public function testFullSnapshotSurvivesReconstructionAndRestoresBehavior(): void
    {
        $this->versionInstall();
        $rule = $this->rule(100, approval: new ApprovalRequirement('billing', 3600, 'Review'), delay: 60);
        $record = (new DatabaseRuleVersionManager($this->db))->recordVersion($rule, 'actor', 'Initial');
        $stored = (new DatabaseRuleVersionManager($this->db))->getVersion($rule->getId(), 1);
        self::assertSame($record->toArray(), $stored->toArray());
        $restored = RuleSnapshotCodec::restore($stored->getSnapshot(), 1);
        self::assertSame(RuleSnapshotCodec::snapshot($rule), RuleSnapshotCodec::snapshot($restored));
        $context = new TriggerContext('invoice.paid', ['brand' => 7, 'paid' => true, 'vip' => true, 'invoice_id' => 42]);
        self::assertTrue($restored->matchesTrigger($context));
        self::assertSame('THEN', $restored->getBranch()->determineBranch($context));
        self::assertSame(['value' => 100, 'invoice' => '42'], $restored->getBranch()->getThenActions()[0]->resolveParameters($context));
        self::assertFalse($restored->matchesTrigger($context->with('brand', 8)));
        self::assertSame(60, $restored->getDelaySeconds());
        self::assertSame('billing', $restored->getApprovalRequirement()->getRequiredRole());
    }
    public function testTriggerKindsRoundTripWithoutLosingScopeOrInterval(): void
    {
        $this->versionInstall();
        foreach ([new ManualTrigger(''), new ManualTrigger(null), new ManualTrigger('billing'), new ScheduleTrigger('hourly')] as $trigger) {
            $rule = $this->rule(trigger: $trigger);
            $record = (new DatabaseRuleVersionManager($this->db))->recordVersion($rule, 'actor', 'Trigger');
            $restored = RuleSnapshotCodec::restore($record->getSnapshot(), $record->getVersionNumber());
            self::assertSame(RuleSnapshotCodec::snapshot($rule), RuleSnapshotCodec::snapshot($restored));
        }
    }
    public function testChangesAndLatestVersionPersistWithoutOverwritingOldVersion(): void
    {
        $this->versionInstall();
        $manager = new DatabaseRuleVersionManager($this->db);
        $first = $manager->recordVersion($this->rule(10), 'alice', 'First');
        $manager->recordVersion($this->rule(20, new EventTrigger('service.*', ['brand' => 8])), 'bob', 'Behavior change');
        $again = new DatabaseRuleVersionManager($this->db);
        self::assertSame($first->toArray(), $again->getVersion('versioned_rule', 1)->toArray());
        self::assertSame(2, $again->getLatestVersion('versioned_rule')->getVersionNumber());
        self::assertSame([1, 2], array_map(static fn($v) => $v->getVersionNumber(), $again->getVersions('versioned_rule')));
        self::assertArrayHasKey('branch', $again->diff('versioned_rule', 1, 2));
        self::assertArrayHasKey('trigger', $again->diff('versioned_rule', 1, 2));
        self::assertNull($again->getVersion('missing', 1));
        $this->expectException(\InvalidArgumentException::class);
        $again->diff('versioned_rule', 1, 9);
    }
    public function testOldApprovedElseBranchUsesOriginalActionsAndVersionAfterNewPublication(): void
    {
        $this->versionInstall();
        $registry = new ActionRegistry();
        $calls = [];
        $registry->register(new CallbackActionHandler('send', function ($action, $context) use (&$calls) { $calls[] = $action->resolveParameters($context); return ['sent' => true]; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        $rule = $this->rule(10, approval: new ApprovalRequirement());
        $engine->getVersionManager()->recordVersion($rule, 'alice', 'First');
        $pending = $engine->executeVersion($rule->getId(), 1, new TriggerContext('invoice.paid', ['brand' => 7, 'paid' => false]));
        self::assertSame('ELSE', $pending->getBranchTaken());
        $engine->getVersionManager()->recordVersion($this->rule(99, approval: new ApprovalRequirement()), 'bob', 'New');
        $again = DatabaseAutomationFactory::create($this->db, $registry);
        $result = $again->executeApprovedRequest($pending->getApprovalId(), 'operator');
        self::assertSame([['value' => -10]], $calls);
        self::assertFalse($result->isConditionPassed());
        self::assertSame('ELSE', $result->getBranchTaken());
        $run = $again->getHistoryRepository()->listRecent()[0];
        self::assertSame(1, $run->getRuleVersion());
        self::assertSame('ELSE', $run->getBranchTaken());
        self::assertFalse($run->getMetadata()['rule_version_unknown']);
        self::assertFalse($run->getMetadata()['condition_unknown']);
        self::assertSame([], (new AutomationRecoveryInspector($this->db))->listUnresolved());
    }
    public function testDelayedProvenancePersistsAndCannotBeReplacedByCurrentRule(): void
    {
        $this->versionInstall();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $rule = $this->rule(10, delay: 3600);
        $engine->getVersionManager()->recordVersion($rule, 'actor', 'First');
        $result = $engine->executeVersion($rule->getId(), 1, new TriggerContext('invoice.paid', ['brand' => 7, 'paid' => false]));
        $again = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $entry = $again->getDelayManager()->findById($result->getDelayId());
        self::assertSame(1, $entry->getExecutionSnapshot()['rule_version']);
        self::assertSame('ELSE', $entry->getExecutionSnapshot()['branch_taken']);
        self::assertFalse($entry->getExecutionSnapshot()['condition_passed']);
        self::assertSame(64, strlen($entry->getExecutionSnapshot()['rule_fingerprint']));
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$entry->getId()]);
        $payload = AutomationCodec::decode($row['payload']);
        $payload['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP'); // Simulate the passage of time in this isolated fixture.
        $this->db->update('automation_pending', ['payload' => AutomationCodec::encode($payload)], 'id = :id', ['id' => $entry->getId()]);
        $calls = [];
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function ($action, $context) use (&$calls) { $calls[] = $action->resolveParameters($context); return ['sent' => true]; }));
        $again->getVersionManager()->recordVersion($this->rule(99, delay: 3600), 'other', 'New version');
        $dispatch = DatabaseAutomationFactory::create($this->db, $registry)->dispatchDueDelayedExecutions();
        self::assertSame([['value' => -10]], $calls);
        self::assertSame('ELSE', $dispatch[$entry->getId()]->getBranchTaken());
        self::assertSame(1, $again->getHistoryRepository()->listRecent()[0]->getRuleVersion());
        self::assertSame([], (new AutomationRecoveryInspector($this->db))->listUnresolved());
        $rule->setName('Unpublished change');
        $this->expectException(\LogicException::class);
        $again->executeRule($rule, new TriggerContext('invoice.paid', ['brand' => 7]));
    }
    public function testSchemaRerunAndRollbackPreserveLedger(): void
    {
        $this->versionInstall();
        $manager = new DatabaseRuleVersionManager($this->db);
        $first = $manager->recordVersion($this->rule(), 'actor', 'First');
        $this->versionInstall();
        self::assertSame($first->toArray(), $manager->getLatestVersion('versioned_rule')->toArray());
        $this->db->beginTransaction();
        $manager->recordVersion($this->rule(2), 'actor', 'Rolled back');
        $this->db->rollBack();
        self::assertCount(1, $manager->getVersions('versioned_rule'));
        self::assertSame(2, $manager->recordVersion($this->rule(3), 'actor', 'Next')->getVersionNumber());
    }
    public function testInvalidObjectInRuleIsRejectedWithoutVersionOrSequenceConsumption(): void
    {
        $this->versionInstall();
        $manager = new DatabaseRuleVersionManager($this->db);
        $bad = new AutomationRule('r', 'Bad', new EventTrigger('event'), new IfElseBranch(new FieldCondition('field', '==', new \stdClass())));
        try { $manager->recordVersion($bad, 'actor', 'Invalid'); self::fail('Object persisted'); }
        catch (\InvalidArgumentException) { self::assertSame([], $manager->getVersions('r')); }
        self::assertSame(1, $manager->recordVersion($this->rule(), 'actor', 'First')->getVersionNumber());
    }
    public function testUnknownVersionAndCorruptedSnapshotFailBeforeExecution(): void
    {
        $this->versionInstall();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        try { $engine->executeVersion('missing', 1, new TriggerContext('event')); self::fail('Missing version executed'); }
        catch (\InvalidArgumentException) { self::assertSame(0, $engine->getHistoryRepository()->count()); }
        $record = $engine->getVersionManager()->recordVersion($this->rule(), 'actor', 'First');
        $data = $record->toArray(); $data['snapshot']['id'] = 'other_rule';
        $this->db->update('automation_rule_versions', ['payload' => AutomationCodec::encode($data)], 'rule_id = :id', ['id' => 'versioned_rule']);
        $this->expectException(\UnexpectedValueException::class);
        $engine->executeVersion('versioned_rule', 1, new TriggerContext('invoice.paid'));
    }
    public function testRecoveryInspectorReportsUnfinishedApprovalAndNeverRetries(): void
    {
        $this->versionInstall();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $entry = $engine->getApprovalManager()->createRequest('r', 'Ask', new TriggerContext('event'), [new ActionDefinition('a', 'send')], new ApprovalRequirement());
        $engine->getApprovalManager()->approve($entry->getId(), 'actor'); // Simulate termination immediately after persisted decision.
        $before = $this->db->select('SELECT * FROM automation_pending');
        $unresolved = (new AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertCount(1, $unresolved);
        self::assertSame('MISSING_OUTCOME', $unresolved[0]['reason']);
        self::assertFalse($unresolved[0]['automatic_retry_allowed']);
        self::assertSame($before, $this->db->select('SELECT * FROM automation_pending'));
        self::assertSame(0, $engine->getHistoryRepository()->count());
    }
    public function testRecoveryInspectorDoesNotTreatFailedOrUnrelatedHistoryAsSuccess(): void
    {
        $this->versionInstall();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $context = new TriggerContext('event', correlationId: 'original');
        $entry = $engine->getApprovalManager()->createRequest('r', 'Ask', $context, [], new ApprovalRequirement());
        $engine->getApprovalManager()->approve($entry->getId(), 'actor');
        foreach ([['foreign', 'other', RunStatus::SUCCESS], ['failed', 'original', RunStatus::FAILED]] as [$id, $correlation, $status]) {
            $engine->getHistoryRepository()->save(new AutomationRunRecord($id, 'r', 'Ask', 0, 'event', $correlation, ExecutionMode::ACTIVE, $status,
                true, 'THEN', metadata: ['approval_id' => $entry->getId()]));
        }
        $unresolved = (new AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertCount(1, $unresolved);
        self::assertSame('AMBIGUOUS_OR_FAILED_OUTCOME', $unresolved[0]['reason']);
    }

    public function testFailedVersionInsertRollsBackAndDoesNotConsumeNumber(): void
    {
        $this->versionInstall();
        $manager = new DatabaseRuleVersionManager($this->db);
        $manager->recordVersion($this->rule(), 'actor', 'First');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER version_insert_fail BEFORE INSERT ON automation_rule_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected write failure'"
            : "CREATE TRIGGER version_insert_fail BEFORE INSERT ON automation_rule_versions BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");
        $candidate = $this->rule(2)->setVersion(99);
        try { $manager->recordVersion($candidate, 'actor', 'Must fail'); self::fail('Injected failure ignored'); }
        catch (\PDOException) { self::assertSame(99, $candidate->getVersion()); }
        self::assertCount(1, $manager->getVersions('versioned_rule'));
        $this->db->statement('DROP TRIGGER version_insert_fail');
        self::assertSame(2, $manager->recordVersion($candidate, 'actor', 'Retry')->getVersionNumber());
    }

    public function testUnfinishedDelayedClaimIsVisibleAfterReconstruction(): void
    {
        $this->versionInstall();
        $entry = new \Coleza\Domain\Automation\Delay\DelayedExecution('inflight', 'r', 'Delay', new TriggerContext('event'), [], new \DateTimeImmutable('-1 hour'));
        $this->db->insert('automation_pending', ['id' => $entry->getId(), 'kind' => 'delay', 'status' => 'SCHEDULED', 'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))]);
        self::assertTrue(DatabaseAutomationFactory::create($this->db, new ActionRegistry())->getDelayManager()->claimForDispatch($entry->getId()));
        $before = $this->db->select('SELECT * FROM automation_pending');
        $items = (new AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertSame('DISPATCHING', $items[0]['status']);
        self::assertSame('MISSING_OUTCOME', $items[0]['reason']);
        self::assertSame($before, $this->db->select('SELECT * FROM automation_pending'));
    }

    public function testInvalidProvenanceIsRejectedBeforePendingInsert(): void
    {
        $this->versionInstall();
        $manager = DatabaseAutomationFactory::create($this->db, new ActionRegistry())->getApprovalManager();
        try {
            $manager->createRequest('r', 'Bad', new TriggerContext('event'), [], new ApprovalRequirement(),
                ['rule_version' => 1, 'condition_passed' => true, 'branch_taken' => 'ELSE', 'rule_fingerprint' => null]);
            self::fail('Invalid execution evidence accepted');
        } catch (\InvalidArgumentException) { self::assertSame([], $manager->listPending()); }
    }
}
