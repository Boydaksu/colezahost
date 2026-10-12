<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Automation\Persistence\{AutomationSchema, AutomationCodec};
use Coleza\Domain\Automation\Engine\DatabaseAutomationFactory;
use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionRegistry};
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Approval\{DatabaseApprovalManager, ApprovalRequirement, ApprovalStatus, PendingApproval};
use Coleza\Domain\Automation\Delay\{DatabaseDelayManager, DelayedExecution, DelayStatus};
use Coleza\Domain\Automation\Execution\{DatabaseRunHistoryRepository, AutomationRunRecord, ExecutionMode, RunStatus};
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;

trait AutomationPersistenceCases
{
    private function engine(): \Coleza\Domain\Automation\Engine\AutomationEngine
    {
        return DatabaseAutomationFactory::create($this->db, new ActionRegistry());
    }
    private function install(): void
    {
        AutomationSchema::installSchema($this->db);
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($this->db);
    }
    private function dueDelay(): DelayedExecution
    {
        $entry = new DelayedExecution('delay_fixture', 'rule', 'Reminder', new TriggerContext('invoice.due', ['invoice_id' => 42]),
            [new ActionDefinition('a', 'send', ['invoice_id' => '{{ invoice_id }}'])], new DateTimeImmutable('-1 hour'));
        $this->db->insert('automation_pending', ['id' => $entry->getId(), 'kind' => 'delay', 'status' => 'SCHEDULED',
            'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))]);
        return $entry;
    }
    public function testApprovalSurvivesReconstructionWithOriginalActionAndContext(): void
    {
        $this->install();
        $context = new TriggerContext('invoice.due', ['invoice_id' => 42], correlationId: 'fixed-correlation');
        $first = $this->engine()->getApprovalManager()->createRequest('r', 'Ask', $context,
            [new ActionDefinition('a', 'send', ['invoice_id' => '{{ invoice_id }}'])], new ApprovalRequirement('billing', 30, 'Review'));
        $again = $this->engine()->getApprovalManager();
        $found = $again->findById($first->getId());
        self::assertSame($first->toArray(), $found->toArray());
        self::assertSame('fixed-correlation', $found->getContext()->getCorrelationId());
        self::assertSame(['invoice_id' => '42'], $found->getActionsToExecute()[0]->resolveParameters($found->getContext()));
        $decided = $again->approve($first->getId(), 'operator', 'checked');
        self::assertSame($decided->toArray(), $this->engine()->getApprovalManager()->findById($first->getId())->toArray());
        self::assertSame([], $again->listPending());
        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->getApprovalManager()->reject($first->getId(), 'other', 'No');
    }
    public function testExpirationPersistsAndExpiredApprovalCannotExecute(): void
    {
        $this->install();
        $entry = new PendingApproval('expired', 'r', 'Expired', new TriggerContext('event'), [], new ApprovalRequirement(),
            new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'));
        $this->db->insert('automation_pending', ['id' => 'expired', 'kind' => 'approval', 'status' => 'PENDING',
            'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))]);
        try { $this->engine()->getApprovalManager()->approve('expired', 'actor'); self::fail('Expired approval accepted'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('expired', $e->getMessage()); }
        self::assertSame(1, $this->engine()->getApprovalManager()->processExpirations());
        self::assertSame(0, $this->engine()->getApprovalManager()->processExpirations());
        self::assertSame(ApprovalStatus::EXPIRED, $this->engine()->getApprovalManager()->findById('expired')->getStatus());
    }
    public function testDelayScheduleCancelAndClaimSurviveReconstruction(): void
    {
        $this->install();
        $delay = new DatabaseDelayManager($this->db);
        $later = $delay->schedule('r', 'Later', new TriggerContext('event'), [], 3600);
        self::assertSame($later->toArray(), (new DatabaseDelayManager($this->db))->findById($later->getId())->toArray());
        self::assertFalse($delay->claimForDispatch($later->getId()));
        $delay->cancel($later->getId());
        self::assertSame(DelayStatus::CANCELLED, (new DatabaseDelayManager($this->db))->findById($later->getId())->getStatus());
        $entry = $this->dueDelay();
        self::assertCount(1, $delay->getDueExecutions());
        self::assertTrue($delay->claimForDispatch($entry->getId()));
        $again = new DatabaseDelayManager($this->db);
        self::assertFalse($again->claimForDispatch($entry->getId()));
        self::assertSame([], $again->getDueExecutions());
        self::assertSame(DelayStatus::DISPATCHING, $again->findById($entry->getId())->getStatus());
        $again->markDispatched($entry->getId());
        self::assertSame(DelayStatus::DISPATCHED, $delay->findById($entry->getId())->getStatus());
    }
    public function testGlobalPauseRetainsDelayedWorkUntilResumeAndWritesHistory(): void
    {
        $this->install();
        $entry = $this->dueDelay();
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function ($action, $context) use (&$calls) {
            $calls++;
            return $action->resolveParameters($context);
        }));
        $first = $this->engine();
        $first->getEmergencyPauseManager()->pause('operator', 'maintenance');
        $engine = DatabaseAutomationFactory::create($this->db, $registry);
        self::assertTrue($engine->getEmergencyPauseManager()->isPaused());
        self::assertSame([], $engine->dispatchDueDelayedExecutions());
        self::assertSame(DelayStatus::SCHEDULED, $engine->getDelayManager()->findById($entry->getId())->getStatus());
        self::assertSame(0, $calls);
        $engine->getEmergencyPauseManager()->resume('operator2', 'done');
        self::assertFalse($this->engine()->getEmergencyPauseManager()->isPaused());
        self::assertSame('done', $this->engine()->getEmergencyPauseManager()->getState()->getResumedReason());
        self::assertCount(1, $engine->dispatchDueDelayedExecutions());
        self::assertSame([], DatabaseAutomationFactory::create($this->db, $registry)->dispatchDueDelayedExecutions());
        self::assertSame(1, $calls);
        $history = $this->engine()->getHistoryRepository()->listRecent();
        self::assertCount(1, $history);
        self::assertSame($entry->getId(), $history[0]->getMetadata()['delay_id']);
        self::assertSame($entry->getContext()->getCorrelationId(), $history[0]->getCorrelationId());
    }
    public function testApprovedExecutionAfterReconstructionWritesResultAndCannotRepeat(): void
    {
        $this->install();
        $pending = $this->engine()->getApprovalManager()->createRequest('r', 'Ask', new TriggerContext('event'),
            [new ActionDefinition('a', 'send')], new ApprovalRequirement(), ['delay_seconds' => 0]);
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register(new CallbackActionHandler('send', function () use (&$calls) { $calls++; return ['sent' => true]; }));
        $result = DatabaseAutomationFactory::create($this->db, $registry)->executeApprovedRequest($pending->getId(), 'actor');
        self::assertSame(RunStatus::SUCCESS, $result->getStatus());
        self::assertSame(1, $this->engine()->getHistoryRepository()->count());
        $history = $this->engine()->getHistoryRepository()->listRecent()[0];
        self::assertSame('UNKNOWN', $history->getBranchTaken());
        self::assertTrue($history->getMetadata()['condition_unknown']);
        try { DatabaseAutomationFactory::create($this->db, $registry)->executeApprovedRequest($pending->getId(), 'actor'); self::fail('Repeated approval execution'); }
        catch (\InvalidArgumentException) { self::assertSame(1, $calls); }
    }
    public function testHistoryQueriesPreserveValuesAndDoNotOverwriteEvidence(): void
    {
        $this->install();
        $repository = new DatabaseRunHistoryRepository($this->db);
        $record = new AutomationRunRecord('run_1', 'r', 'Rule', 3, 'event', 'correlation', ExecutionMode::DRY_RUN, RunStatus::SUCCESS,
            true, 'THEN', [['sent' => false]], ['invoice' => 42], 1.5, executedAt: new DateTimeImmutable('2025-01-01T01:00:00+03:00'), metadata: ['origin' => 'test']);
        $repository->save($record);
        $again = new DatabaseRunHistoryRepository($this->db);
        self::assertSame($record->toArray(), $again->findById('run_1')->toArray());
        self::assertCount(1, $again->findByRuleId('r'));
        self::assertCount(1, $again->findByCorrelationId('correlation'));
        self::assertSame([], $again->listRecent(0));
        self::assertNull($again->findById('missing'));
        try { $again->save($record); self::fail('History overwritten'); } catch (\PDOException) { self::assertSame(1, $again->count()); }
    }
    public function testInstallerRerunPreservesPauseAndPendingRecords(): void
    {
        $this->install();
        $this->engine()->getEmergencyPauseManager()->pause('actor', 'stop', 'DESTRUCTIVE');
        $this->dueDelay();
        $this->install();
        self::assertSame('stop', $this->engine()->getEmergencyPauseManager()->getState()->getReason());
        self::assertTrue($this->engine()->getEmergencyPauseManager()->isPaused('service.suspend'));
        self::assertFalse($this->engine()->getEmergencyPauseManager()->isPaused('send'));
        self::assertCount(1, $this->engine()->getDelayManager()->getDueExecutions());
    }
    public function testRollbackIncludesPendingAndPauseChanges(): void
    {
        $this->install();
        $this->db->beginTransaction();
        $entry = $this->engine()->getApprovalManager()->createRequest('r', 'Ask', new TriggerContext('event'), [], new ApprovalRequirement());
        $this->engine()->getEmergencyPauseManager()->pause('actor', 'stop');
        $this->db->rollBack();
        self::assertNull($this->engine()->getApprovalManager()->findById($entry->getId()));
        self::assertFalse($this->engine()->getEmergencyPauseManager()->isPaused());
    }
    public function testObjectPayloadRejectedBeforeInsert(): void
    {
        $this->install();
        try {
            $this->engine()->getApprovalManager()->createRequest('r', 'Bad', new TriggerContext('event', ['object' => new \stdClass()]), [], new ApprovalRequirement());
            self::fail('Object persisted');
        } catch (\InvalidArgumentException) { self::assertSame([], $this->engine()->getApprovalManager()->listPending()); }
    }
    public function testMissingSafetyStateFailsClosed(): void
    {
        $this->install();
        $this->db->statement('DELETE FROM automation_emergency_pause');
        $this->expectException(\RuntimeException::class);
        $this->engine();
    }

    public function testDestructivePauseDoesNotConsumeApprovalOrDelayedWork(): void
    {
        $this->install();
        $engine = $this->engine();
        $approval = $engine->getApprovalManager()->createRequest('r', 'Suspend', new TriggerContext('event'),
            [new ActionDefinition('a', 'service.suspend')], new ApprovalRequirement());
        $entry = new DelayedExecution('destructive_delay', 'r', 'Suspend', new TriggerContext('event'),
            [new ActionDefinition('a', 'service.suspend')], new DateTimeImmutable('-1 hour'));
        $this->db->insert('automation_pending', ['id' => $entry->getId(), 'kind' => 'delay', 'status' => 'SCHEDULED',
            'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))]);
        $engine->getEmergencyPauseManager()->pause('actor', 'review', 'DESTRUCTIVE');
        self::assertSame([], $this->engine()->dispatchDueDelayedExecutions());
        self::assertSame(DelayStatus::SCHEDULED, $engine->getDelayManager()->findById($entry->getId())->getStatus());
        try { $this->engine()->executeApprovedRequest($approval->getId(), 'actor'); self::fail('Paused approval consumed'); }
        catch (\LogicException) { self::assertSame(ApprovalStatus::PENDING, $engine->getApprovalManager()->findById($approval->getId())->getStatus()); }
    }

    public function testClaimRollbackAndMissingLockCannotConsumeWork(): void
    {
        $this->install();
        $entry = $this->dueDelay();
        $this->db->beginTransaction();
        self::assertTrue($this->engine()->getDelayManager()->claimForDispatch($entry->getId()));
        $this->db->rollBack();
        self::assertSame(DelayStatus::SCHEDULED, $this->engine()->getDelayManager()->findById($entry->getId())->getStatus());
        $this->db->statement('DELETE FROM automation_state_lock');
        $this->expectException(\RuntimeException::class);
        (new DatabaseDelayManager($this->db))->claimForDispatch($entry->getId());
    }

    public function testUnclaimedDelayCannotBeMarkedDispatched(): void
    {
        $this->install();
        $entry = $this->dueDelay();
        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->getDelayManager()->markDispatched($entry->getId());
    }

    public function testPersistentEngineRecordsDryRunWithoutCreatingApproval(): void
    {
        $this->install();
        $rule = new \Coleza\Domain\Automation\Engine\AutomationRule('r', 'Dry run', new \Coleza\Domain\Automation\Triggers\EventTrigger('event'),
            new \Coleza\Domain\Automation\Branching\IfElseBranch(new \Coleza\Domain\Automation\Conditions\FieldCondition('ok', \Coleza\Domain\Automation\Conditions\ConditionOperator::EQUALS, true),
                [new ActionDefinition('a', 'send', ['invoice' => '{{ invoice_id }}'])]),
            executionMode: ExecutionMode::DRY_RUN, approvalRequirement: new ApprovalRequirement());
        $context = new TriggerContext('event', ['ok' => true, 'invoice_id' => 42]);
        $this->engine()->executeRule($rule, $context);
        self::assertSame([], $this->engine()->getApprovalManager()->listPending());
        $history = $this->engine()->getHistoryRepository()->findByCorrelationId($context->getCorrelationId());
        self::assertCount(1, $history);
        self::assertSame(ExecutionMode::DRY_RUN, $history[0]->getExecutionMode());
        self::assertSame(['invoice' => '42'], $history[0]->getActionResults()[0]['output']['resolved_parameters']);
    }

    public function testApplicationContainerUsesDurableEngineAcrossContainers(): void
    {
        $this->install();
        $makeContainer = function () {
            $container = new \Coleza\Foundation\Container\Container();
            (new \Coleza\Foundation\Bootstrap\CoreServiceProvider())->register($container);
            $container->instance(\Coleza\Foundation\Database\Connection::class, $this->db);
            $container->instance(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
            return $container;
        };
        $first = $makeContainer();
        $engine = $first->get(\Coleza\Domain\Automation\Engine\AutomationEngine::class);
        self::assertInstanceOf(DatabaseApprovalManager::class, $engine->getApprovalManager());
        self::assertInstanceOf(\Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::class, $engine->getVersionManager());
        self::assertSame($engine->getVersionManager(), $first->get(\Coleza\Domain\Automation\Versioning\RuleVersionManagerInterface::class));
        self::assertSame($engine->getApprovalDelayCoordinator(), $first->get(\Coleza\Domain\Automation\Approval\ApprovalDelayCoordinatorInterface::class));
        self::assertSame($engine->getDelayManager(), $first->get(\Coleza\Domain\Automation\Delay\DelayManagerInterface::class));
        self::assertSame($engine->getHistoryRepository(), $first->get(\Coleza\Domain\Automation\Execution\RunHistoryRepositoryInterface::class));
        $first->get(\Coleza\Domain\Automation\Safety\EmergencyPauseManagerInterface::class)->pause('actor', 'container restart');
        $again = $makeContainer()->get(\Coleza\Domain\Automation\Engine\AutomationEngine::class);
        self::assertTrue($again->getEmergencyPauseManager()->isPaused());
        self::assertSame('container restart', $again->getEmergencyPauseManager()->getState()->getReason());
    }
}
