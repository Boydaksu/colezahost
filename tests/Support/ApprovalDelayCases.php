<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Automation\Engine\{AutomationRule, DatabaseAutomationFactory};
use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionRegistry};
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Triggers\{TriggerContext, EventTrigger};
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Approval\ApprovalRequirement;
use Coleza\Domain\Automation\Persistence\AutomationSchema;
use Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager;
use Coleza\Domain\Automation\Execution\RunStatus;
trait ApprovalDelayCases
{
    private function installCombined(): void
    {
        AutomationSchema::installSchema($this->db);
        DatabaseRuleVersionManager::installSchema($this->db);
    }
    private function combinedRule(int $delay = 3600): AutomationRule
    {
        return new AutomationRule('combined', 'Approval and delay', new EventTrigger('event'),
            new IfElseBranch(new FieldCondition('ok', '==', true), [new ActionDefinition('a', 'send', ['value' => 1])], [new ActionDefinition('b', 'send', ['value' => 2])]),
            approvalRequirement: new ApprovalRequirement(), delaySeconds: $delay);
    }
    public function testApprovalDoesNotBypassDelay(): void
    {
        $this->installCombined();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function () use (&$calls) { $calls++; return ['sent' => true]; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        $pending = $engine->executeRule($this->combinedRule(), new TriggerContext('event', ['ok' => true]));
        $result = $engine->executeApprovedRequest($pending->getApprovalId(), 'actor');
        self::assertSame(RunStatus::DELAYED, $result->getStatus());
        self::assertSame(0, $calls);
        self::assertNotNull($result->getDelayId());
        self::assertSame([], $engine->dispatchDueDelayedExecutions());
    }
    private function makePending(bool $registered = false): array
    {
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $rule = $this->combinedRule();
        if ($registered) { $engine->getVersionManager()->recordVersion($rule, 'author', 'First'); }
        $result = $engine->executeRule($rule, new TriggerContext('event', ['ok' => false]));
        return [$engine, $result->getApprovalId()];
    }
    private function childCount(): int
    {
        return (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM automation_pending WHERE kind = 'delay'")['n'];
    }
    private function makeDue(string $id): void
    {
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$id]);
        $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($row['payload']);
        $data['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
        $this->db->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $id]);
    }
    public function testRestartAndRepeatedApprovalPreserveOneChildAndOriginalDecision(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $first = $engine->executeApprovedRequest($id, 'actor');
        $decision = $engine->getApprovalManager()->findById($id)->toArray();
        $again = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $second = $again->executeApprovedRequest($id, 'actor');
        self::assertSame($first->getDelayId(), $second->getDelayId());
        self::assertSame($decision, $again->getApprovalManager()->findById($id)->toArray());
        self::assertSame(1, $this->childCount());
        self::assertSame(2, $again->getHistoryRepository()->count());
        $child = $again->getDelayManager()->findById($first->getDelayId());
        self::assertSame($id, $child->getExecutionSnapshot()['approval_id']);
        self::assertSame($first->getDelayId(), $again->getApprovalManager()->findById($id)->getDelayedExecutionId());
        self::assertSame([], (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved());
    }
    public function testOriginalVersionElseBranchAndDelaySurviveNewPublicationAndDispatch(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending(true);
        $engine->getVersionManager()->recordVersion($this->combinedRule(7200), 'other', 'Longer delay');
        $result = $engine->executeApprovedRequest($id, 'actor');
        $child = $engine->getDelayManager()->findById($result->getDelayId());
        self::assertSame(3600, $child->getExecutionSnapshot()['delay_seconds']);
        self::assertSame(1, $child->getExecutionSnapshot()['rule_version']);
        self::assertSame('ELSE', $result->getBranchTaken());
        $seconds = $child->getExecuteAt()->getTimestamp() - $engine->getApprovalManager()->findById($id)->getDecidedAt()->getTimestamp();
        self::assertGreaterThanOrEqual(3600, $seconds);
        self::assertSame(3600, $child->getExecuteAt()->getTimestamp() - $child->getScheduledAt()->getTimestamp());
        $this->makeDue($child->getId());
        $calls = [];
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function ($a, $c) use (&$calls) { $calls[] = $a->resolveParameters($c); return ['sent' => true]; }));
        $again = DatabaseAutomationFactory::create($this->db, $registry);
        $dispatched = $again->dispatchDueDelayedExecutions();
        self::assertSame([['value' => 2]], $calls);
        self::assertSame('ELSE', $dispatched[$child->getId()]->getBranchTaken());
        self::assertSame([], $again->dispatchDueDelayedExecutions());
        $runs = $again->getHistoryRepository()->listRecent();
        $final = array_values(array_filter($runs, static fn($r) => $r->getStatus() === RunStatus::SUCCESS));
        self::assertCount(1, $final);
        self::assertSame(1, $final[0]->getRuleVersion());
        self::assertSame($id, $final[0]->getMetadata()['approval_id']);
        self::assertSame([], (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved());
        self::assertSame($child->getId(), $again->executeApprovedRequest($id, 'actor')->getDelayId());
        self::assertSame(1, $this->childCount());
    }
    public function testOldVersionedPayloadResolvesDelayFromFrozenVersion(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending(true);
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$id]);
        $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($row['payload']);
        unset($data['execution_snapshot']['delay_seconds']); // D02-3k payload format.
        $this->db->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $id]);
        $engine->getVersionManager()->recordVersion($this->combinedRule(0), 'other', 'Removed delay in new version');
        $result = $engine->executeApprovedRequest($id, 'actor');
        self::assertSame(RunStatus::DELAYED, $result->getStatus());
        self::assertSame(3600, $engine->getDelayManager()->findById($result->getDelayId())->getExecutionSnapshot()['delay_seconds']);
    }
    public function testUnknownHistoricalPolicyIsNotAssumedToHaveZeroDelay(): void
    {
        $this->installCombined();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $pending = $engine->getApprovalManager()->createRequest('legacy', 'Unknown', new TriggerContext('event'), [], new ApprovalRequirement());
        try { $engine->executeApprovedRequest($pending->getId(), 'actor'); self::fail('Unknown policy executed'); }
        catch (\LogicException) {
            self::assertSame(\Coleza\Domain\Automation\Approval\ApprovalStatus::PENDING, $engine->getApprovalManager()->findById($pending->getId())->getStatus());
            self::assertSame(0, $this->childCount());
        }
    }
    public function testOldVersionFingerprintMismatchIsBlockedBeforeDecision(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending(true);
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$id]);
        $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($row['payload']);
        unset($data['execution_snapshot']['delay_seconds']);
        $data['execution_snapshot']['rule_fingerprint'] = str_repeat('a', 64);
        $this->db->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $id]);
        $this->expectException(\LogicException::class);
        $engine->executeApprovedRequest($id, 'actor');
    }
    public function testAnotherActorCannotReplaceOrRepeatTheRecordedDecision(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $engine->executeApprovedRequest($id, 'first');
        try { $engine->executeApprovedRequest($id, 'other'); self::fail('Actor changed'); }
        catch (\InvalidArgumentException) { self::assertSame('first', $engine->getApprovalManager()->findById($id)->getDecidedBy()); }
        self::assertSame(1, $this->childCount());
    }
    private function failHandoff(string $stage): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $table = $stage === 'history' ? 'automation_run_history' : 'automation_pending';
        $operation = $stage === 'history' ? 'INSERT' : 'UPDATE';
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER handoff_fail BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'"
            : "CREATE TRIGGER handoff_fail BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        try { $engine->executeApprovedRequest($id, 'actor'); self::fail('Write failure ignored'); }
        catch (\PDOException) {
            self::assertSame(0, $this->childCount());
            self::assertSame(\Coleza\Domain\Automation\Approval\ApprovalStatus::PENDING, $engine->getApprovalManager()->findById($id)->getStatus());
            self::assertNull($engine->getApprovalManager()->findById($id)->getDelayedExecutionId());
            self::assertSame(1, $engine->getHistoryRepository()->count());
        }
        $this->db->statement('DROP TRIGGER handoff_fail');
        self::assertSame(RunStatus::DELAYED, $engine->executeApprovedRequest($id, 'actor')->getStatus());
        self::assertSame(1, $this->childCount());
    }
    public function testHistoryInsertFailureRollsBackDecisionAndChild(): void { $this->failHandoff('history'); }
    public function testFinalApprovalUpdateFailureRollsBackChildAndHistory(): void { $this->failHandoff('approval'); }
    public function testOuterRollbackRestoresAllHandoffState(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $this->db->beginTransaction();
        $engine->executeApprovedRequest($id, 'actor');
        $this->db->rollBack();
        self::assertSame(0, $this->childCount());
        self::assertSame(\Coleza\Domain\Automation\Approval\ApprovalStatus::PENDING, $engine->getApprovalManager()->findById($id)->getStatus());
        self::assertSame(1, $engine->getHistoryRepository()->count());
    }
    public function testCoordinatorChecksPauseEvenWhenCalledDirectly(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $engine->getEmergencyPauseManager()->pause('operator', 'stop');
        try { $engine->getApprovalDelayCoordinator()->scheduleApproved($id, 'actor'); self::fail('Pause bypassed'); }
        catch (\LogicException) { self::assertSame(0, $this->childCount()); }
        self::assertSame(\Coleza\Domain\Automation\Approval\ApprovalStatus::PENDING, $engine->getApprovalManager()->findById($id)->getStatus());
    }
    public function testInFlightLinkedDelayIsInspectedOnceAndIsNotRetried(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $result = $engine->executeApprovedRequest($id, 'actor');
        $this->makeDue($result->getDelayId());
        self::assertTrue($engine->getDelayManager()->claimForDispatch($result->getDelayId()));
        $items = (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertCount(1, $items);
        self::assertSame($result->getDelayId(), $items[0]['id']);
        self::assertFalse($items[0]['automatic_retry_allowed']);
        self::assertSame([], $engine->dispatchDueDelayedExecutions());
    }
    public function testMissingLinkedDelayIsVisibleAndIsNeverRecreated(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $result = $engine->executeApprovedRequest($id, 'actor');
        $this->db->delete('automation_pending', 'id = :id', ['id' => $result->getDelayId()]);
        $items = (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertSame('LINKED_DELAY_MISSING_OR_MISMATCHED', $items[0]['reason']);
        $this->expectException(\LogicException::class);
        $engine->executeApprovedRequest($id, 'actor');
    }
    public function testCancellationDoesNotCreateAnotherChildOnReplay(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $result = $engine->executeApprovedRequest($id, 'actor');
        $engine->getDelayManager()->cancel($result->getDelayId());
        self::assertSame($result->getDelayId(), $engine->executeApprovedRequest($id, 'actor')->getDelayId());
        self::assertSame(1, $this->childCount());
        self::assertSame([], (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved());
    }
    public function testMissingCoordinatorCannotSkipTheDelay(): void
    {
        $this->installCombined();
        [$configured, $id] = $this->makePending();
        $engine = new \Coleza\Domain\Automation\Engine\AutomationEngine(approvalManager: $configured->getApprovalManager(), delayManager: $configured->getDelayManager());
        $this->expectException(\LogicException::class);
        $engine->executeApprovedRequest($id, 'actor');
    }
    public function testExplicitZeroDelayStillRunsOnceImmediatelyAfterApproval(): void
    {
        $this->installCombined();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function () use (&$calls) { $calls++; return ['sent' => true]; }));
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        $request = $engine->executeRule($this->combinedRule(0), new TriggerContext('event', ['ok' => true]));
        self::assertSame(RunStatus::SUCCESS, $engine->executeApprovedRequest($request->getApprovalId(), 'actor')->getStatus());
        self::assertSame(1, $calls);
        self::assertSame(0, $this->childCount());
        try { $engine->executeApprovedRequest($request->getApprovalId(), 'actor'); self::fail('Immediate action repeated'); }
        catch (\InvalidArgumentException) { self::assertSame(1, $calls); }
    }
    public function testDryRunAndObserveDoNotCreateApprovalOrDelay(): void
    {
        $this->installCombined();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        foreach ([\Coleza\Domain\Automation\Execution\ExecutionMode::DRY_RUN, \Coleza\Domain\Automation\Execution\ExecutionMode::OBSERVE] as $mode) {
            $result = $engine->executeRule($this->combinedRule(), new TriggerContext('event', ['ok' => true]), $mode);
            self::assertSame($mode, $result->getExecutionMode());
        }
        self::assertSame([], $engine->getApprovalManager()->listPending());
        self::assertSame(0, $this->childCount());
    }
    public function testExplicitManualDelayPolicyWorksWithUnknownOtherProvenance(): void
    {
        $this->installCombined();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $pending = $engine->getApprovalManager()->createRequest('manual', 'Explicit policy', new TriggerContext('event'), [], new ApprovalRequirement(), ['delay_seconds' => 60]);
        $result = $engine->executeApprovedRequest($pending->getId(), 'actor');
        self::assertSame(RunStatus::DELAYED, $result->getStatus());
        self::assertSame(60, $engine->getDelayManager()->findById($result->getDelayId())->getExecutionSnapshot()['delay_seconds']);
        $record = $engine->getHistoryRepository()->listRecent()[0];
        self::assertTrue($record->getMetadata()['condition_unknown']);
        self::assertSame('UNKNOWN', $record->getBranchTaken());
    }
    public function testExpiredRequestCannotProduceDelayedWork(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$id]);
        $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($row['payload']);
        $data['expires_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
        $this->db->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $id]);
        try { $engine->executeApprovedRequest($id, 'actor'); self::fail('Expired approval scheduled'); }
        catch (\InvalidArgumentException) { self::assertSame(0, $this->childCount()); }
        self::assertSame(1, $engine->getApprovalManager()->processExpirations());
        self::assertSame(\Coleza\Domain\Automation\Approval\ApprovalStatus::EXPIRED, $engine->getApprovalManager()->findById($id)->getStatus());
    }
    public function testDestructivePauseIsCheckedWithinAtomicHandoff(): void
    {
        $this->installCombined();
        $engine = DatabaseAutomationFactory::create($this->db, new ActionRegistry());
        $rule = new AutomationRule('suspend', 'Restricted', new EventTrigger('event'),
            new IfElseBranch(new FieldCondition('ok', '==', true), [new ActionDefinition('a', 'service.suspend')]),
            approvalRequirement: new ApprovalRequirement(), delaySeconds: 60);
        $result = $engine->executeRule($rule, new TriggerContext('event', ['ok' => true]));
        $engine->getEmergencyPauseManager()->pause('operator', 'destructive stop', 'DESTRUCTIVE');
        try { $engine->getApprovalDelayCoordinator()->scheduleApproved($result->getApprovalId(), 'actor'); self::fail('Destructive pause bypassed'); }
        catch (\LogicException) { self::assertSame(0, $this->childCount()); }
        self::assertSame(\Coleza\Domain\Automation\Approval\ApprovalStatus::PENDING, $engine->getApprovalManager()->findById($result->getApprovalId())->getStatus());
    }
    public function testDryRunHistoryCannotCompleteAnUnfinishedApproval(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $entry = $engine->getApprovalManager()->approve($id, 'actor');
        $engine->getHistoryRepository()->save(new \Coleza\Domain\Automation\Execution\AutomationRunRecord('simulated', $entry->getRuleId(), 'Rule', 0,
            $entry->getContext()->getEventName(), $entry->getContext()->getCorrelationId(), \Coleza\Domain\Automation\Execution\ExecutionMode::DRY_RUN,
            RunStatus::SUCCESS, true, 'THEN', metadata: ['approval_id' => $id]));
        $items = (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertCount(1, $items);
        self::assertSame('MISSING_OUTCOME', $items[0]['reason']);
    }
    public function testExplicitPolicyMustMatchFrozenVersionEvenWhenFingerprintIsValid(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending(true);
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$id]);
        $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($row['payload']);
        $data['execution_snapshot']['delay_seconds'] = 0;
        $this->db->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $id]);
        $this->expectException(\LogicException::class);
        $engine->executeApprovedRequest($id, 'actor');
    }
}
