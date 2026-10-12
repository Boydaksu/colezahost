<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionRegistry, ActionResult};
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Engine\{AutomationRule, DatabaseAutomationFactory};
use Coleza\Domain\Automation\Triggers\{EventTrigger, TriggerContext};
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\FieldCondition;

trait AutomationActionJournalCases
{
    private int $calls = 0;
    private function journalEngine(?\Closure $handler = null): \Coleza\Domain\Automation\Engine\AutomationEngine
    {
        \Coleza\Domain\Automation\Persistence\AutomationSchema::installSchema($this->db);
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($this->db);
        \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter::installSchema($this->db);
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function ($action, $context) use ($handler) {
            $this->calls++;
            return $handler === null ? [] : $handler($action, $context);
        }));
        return DatabaseAutomationFactory::create($this->db, $registry);
    }
    private function journalRule(int $count = 1): AutomationRule
    {
        $actions = [];
        for ($i = 0; $i < $count; $i++) { $actions[] = new ActionDefinition('a' . $i, 'send', ['token' => '{{ token }}']); }
        return new AutomationRule('r', 'Rule', new EventTrigger('event'),
            new IfElseBranch(new FieldCondition('ok', '==', true), $actions));
    }
    private function journalContext(): TriggerContext { return new TriggerContext('event', ['ok' => true, 'token' => 'SECRET_NOT_FOR_JOURNAL']); }
    public function testStartedActionIsDurablyVisibleBeforeHandler(): void
    {
        $observed = [];
        $engine = $this->journalEngine(function () use (&$observed) {
            $observed = $this->db->select('SELECT * FROM automation_action_attempts ORDER BY action_position');
            return [];
        });
        $result = $engine->executeRule($this->journalRule(2), $this->journalContext());
        self::assertTrue($result->isSuccessful());
        self::assertCount(2, $observed);
        self::assertSame('DISPATCHING', $observed[1]['status']);
        self::assertSame(2, $this->calls);
    }
    public function testCallerRollbackCannotEraseEffectEvidence(): void
    {
        $engine = $this->journalEngine();
        $this->db->beginTransaction();
        try { $engine->executeRule($this->journalRule(), $this->journalContext()); }
        catch (\LogicException) { /* Refused before any handler. */ }
        finally { $this->db->rollBack(); }
        self::assertSame(0, $this->calls, 'External effect must not outlive rolled-back evidence');
    }
    public function testWrongActionReceiptCannotBeSuccessful(): void
    {
        $engine = $this->journalEngine(static fn() => ActionResult::success('other', 'service.terminate'));
        $result = $engine->executeRule($this->journalRule(), $this->journalContext());
        self::assertFalse($result->isSuccessful(), 'A different action receipt cannot prove completion');
    }

    private function journalRows(): array { return $this->db->select('SELECT * FROM automation_action_attempts ORDER BY dispatch_id, action_position'); }
    private function rejectJournalWrite(string $operation, string $condition): void
    {
        if ($this->db->getDriverName() === 'mysql') {
            $this->db->statement("CREATE TRIGGER journal_fault BEFORE {$operation} ON automation_action_attempts FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal fault'; END IF; END");
        } else {
            $this->db->statement("CREATE TRIGGER journal_fault BEFORE {$operation} ON automation_action_attempts WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'journal fault'); END");
        }
    }
    public function testWholePlanInsertFailurePreventsAllEffectsAndPartialRows(): void
    {
        $engine = $this->journalEngine();
        $this->rejectJournalWrite('INSERT', 'NEW.action_position = 1');
        try { $engine->executeRule($this->journalRule(2), $this->journalContext()); self::fail('Journal fault hidden'); }
        catch (\PDOException $e) { self::assertStringContainsString('journal fault', $e->getMessage()); }
        self::assertSame(0, $this->calls);
        self::assertSame([], $this->journalRows());
    }
    public function testStartWriteFailurePreventsHandlerAndKeepsPlan(): void
    {
        $engine = $this->journalEngine();
        $this->rejectJournalWrite('UPDATE', "NEW.status = 'DISPATCHING'");
        try { $engine->executeRule($this->journalRule(), $this->journalContext()); self::fail('Start fault hidden'); }
        catch (\PDOException $e) { self::assertStringContainsString('journal fault', $e->getMessage()); }
        self::assertSame(0, $this->calls);
        self::assertSame('PREPARED', $this->journalRows()[0]['status']);
    }
    public function testOutcomeWriteFailureStopsNextActionAndRemainsUnknownAcrossRestart(): void
    {
        $engine = $this->journalEngine();
        $this->rejectJournalWrite('UPDATE', "NEW.status = 'REPORTED_SUCCESS'");
        try { $engine->executeRule($this->journalRule(2), $this->journalContext()); self::fail('Outcome fault hidden'); }
        catch (\PDOException $e) { self::assertStringContainsString('journal fault', $e->getMessage()); }
        self::assertSame(1, $this->calls);
        self::assertSame(['DISPATCHING', 'PREPARED'], array_column($this->journalRows(), 'status'));
        $fresh = $this->journalEngine();
        $before = $this->journalRows();
        $unresolved = $fresh->getActionJournal()->listUnresolved();
        self::assertCount(2, $unresolved);
        foreach ($unresolved as $item) { self::assertFalse($item['automatic_retry_allowed']); self::assertFalse($item['provider_outcome_proven']); }
        self::assertSame($before, $this->journalRows());
        self::assertSame(1, $this->calls);
    }
    public function testReportedFailureMayFollowEffectAndIsNotProofOfAbsence(): void
    {
        $engine = $this->journalEngine(static fn($a) => ActionResult::failed($a->getId(), $a->getType(), 'SECRET_NOT_FOR_JOURNAL'));
        $engine->executeRule($this->journalRule(), $this->journalContext());
        $rows = $this->journalRows();
        self::assertSame('OUTCOME_UNKNOWN', $rows[0]['status']);
        self::assertSame('HANDLER_REPORTED_FAILURE', $rows[0]['reason_code']);
        self::assertStringNotContainsString('SECRET_NOT_FOR_JOURNAL', json_encode($rows));
        self::assertCount(1, $engine->getActionJournal()->listUnresolved());
    }
    public function testMatchingSuccessIsOnlyHandlerObservationAndKeepsNoRawSecrets(): void
    {
        $engine = $this->journalEngine(static fn() => ['token' => 'SECRET_NOT_FOR_JOURNAL']);
        $engine->getVersionManager()->recordVersion($this->journalRule(), 'author', 'First');
        $engine->executeRule($this->journalRule(), $this->journalContext());
        $row = $this->journalRows()[0];
        self::assertSame('REPORTED_SUCCESS', $row['status']);
        self::assertSame('HANDLER_REPORTED_SUCCESS', $row['reason_code']);
        self::assertSame(1, (int) $row['rule_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['rule_fingerprint']);
        self::assertSame($row['planned_input_fingerprint'], $row['input_fingerprint']);
        self::assertStringNotContainsString('SECRET_NOT_FOR_JOURNAL', json_encode($row));
        self::assertSame([], $engine->getActionJournal()->listUnresolved());
        $history = $engine->getHistoryRepository()->listRecent()[0]->toArray();
        self::assertSame([$row['id']], $history['metadata']['action_attempt_ids']);
    }
    public function testInvalidLaterActionIsRejectedBeforeFirstEffect(): void
    {
        $engine = $this->journalEngine();
        $rule = new AutomationRule('r', 'Rule', new EventTrigger('event'), new IfElseBranch(new FieldCondition('ok', '==', true),
            [new ActionDefinition('a', 'send'), new ActionDefinition('b', 'send', ['bad' => new \stdClass()])]));
        try { $engine->executeRule($rule, $this->journalContext()); self::fail('Invalid persistent input accepted'); }
        catch (\InvalidArgumentException) { self::assertSame(0, $this->calls); }
        self::assertSame([], $this->journalRows());
    }
    public function testRawPdoCallerTransactionIsAlsoRefused(): void
    {
        $engine = $this->journalEngine();
        $this->db->getPdo()->beginTransaction();
        try { $engine->executeRule($this->journalRule(), $this->journalContext()); self::fail('Raw PDO transaction accepted'); }
        catch (\LogicException) { self::assertSame(0, $this->calls); }
        finally { $this->db->getPdo()->rollBack(); }
    }
    public function testDryRunAndObserveCreateNoActionAttempts(): void
    {
        $engine = $this->journalEngine();
        foreach ([\Coleza\Domain\Automation\Execution\ExecutionMode::DRY_RUN, \Coleza\Domain\Automation\Execution\ExecutionMode::OBSERVE] as $mode) {
            $engine->executeRule($this->journalRule(), $this->journalContext(), $mode);
        }
        self::assertSame(0, $this->calls);
        self::assertSame([], $this->journalRows());
    }
    public function testMissingHandlerIsBlockedWithoutInferringProviderFailure(): void
    {
        $engine = $this->journalEngine();
        $rule = new AutomationRule('r', 'Rule', new EventTrigger('event'), new IfElseBranch(new FieldCondition('ok', '==', true), [new ActionDefinition('a', 'missing')]));
        $engine->executeRule($rule, $this->journalContext());
        self::assertSame(0, $this->calls);
        self::assertSame('BLOCKED', $this->journalRows()[0]['status']);
        self::assertSame('HANDLER_MISSING', $this->journalRows()[0]['reason_code']);
    }
    public function testCompletedAttemptCannotRestartOrOverwriteOutcome(): void
    {
        $engine = $this->journalEngine();
        $engine->executeRule($this->journalRule(), $this->journalContext());
        $before = $this->journalRows();
        try { $engine->getActionJournal()->start($before[0]['id'], new ActionDefinition('a0', 'send', ['token' => '{{ token }}']), $this->journalContext()); self::fail('Completed attempt restarted'); }
        catch (\LogicException) { self::assertSame($before, $this->journalRows()); }
        try { $engine->getActionJournal()->finish($before[0]['id'], 'OUTCOME_UNKNOWN', 'HANDLER_EXCEPTION'); self::fail('Completed decision overwritten'); }
        catch (\LogicException) { self::assertSame($before, $this->journalRows()); }
    }
    public function testApprovalAttemptIsBoundToPendingInputsAndReplayDoesNotDispatch(): void
    {
        $engine = $this->journalEngine();
        $pending = $engine->getApprovalManager()->createRequest('r', 'Rule', $this->journalContext(),
            [new ActionDefinition('a0', 'send')], new \Coleza\Domain\Automation\Approval\ApprovalRequirement(), ['delay_seconds' => 0]);
        self::assertSame([], $this->journalRows());
        $engine->executeApprovedRequest($pending->getId(), 'actor');
        $row = $this->journalRows()[0];
        self::assertSame('approval', $row['pending_kind']);
        self::assertSame($pending->getId(), $row['pending_id']);
        self::assertSame(\Coleza\Domain\Automation\Execution\PendingExecutionIdentity::fingerprint($pending), $row['pending_fingerprint']);
        try { $engine->executeApprovedRequest($pending->getId(), 'actor'); self::fail('Approval replay dispatched'); }
        catch (\InvalidArgumentException) { self::assertSame(1, $this->calls); }
        self::assertCount(1, $this->journalRows());
    }
    public function testDelayedOutcomeFailureKeepsClaimAndIndividualAttemptsVisible(): void
    {
        $engine = $this->journalEngine();
        $entry = $engine->getDelayManager()->schedule('r', 'Rule', $this->journalContext(),
            [new ActionDefinition('a0', 'send'), new ActionDefinition('a1', 'send')], 0);
        $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$entry->getId()])['payload']);
        $data['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
        $data['scheduled_at'] = (new \DateTimeImmutable('-1 hour -1 second'))->format('Y-m-d\TH:i:s.uP');
        $this->db->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $entry->getId()]);
        $this->rejectJournalWrite('UPDATE', "NEW.status = 'REPORTED_SUCCESS'");
        try { $engine->dispatchDueDelayedExecutions(); self::fail('Delayed outcome fault hidden'); }
        catch (\PDOException) { self::assertSame(1, $this->calls); }
        self::assertSame('DISPATCHING', $engine->getDelayManager()->findById($entry->getId())->getStatus()->value);
        $rows = $this->journalRows();
        self::assertSame(['DISPATCHING', 'PREPARED'], array_column($rows, 'status'));
        self::assertSame('delay', $rows[0]['pending_kind']);
        self::assertSame($entry->getId(), $rows[0]['pending_id']);
        self::assertSame([], $engine->dispatchDueDelayedExecutions());
        self::assertSame(1, $this->calls);
    }
    public function testMigrationReplayPreservesExistingAttemptsAndPendingWork(): void
    {
        $engine = $this->journalEngine();
        $engine->executeRule($this->journalRule(), $this->journalContext());
        $pending = $engine->getDelayManager()->schedule('r', 'Rule', $this->journalContext(), [new ActionDefinition('a', 'send')], 600);
        $before = $this->journalRows();
        $payload = $this->db->select('SELECT * FROM automation_pending');
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000016_automation_action_attempts.php';
        $migration->up($this->db); $migration->up($this->db);
        self::assertSame($before, $this->journalRows());
        self::assertSame($payload, $this->db->select('SELECT * FROM automation_pending'));
        self::assertSame('SCHEDULED', $engine->getDelayManager()->findById($pending->getId())->getStatus()->value);
    }
    public function testPreparedActionParametersCannotBeSubstituted(): void
    {
        $engine = $this->journalEngine();
        $context = $this->journalContext();
        $ids = $engine->getActionJournal()->prepare('r', [new ActionDefinition('a', 'send', ['value' => 1])], $context);
        try { $engine->getActionJournal()->start($ids[0], new ActionDefinition('a', 'send', ['value' => 999]), $context); self::fail('Different action parameters accepted'); }
        catch (\LogicException) { self::assertSame('PREPARED', $this->journalRows()[0]['status']); }
    }
    public function testUnrecognizedEvidenceCannotDisappearFromInspection(): void
    {
        $engine = $this->journalEngine();
        $engine->executeRule($this->journalRule(), $this->journalContext());
        $this->db->update('automation_action_attempts', ['status' => 'UNKNOWN_NEW_STATE'], 'id = :id', ['id' => $this->journalRows()[0]['id']]);
        try { (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolvedActionAttempts(); self::fail('Unknown state hidden'); }
        catch (\UnexpectedValueException) { self::assertSame(1, $this->calls); }
    }
    public function testSkippedHandlerReceiptIsNotProviderCompletionProof(): void
    {
        $engine = $this->journalEngine(static fn($a) => ActionResult::skipped($a->getId(), $a->getType(), 'nothing to do'));
        $engine->executeRule($this->journalRule(), $this->journalContext());
        $items = (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolvedActionAttempts();
        self::assertCount(1, $items);
        self::assertSame('HANDLER_SKIPPED', $items[0]['reason_code']);
        self::assertFalse($items[0]['automatic_retry_allowed']);
    }
    public function testFailedReceiptCannotBeRecordedAsReportedSuccess(): void
    {
        $engine = $this->journalEngine();
        $action = new ActionDefinition('a', 'send');
        $context = $this->journalContext();
        $ids = $engine->getActionJournal()->prepare('r', [$action], $context);
        $engine->getActionJournal()->start($ids[0], $action, $context);
        try { $engine->getActionJournal()->finish($ids[0], 'REPORTED_SUCCESS', 'HANDLER_REPORTED_FAILURE'); self::fail('Contradictory observation accepted'); }
        catch (\InvalidArgumentException) { self::assertSame('DISPATCHING', $this->journalRows()[0]['status']); }
    }
    public function testMissingJournalCannotSilentlyFallBackToUnrecordedExecution(): void
    {
        $this->journalEngine();
        $this->db->statement('DROP TABLE automation_action_attempts');
        try { DatabaseAutomationFactory::create($this->db, new ActionRegistry()); self::fail('Missing journal accepted'); }
        catch (\PDOException) { self::assertSame(0, $this->calls); }
        if ($this->db->getDriverName() === 'mysql') {
            self::assertNull($this->db->selectOne("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'automation_action_attempts'"));
        } else { self::assertNull($this->db->selectOne("SELECT 1 FROM sqlite_master WHERE name = 'automation_action_attempts'")); }
    }
    public function testReceiptWithSuccessAndErrorCannotProveCompletion(): void
    {
        $engine = $this->journalEngine(static fn($a) => new ActionResult($a->getId(), $a->getType(),
            \Coleza\Domain\Automation\Actions\ActionStatus::SUCCESS, errorMessage: 'contradictory'));
        $result = $engine->executeRule($this->journalRule(), $this->journalContext());
        self::assertFalse($result->isSuccessful());
        self::assertSame('OUTCOME_UNKNOWN', $this->journalRows()[0]['status']);
        self::assertSame('HANDLER_RECEIPT_MISMATCH', $this->journalRows()[0]['reason_code']);
    }
}
