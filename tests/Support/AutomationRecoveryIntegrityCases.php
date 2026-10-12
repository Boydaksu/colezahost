<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Automation\Engine\{AutomationRule, DatabaseAutomationFactory};
use Coleza\Domain\Automation\Execution\AutomationRecoveryInspector;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionRegistry};
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Approval\ApprovalRequirement;
use Coleza\Domain\Automation\Triggers\{TriggerContext, EventTrigger};
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\FieldCondition;

trait AutomationRecoveryIntegrityCases
{
    private function recoveryEngine(): \Coleza\Domain\Automation\Engine\AutomationEngine
    {
        \Coleza\Domain\Automation\Persistence\AutomationSchema::installSchema($this->db);
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($this->db);
        \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter::installSchema($this->db);
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function ($action, $context) {
            $this->calls++;
            return $action->resolveParameters($context);
        }));
        return DatabaseAutomationFactory::create($this->db, $registry);
    }
    private int $calls = 0;
    private function completedApproval(): array
    {
        $engine = $this->recoveryEngine();
        $pending = $engine->getApprovalManager()->createRequest('r', 'Rule', new TriggerContext('event', ['value' => 42]),
            [new ActionDefinition('a', 'send', ['value' => '{{ value }}'])], new ApprovalRequirement(),
            ['rule_version' => 0, 'rule_fingerprint' => null, 'condition_passed' => true, 'branch_taken' => 'THEN', 'delay_seconds' => 0]);
        $engine->executeApprovedRequest($pending->getId(), 'actor');
        return [$engine, $pending->getId(), $engine->getHistoryRepository()->listRecent()[0]->getRunId()];
    }
    private function linkedDelay(): array
    {
        $engine = $this->recoveryEngine();
        $rule = new AutomationRule('r', 'Rule', new EventTrigger('event'),
            new IfElseBranch(new FieldCondition('ok', '==', true), [new ActionDefinition('a', 'send', ['value' => 42])]),
            approvalRequirement: new ApprovalRequirement(), delaySeconds: 600);
        $engine->getVersionManager()->recordVersion($rule, 'author', 'First');
        $approval = $engine->executeRule($rule, new TriggerContext('event', ['ok' => true]));
        $delay = $engine->executeApprovedRequest($approval->getApprovalId(), 'actor');
        return [$engine, $approval->getApprovalId(), $delay->getDelayId()];
    }
    private function changePayload(string $table, string $id, callable $change): void
    {
        $data = AutomationCodec::decode($this->db->selectOne("SELECT payload FROM {$table} WHERE id = ?", [$id])['payload']);
        $change($data);
        $this->db->update($table, ['payload' => AutomationCodec::encode($data)], 'id = :id', ['id' => $id]);
    }
    public function testWrongVersionSuccessCannotHideUnresolvedApproval(): void
    {
        [, , $runId] = $this->completedApproval();
        $this->changePayload('automation_run_history', $runId, static function (&$d) { $d['rule_version'] = 99; });
        self::assertCount(1, (new AutomationRecoveryInspector($this->db))->listUnresolved());
    }
    public function testChangedLinkedActionsCannotHideApproval(): void
    {
        [, , $delayId] = $this->linkedDelay();
        $this->changePayload('automation_pending', $delayId, static function (&$d) { $d['actions'][0]['parameters']['value'] = 999; });
        $items = (new AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertNotEmpty($items);
        self::assertSame('LINKED_DELAY_MISSING_OR_MISMATCHED', $items[0]['reason']);
    }
    public function testChangedLinkedActionsCannotBeDispatched(): void
    {
        [$engine, , $delayId] = $this->linkedDelay();
        $this->changePayload('automation_pending', $delayId, static function (&$d) {
            $d['actions'][0]['parameters']['value'] = 999;
            $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
            $d['scheduled_at'] = (new \DateTimeImmutable($d['execute_at']))->modify('-600 seconds')->format('Y-m-d\TH:i:s.uP');
        });
        try { $engine->dispatchDueDelayedExecutions(); }
        catch (\LogicException) { /* The original pending state must remain available for reconciliation. */ }
        self::assertSame(0, $this->calls, 'Changed child action must never reach a handler');
        self::assertSame('SCHEDULED', $engine->getDelayManager()->findById($delayId)->getStatus()->value);
    }
    public function testCompleteEvidenceClosesLocalInspectionWithoutReexecuting(): void
    {
        [$engine, $id] = $this->completedApproval();
        self::assertSame([], (new AutomationRecoveryInspector($this->db))->listUnresolved());
        self::assertSame(1, $this->calls);
        try { $engine->executeApprovedRequest($id, 'actor'); self::fail('Completed approval reexecuted'); }
        catch (\InvalidArgumentException) { self::assertSame(1, $this->calls); }
    }
    public function testIncompleteContradictoryAndStaleSuccessEvidenceRemainsUnresolved(): void
    {
        [, , $runId] = $this->completedApproval();
        $original = AutomationCodec::decode($this->db->selectOne('SELECT payload FROM automation_run_history WHERE id = ?', [$runId])['payload']);
        $changes = [
            static function (&$d) { unset($d['metadata']['pending_execution_fingerprint']); },
            static function (&$d) { $d['metadata']['pending_execution_fingerprint'] = str_repeat('a', 64); },
            static function (&$d) { $d['metadata']['rule_fingerprint'] = str_repeat('a', 64); },
            static function (&$d) { $d['branch_taken'] = 'ELSE'; },
            static function (&$d) { $d['condition_passed'] = false; },
            static function (&$d) { $d['event_name'] = 'other'; },
            static function (&$d) { $d['context_summary']['value'] = 999; },
            static function (&$d) { $d['action_results'] = []; },
            static function (&$d) { $d['action_results'][0]['action_id'] = 'other'; },
            static function (&$d) { $d['action_results'][0]['action_type'] = 'service.terminate'; },
            static function (&$d) { $d['action_results'][0]['status'] = 'FAILED'; },
            static function (&$d) { $d['action_results'][] = $d['action_results'][0]; },
            static function (&$d) { $d['action_results'][0]['error_message'] = 'response unknown'; },
            static function (&$d) { $d['error_message'] = 'uncertain'; },
            static function (&$d) { $d['metadata']['delay_id'] = 'other'; },
            static function (&$d) { $d['executed_at'] = '2000-01-01T00:00:00+00:00'; },
        ];
        foreach ($changes as $change) {
            $data = $original; $change($data);
            $this->db->update('automation_run_history', ['payload' => AutomationCodec::encode($data)], 'id = :id', ['id' => $runId]);
            $before = $this->db->select('SELECT * FROM automation_pending');
            $items = (new AutomationRecoveryInspector($this->db))->listUnresolved();
            self::assertCount(1, $items);
            self::assertFalse($items[0]['automatic_retry_allowed']);
            self::assertSame($before, $this->db->select('SELECT * FROM automation_pending'));
        }
        self::assertSame(1, $this->calls);
    }
    public function testPendingActionChangeCannotReusePreviousSuccessReceipt(): void
    {
        [, $id] = $this->completedApproval();
        $this->changePayload('automation_pending', $id, static function (&$d) { $d['actions'][0]['parameters']['value'] = 999; });
        self::assertCount(1, (new AutomationRecoveryInspector($this->db))->listUnresolved());
    }
    public function testDuplicateSuccessReceiptsAreAmbiguous(): void
    {
        [$engine, , $runId] = $this->completedApproval();
        $data = AutomationCodec::decode($this->db->selectOne('SELECT payload FROM automation_run_history WHERE id = ?', [$runId])['payload']);
        $data['run_id'] = 'duplicate';
        $original = $this->db->selectOne('SELECT * FROM automation_run_history WHERE id = ?', [$runId]);
        $original['id'] = 'duplicate'; $original['payload'] = AutomationCodec::encode($data);
        $this->db->insert('automation_run_history', $original);
        self::assertCount(1, (new AutomationRecoveryInspector($this->db))->listUnresolved());
        self::assertSame(2, $engine->getHistoryRepository()->count());
    }
    private function rejectLinkedChange(callable $change): void
    {
        [$engine, $approvalId, $delayId] = $this->linkedDelay();
        $this->changePayload('automation_pending', $delayId, static function (&$d) use ($change) {
            $change($d);
            $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
            $d['scheduled_at'] = (new \DateTimeImmutable($d['execute_at']))->modify('-600 seconds')->format('Y-m-d\TH:i:s.uP');
        });
        $before = $this->db->select('SELECT * FROM automation_pending');
        $items = (new AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertNotEmpty($items);
        foreach ([static fn() => $engine->getDelayManager()->claimForDispatch($delayId),
            static fn() => $engine->executeApprovedRequest($approvalId, 'actor')] as $operation) {
            try { $operation(); self::fail('Inconsistent approval child accepted'); }
            catch (\LogicException) { self::assertSame($before, $this->db->select('SELECT * FROM automation_pending')); }
        }
        self::assertSame(0, $this->calls);
    }
    public function testLinkedContextChangeBlocksClaimAndReplay(): void
    {
        $this->rejectLinkedChange(static function (&$d) { $d['context']['payload']['ok'] = false; });
    }
    public function testLinkedVersionChangeBlocksClaimAndReplay(): void
    {
        $this->rejectLinkedChange(static function (&$d) { $d['execution_snapshot']['rule_version'] = 2; });
    }
    public function testLinkedDelayPolicyChangeBlocksClaimAndReplay(): void
    {
        $this->rejectLinkedChange(static function (&$d) { $d['execution_snapshot']['delay_seconds'] = 0; });
    }
    public function testRemovingOriginReferenceDoesNotBypassReverseParentLink(): void
    {
        $this->rejectLinkedChange(static function (&$d) { unset($d['execution_snapshot']['approval_id']); });
    }
    public function testOrphanChildIsVisibleAndCannotClaim(): void
    {
        [$engine, $parent, $child] = $this->linkedDelay();
        $this->db->delete('automation_pending', 'id = :id', ['id' => $parent]);
        $this->changePayload('automation_pending', $child, static function (&$d) { $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP'); });
        $items = (new AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertCount(1, $items);
        self::assertSame('LINKED_APPROVAL_MISSING_OR_MISMATCHED', $items[0]['reason']);
        $this->expectException(\LogicException::class);
        $engine->getDelayManager()->claimForDispatch($child);
    }
    public function testPendingPayloadIdentityMismatchStopsReading(): void
    {
        [$engine, $id] = $this->completedApproval();
        $this->changePayload('automation_pending', $id, static function (&$d) { $d['id'] = 'another'; });
        $this->expectException(\UnexpectedValueException::class);
        $engine->getApprovalManager()->findById($id);
    }
    public function testPendingPayloadStatusMismatchStopsMutation(): void
    {
        [$engine, $id] = $this->completedApproval();
        $this->db->update('automation_pending', ['status' => 'PENDING'], 'id = :id', ['id' => $id]);
        $this->expectException(\UnexpectedValueException::class);
        $engine->getApprovalManager()->reject($id, 'other', 'Must not replace existing decision');
    }
    public function testHistoryRowAndPayloadIdentityMismatchStopsInspection(): void
    {
        [, , $runId] = $this->completedApproval();
        $this->changePayload('automation_run_history', $runId, static function (&$d) { $d['run_id'] = 'another'; });
        $this->expectException(\UnexpectedValueException::class);
        (new AutomationRecoveryInspector($this->db))->listUnresolved();
    }
    public function testValidLinkedDelayCompletesAndFingerprintSurvivesRestart(): void
    {
        [$engine, , $delayId] = $this->linkedDelay();
        $this->changePayload('automation_pending', $delayId, static function (&$d) {
            $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
            $d['scheduled_at'] = (new \DateTimeImmutable($d['execute_at']))->modify('-600 seconds')->format('Y-m-d\TH:i:s.uP');
        });
        self::assertTrue($engine->dispatchDueDelayedExecutions()[$delayId]->isSuccessful());
        self::assertSame([], (new AutomationRecoveryInspector($this->db))->listUnresolved());
        self::assertSame([], DatabaseAutomationFactory::create($this->db, new ActionRegistry())->dispatchDueDelayedExecutions());
        self::assertSame(1, $this->calls);
        self::assertArrayHasKey('pending_execution_fingerprint', $engine->getHistoryRepository()->listRecent()[0]->getMetadata());
    }
    public function testInFlightChildIsNeverClosedByAClaimedSuccessReceipt(): void
    {
        [$engine, , $delayId] = $this->linkedDelay();
        $this->changePayload('automation_pending', $delayId, static function (&$d) {
            $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
            $d['scheduled_at'] = (new \DateTimeImmutable($d['execute_at']))->modify('-600 seconds')->format('Y-m-d\TH:i:s.uP');
        });
        $engine->dispatchDueDelayedExecutions();
        $this->changePayload('automation_pending', $delayId, static function (&$d) { $d['status'] = 'DISPATCHING'; $d['dispatched_at'] = null; });
        $this->db->update('automation_pending', ['status' => 'DISPATCHING'], 'id = :id', ['id' => $delayId]);
        self::assertCount(1, (new AutomationRecoveryInspector($this->db))->listUnresolved());
        self::assertSame([], $engine->dispatchDueDelayedExecutions());
        self::assertSame(1, $this->calls);
    }
    public function testStandaloneLegacyDelayStillExecutesWithUnknownProvenance(): void
    {
        $engine = $this->recoveryEngine();
        $entry = new \Coleza\Domain\Automation\Delay\DelayedExecution('legacy', 'r', 'Legacy', new TriggerContext('event'),
            [new ActionDefinition('a', 'send')], new \DateTimeImmutable('-1 hour'));
        $this->db->insert('automation_pending', ['id' => $entry->getId(), 'kind' => 'delay', 'status' => 'SCHEDULED', 'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))]);
        self::assertTrue($engine->dispatchDueDelayedExecutions()['legacy']->isSuccessful());
        self::assertSame([], (new AutomationRecoveryInspector($this->db))->listUnresolved());
        self::assertTrue($engine->getHistoryRepository()->listRecent()[0]->getMetadata()['rule_version_unknown']);
    }
    public function testChangingOnlyExecuteTimeCannotSkipApprovedDelay(): void
    {
        [$engine, , $child] = $this->linkedDelay();
        $this->changePayload('automation_pending', $child, static function (&$d) {
            $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
        });
        try { $engine->getDelayManager()->claimForDispatch($child); self::fail('Inconsistent delay interval claimed'); }
        catch (\LogicException) { self::assertSame('SCHEDULED', $engine->getDelayManager()->findById($child)->getStatus()->value); }
    }
}
