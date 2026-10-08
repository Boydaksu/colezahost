<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Actions\Handlers\LogActionHandler;
use Coleza\Domain\Automation\Actions\Handlers\SetContextActionHandler;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Engine\AutomationEngine;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use PHPUnit\Framework\TestCase;

final class AutomationEngineTest extends TestCase
{
    private ActionRegistry $registry;
    private LogActionHandler $logHandler;
    private AutomationEngine $engine;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();
        $this->logHandler = new LogActionHandler();

        $this->registry->register($this->logHandler);
        $this->registry->register(new SetContextActionHandler());

        $this->engine = new AutomationEngine($this->registry);
    }

    public function testDisabledRuleIsSkipped(): void
    {
        $rule = new AutomationRule(
            id: 'rule_1',
            name: 'Disabled Test Rule',
            trigger: new EventTrigger('order.created'),
            branch: new IfElseBranch(
                new FieldCondition('order.total', ConditionOperator::GREATER_THAN, 100),
                [new ActionDefinition('a1', 'log', ['message' => 'Large order'])]
            ),
            enabled: false
        );

        $context = new TriggerContext('order.created', ['order' => ['total' => 500]]);
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('Rule is disabled', $result->getSkippedReason());
        $this->assertCount(0, $result->getActionResults());
        $this->assertCount(0, $this->logHandler->getLoggedMessages());
    }

    public function testTriggerMismatchIsSkipped(): void
    {
        $rule = new AutomationRule(
            id: 'rule_2',
            name: 'Service Rule',
            trigger: new EventTrigger('service.created'),
            branch: new IfElseBranch(
                new FieldCondition('status', ConditionOperator::EQUALS, 'active'),
                [new ActionDefinition('a1', 'log', ['message' => 'Service created'])]
            )
        );

        $context = new TriggerContext('invoice.paid', ['invoice_id' => 'INV-1']);
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('Trigger criteria did not match', $result->getSkippedReason());
    }

    public function testIfElseExecutionBranches(): void
    {
        $rule = new AutomationRule(
            id: 'rule_overdue',
            name: 'Overdue Suspension Notice',
            trigger: new EventTrigger('invoice.overdue'),
            branch: new IfElseBranch(
                condition: new FieldCondition('days_overdue', ConditionOperator::GREATER_THAN, 7),
                thenActions: [
                    new ActionDefinition('act_suspend_log', 'log', [
                        'level' => 'critical',
                        'message' => 'Invoice {{ invoice_id }} is {{ days_overdue }} days overdue: suspend service',
                    ]),
                ],
                elseActions: [
                    new ActionDefinition('act_grace_log', 'log', [
                        'level' => 'info',
                        'message' => 'Invoice {{ invoice_id }} is {{ days_overdue }} days overdue: in grace period',
                    ]),
                ]
            )
        );

        // Case 1: THEN branch (> 7 days)
        $contextThen = new TriggerContext('invoice.overdue', [
            'invoice_id' => 'INV-999',
            'days_overdue' => 10,
        ]);
        $resultThen = $this->engine->executeRule($rule, $contextThen);

        $this->assertTrue($resultThen->isExecuted());
        $this->assertTrue($resultThen->isConditionPassed());
        $this->assertSame('THEN', $resultThen->getBranchTaken());
        $this->assertTrue($resultThen->isSuccessful());
        $this->assertCount(1, $resultThen->getActionResults());
        $this->assertSame('act_suspend_log', $resultThen->getActionResults()[0]->getActionId());

        $this->logHandler->clear();

        // Case 2: ELSE branch (<= 7 days)
        $contextElse = new TriggerContext('invoice.overdue', [
            'invoice_id' => 'INV-888',
            'days_overdue' => 3,
        ]);
        $resultElse = $this->engine->executeRule($rule, $contextElse);

        $this->assertTrue($resultElse->isExecuted());
        $this->assertFalse($resultElse->isConditionPassed());
        $this->assertSame('ELSE', $resultElse->getBranchTaken());
        $this->assertTrue($resultElse->isSuccessful());
        $this->assertCount(1, $resultElse->getActionResults());
        $this->assertSame('act_grace_log', $resultElse->getActionResults()[0]->getActionId());
    }

    public function testChainedActionExecutionWithContextEnhancement(): void
    {
        $executedOrder = [];

        $this->registry->register(new CallbackActionHandler('action_one', function ($action, $context) use (&$executedOrder) {
            $executedOrder[] = 'one';
            return ['step' => 1];
        }));

        $this->registry->register(new CallbackActionHandler('action_two', function ($action, $context) use (&$executedOrder) {
            $executedOrder[] = 'two:' . $context->get('intermediate_state');
            return ['step' => 2];
        }));

        $rule = new AutomationRule(
            id: 'rule_chained',
            name: 'Chained Pipeline',
            trigger: new EventTrigger('pipeline.start'),
            branch: new IfElseBranch(
                condition: new FieldCondition('start', ConditionOperator::EQUALS, true),
                thenActions: [
                    new ActionDefinition('a1', 'action_one'),
                    new ActionDefinition('a_set', 'context.set', [
                        'key' => 'intermediate_state',
                        'value' => 'stage_alpha_complete',
                    ]),
                    new ActionDefinition('a2', 'action_two'),
                ]
            )
        );

        $context = new TriggerContext('pipeline.start', ['start' => true]);
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(['one', 'two:stage_alpha_complete'], $executedOrder);
        $this->assertSame('stage_alpha_complete', $context->get('intermediate_state'));
    }

    public function testActionFailureHandledGracefully(): void
    {
        $this->registry->register(new CallbackActionHandler('failing_op', function () {
            throw new \RuntimeException('Remote API timeout');
        }));

        $rule = new AutomationRule(
            id: 'rule_fail',
            name: 'Faulty Rule',
            trigger: new EventTrigger('test.event'),
            branch: new IfElseBranch(
                condition: new FieldCondition('ready', ConditionOperator::EQUALS, true),
                thenActions: [
                    new ActionDefinition('f1', 'failing_op'),
                ]
            )
        );

        $context = new TriggerContext('test.event', ['ready' => true]);
        $result = $this->engine->executeRule($rule, $context);

        $this->assertTrue($result->isExecuted());
        $this->assertTrue($result->hasActionFailures());
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Remote API timeout', $result->getActionResults()[0]->getErrorMessage());
    }

    public function testProcessEventRespectsPriorityOrdering(): void
    {
        $executionTrail = [];

        $this->registry->register(new CallbackActionHandler('record_priority', function ($action) use (&$executionTrail) {
            $executionTrail[] = $action->getParameters()['tag'];
            return ['ok' => true];
        }));

        $ruleLow = new AutomationRule(
            id: 'rule_low',
            name: 'Low Priority Rule',
            trigger: new EventTrigger('cron.daily'),
            branch: new IfElseBranch(
                new FieldCondition('run', ConditionOperator::EQUALS, true),
                [new ActionDefinition('act_low', 'record_priority', ['tag' => 'priority_200'])]
            ),
            priority: 200
        );

        $ruleHigh = new AutomationRule(
            id: 'rule_high',
            name: 'High Priority Rule',
            trigger: new EventTrigger('cron.daily'),
            branch: new IfElseBranch(
                new FieldCondition('run', ConditionOperator::EQUALS, true),
                [new ActionDefinition('act_high', 'record_priority', ['tag' => 'priority_10'])]
            ),
            priority: 10
        );

        $context = new TriggerContext('cron.daily', ['run' => true]);
        // Pass in random order
        $report = $this->engine->processEvent($context, [$ruleLow, $ruleHigh]);

        $this->assertSame(2, $report->count());
        $this->assertSame(['priority_10', 'priority_200'], $executionTrail);
        $this->assertFalse($report->hasFailures());
        $this->assertCount(2, $report->getExecutedResults());
        $this->assertCount(0, $report->getFailedResults());
    }
}
