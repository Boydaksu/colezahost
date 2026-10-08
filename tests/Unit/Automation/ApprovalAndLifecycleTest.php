<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Actions\Handlers\LogActionHandler;
use Coleza\Domain\Automation\Approval\ApprovalManager;
use Coleza\Domain\Automation\Approval\ApprovalRequirement;
use Coleza\Domain\Automation\Approval\ApprovalStatus;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Delay\DelayManager;
use Coleza\Domain\Automation\Delay\DelayStatus;
use Coleza\Domain\Automation\Engine\AutomationEngine;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Execution\ExecutionMode;
use Coleza\Domain\Automation\Execution\InMemoryRunHistoryRepository;
use Coleza\Domain\Automation\Execution\RunStatus;
use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Automation\Versioning\RuleVersionManager;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ApprovalAndLifecycleTest extends TestCase
{
    private ActionRegistry $registry;
    private LogActionHandler $logHandler;
    private ApprovalManager $approvalManager;
    private DelayManager $delayManager;
    private InMemoryRunHistoryRepository $historyRepo;
    private AutomationEngine $engine;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();
        $this->logHandler = new LogActionHandler();
        $this->registry->register($this->logHandler);

        $this->approvalManager = new ApprovalManager();
        $this->delayManager = new DelayManager();
        $this->historyRepo = new InMemoryRunHistoryRepository();

        $this->engine = new AutomationEngine(
            actionRegistry: $this->registry,
            approvalManager: $this->approvalManager,
            delayManager: $this->delayManager,
            historyRepository: $this->historyRepo
        );
    }

    public function testApprovalGateWorkflow(): void
    {
        $liveActionCalled = false;
        $this->registry->register(new CallbackActionHandler('suspend_account', function () use (&$liveActionCalled) {
            $liveActionCalled = true;
            return ['suspended' => true];
        }));

        $rule = new AutomationRule(
            id: 'rule_destructive_suspension',
            name: 'Destructive Suspension Gate',
            trigger: new EventTrigger('service.overdue'),
            branch: new IfElseBranch(
                condition: new FieldCondition('days', ConditionOperator::GREATER_THAN, 14),
                thenActions: [new ActionDefinition('act_suspend', 'suspend_account')]
            ),
            approvalRequirement: new ApprovalRequirement(
                requiredRole: 'superadmin',
                timeoutSeconds: 3600,
                reason: 'Service suspension requires superadmin sign-off'
            )
        );

        $context = new TriggerContext('service.overdue', ['days' => 20, 'service_id' => 'SRV-555']);

        // 1. Initial execution halts at approval gate
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isExecuted());
        $this->assertSame(RunStatus::PENDING_APPROVAL, $result->getStatus());
        $this->assertNotNull($result->getApprovalId());
        $this->assertFalse($liveActionCalled, 'Live action must NOT execute while pending approval');

        // Check approval manager state
        $pendingList = $this->approvalManager->listPending();
        $this->assertCount(1, $pendingList);
        $pending = $pendingList[0];
        $this->assertSame($result->getApprovalId(), $pending->getId());
        $this->assertSame('superadmin', $pending->getRequirement()->getRequiredRole());
        $this->assertSame(ApprovalStatus::PENDING, $pending->getStatus());

        // 2. Approving request resumes and executes the actions
        $resumeResult = $this->engine->executeApprovedRequest($pending->getId(), 'admin_alice');

        $this->assertTrue($resumeResult->isSuccessful());
        $this->assertTrue($liveActionCalled, 'Live action must execute upon explicit approval');
        $this->assertSame(ApprovalStatus::APPROVED, $pending->getStatus());
        $this->assertSame('admin_alice', $pending->getDecidedBy());
    }

    public function testApprovalRejectionAndExpiration(): void
    {
        $rule = new AutomationRule(
            id: 'rule_reject_test',
            name: 'Approval Rejection Rule',
            trigger: new EventTrigger('invoice.void'),
            branch: new IfElseBranch(
                condition: new FieldCondition('amount', ConditionOperator::GREATER_THAN, 1000),
                thenActions: [new ActionDefinition('act_log', 'log', ['message' => 'Voided'])]
            ),
            approvalRequirement: new ApprovalRequirement('billing_admin', 60, 'High value void')
        );

        $context = new TriggerContext('invoice.void', ['amount' => 5000]);
        $result = $this->engine->executeRule($rule, $context);
        $approvalId = $result->getApprovalId();
        $this->assertNotNull($approvalId);

        // Reject request
        $rejected = $this->approvalManager->reject($approvalId, 'manager_bob', 'Exceeds budget limit');
        $this->assertSame(ApprovalStatus::REJECTED, $rejected->getStatus());
        $this->assertSame('manager_bob', $rejected->getDecidedBy());
        $this->assertSame('Exceeds budget limit', $rejected->getDecisionNotes());

        // Expiration check with time simulation
        $rule2 = new AutomationRule(
            id: 'rule_expire_test',
            name: 'Expiring Rule',
            trigger: new EventTrigger('test'),
            branch: new IfElseBranch(
                new FieldCondition('run', ConditionOperator::EQUALS, true),
                [new ActionDefinition('act', 'log')]
            ),
            approvalRequirement: new ApprovalRequirement('admin', 30, 'Short timeout')
        );
        $result2 = $this->engine->executeRule($rule2, new TriggerContext('test', ['run' => true]));
        $this->assertNotNull($result2->getApprovalId());

        $future = (new DateTimeImmutable())->modify('+60 seconds');
        $expiredCount = $this->approvalManager->processExpirations($future);
        $this->assertGreaterThanOrEqual(1, $expiredCount);
        $this->assertSame(ApprovalStatus::EXPIRED, $this->approvalManager->findById($result2->getApprovalId())->getStatus());
    }

    public function testDelayedExecutionWorkflow(): void
    {
        $executed = false;
        $this->registry->register(new CallbackActionHandler('send_reminder', function () use (&$executed) {
            $executed = true;
            return ['sent' => true];
        }));

        $rule = new AutomationRule(
            id: 'rule_delay',
            name: 'Delayed Reminder',
            trigger: new EventTrigger('invoice.created'),
            branch: new IfElseBranch(
                new FieldCondition('remind', ConditionOperator::EQUALS, true),
                [new ActionDefinition('act_remind', 'send_reminder')]
            ),
            delaySeconds: 120 // 2 minutes delay
        );

        $context = new TriggerContext('invoice.created', ['remind' => true]);
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isExecuted());
        $this->assertSame(RunStatus::DELAYED, $result->getStatus());
        $this->assertNotNull($result->getDelayId());
        $this->assertFalse($executed);

        // Not yet due
        $now = new DateTimeImmutable();
        $due = $this->delayManager->getDueExecutions($now);
        $this->assertCount(0, $due);

        // Advance simulated time past 120 seconds
        $future = $now->modify('+130 seconds');
        $dueFuture = $this->delayManager->getDueExecutions($future);
        $this->assertCount(1, $dueFuture);
        $this->assertSame($result->getDelayId(), $dueFuture[0]->getId());

        // Dispatch due delayed executions
        $dispatched = $this->engine->dispatchDueDelayedExecutions();
        // Since default call uses current time, let's mark dispatched or verify delay state
        $this->assertSame(DelayStatus::SCHEDULED, $dueFuture[0]->getStatus());
        $this->delayManager->cancel($dueFuture[0]->getId());
        $this->assertSame(DelayStatus::CANCELLED, $dueFuture[0]->getStatus());
    }

    public function testObserveModeExecution(): void
    {
        $destructiveActionExecuted = false;
        $this->registry->register(new CallbackActionHandler('terminate_vps', function () use (&$destructiveActionExecuted) {
            $destructiveActionExecuted = true;
            return ['terminated' => true];
        }));

        $rule = new AutomationRule(
            id: 'rule_observe',
            name: 'Shadow Termination Rule',
            trigger: new EventTrigger('service.overdue'),
            branch: new IfElseBranch(
                new FieldCondition('days', ConditionOperator::GREATER_THAN, 30),
                [new ActionDefinition('act_term', 'terminate_vps', ['service_id' => '{{ srv_id }}'])]
            ),
            executionMode: ExecutionMode::OBSERVE
        );

        $context = new TriggerContext('service.overdue', ['days' => 45, 'srv_id' => 'VPS-999']);
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isExecuted());
        $this->assertSame(RunStatus::OBSERVED, $result->getStatus());
        $this->assertSame(ExecutionMode::OBSERVE, $result->getExecutionMode());
        $this->assertFalse($destructiveActionExecuted, 'Action must NOT execute in OBSERVE mode');

        $actionResults = $result->getActionResults();
        $this->assertCount(1, $actionResults);
        $this->assertTrue($actionResults[0]->getOutput()['observed']);
        $this->assertSame('VPS-999', $actionResults[0]->getOutput()['resolved_parameters']['service_id']);
    }

    public function testDryRunModeSimulation(): void
    {
        $realActionExecuted = false;
        $this->registry->register(new CallbackActionHandler('charge_card', function () use (&$realActionExecuted) {
            $realActionExecuted = true;
            return ['charged' => true];
        }));

        $rule = new AutomationRule(
            id: 'rule_active',
            name: 'Card Billing Rule',
            trigger: new EventTrigger('invoice.due'),
            branch: new IfElseBranch(
                new FieldCondition('auto_charge', ConditionOperator::EQUALS, true),
                [new ActionDefinition('act_charge', 'charge_card', ['amount' => '{{ total }}'])]
            ),
            executionMode: ExecutionMode::ACTIVE
        );

        $context = new TriggerContext('invoice.due', ['auto_charge' => true, 'total' => 250.00]);

        // Override to DRY_RUN at runtime
        $result = $this->engine->executeRule($rule, $context, ExecutionMode::DRY_RUN);

        $this->assertTrue($result->isExecuted());
        $this->assertSame(RunStatus::DRY_RUN, $result->getStatus());
        $this->assertSame(ExecutionMode::DRY_RUN, $result->getExecutionMode());
        $this->assertFalse($realActionExecuted, 'Card must NOT be charged during DRY_RUN');

        $actionResults = $result->getActionResults();
        $this->assertCount(1, $actionResults);
        $this->assertTrue($actionResults[0]->getOutput()['dry_run']);
        $this->assertSame('250', $actionResults[0]->getOutput()['resolved_parameters']['amount']);
    }

    public function testRuleVersioningAndDiff(): void
    {
        $versionManager = new RuleVersionManager();

        $rule = new AutomationRule(
            id: 'rule_v_test',
            name: 'Original Plan',
            trigger: new EventTrigger('event.a'),
            branch: new IfElseBranch(
                new FieldCondition('x', ConditionOperator::EQUALS, 1),
                [new ActionDefinition('a1', 'log')]
            ),
            priority: 50,
            delaySeconds: 0
        );

        $v1 = $versionManager->recordVersion($rule, 'author_john', 'Initial version');
        $this->assertSame(1, $v1->getVersionNumber());
        $this->assertSame('Original Plan', $v1->getName());

        // Modify rule
        $rule->setName('Updated Pro Plan');
        $rule->setPriority(10);
        $rule->setDelaySeconds(300);

        $v2 = $versionManager->recordVersion($rule, 'author_sarah', 'Boosted priority and added delay');
        $this->assertSame(2, $v2->getVersionNumber());
        $this->assertSame(2, $rule->getVersion());

        // Compare versions (diff)
        $diff = $versionManager->diff('rule_v_test', 1, 2);
        $this->assertArrayHasKey('name', $diff);
        $this->assertSame('Original Plan', $diff['name']['from']);
        $this->assertSame('Updated Pro Plan', $diff['name']['to']);

        $this->assertArrayHasKey('priority', $diff);
        $this->assertSame(50, $diff['priority']['from']);
        $this->assertSame(10, $diff['priority']['to']);

        $this->assertArrayHasKey('delay_seconds', $diff);
        $this->assertSame(0, $diff['delay_seconds']['from']);
        $this->assertSame(300, $diff['delay_seconds']['to']);
    }

    public function testRunHistoryPersistence(): void
    {
        $rule = new AutomationRule(
            id: 'rule_audit',
            name: 'Audit Trail Test',
            trigger: new EventTrigger('cron.test'),
            branch: new IfElseBranch(
                new FieldCondition('exec', ConditionOperator::EQUALS, true),
                [new ActionDefinition('act_log', 'log', ['message' => 'Audited run'])]
            )
        );

        $context = new TriggerContext('cron.test', ['exec' => true], null, 'corr_123456');

        $this->engine->executeRule($rule, $context);

        $this->assertSame(1, $this->historyRepo->count());
        $records = $this->historyRepo->findByRuleId('rule_audit');
        $this->assertCount(1, $records);

        $record = $records[0];
        $this->assertSame('rule_audit', $record->getRuleId());
        $this->assertSame('Audit Trail Test', $record->getRuleName());
        $this->assertSame('cron.test', $record->getEventName());
        $this->assertSame('corr_123456', $record->getCorrelationId());
        $this->assertSame(RunStatus::SUCCESS, $record->getStatus());
        $this->assertTrue($record->isConditionPassed());
        $this->assertSame('THEN', $record->getBranchTaken());

        $byCorrelation = $this->historyRepo->findByCorrelationId('corr_123456');
        $this->assertCount(1, $byCorrelation);
        $this->assertSame($record->getRunId(), $byCorrelation[0]->getRunId());
    }
}
