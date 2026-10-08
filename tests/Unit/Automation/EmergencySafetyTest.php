<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Engine\AutomationEngine;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Execution\InMemoryRunHistoryRepository;
use Coleza\Domain\Automation\Execution\RunStatus;
use Coleza\Domain\Automation\Safety\BlastRadiusLimiter;
use Coleza\Domain\Automation\Safety\DestructiveActionRegistry;
use Coleza\Domain\Automation\Safety\EmergencyPauseManager;
use Coleza\Domain\Automation\Safety\EmergencyPauseState;
use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmergencySafetyTest extends TestCase
{
    public function testEmergencyPauseStateDefaultsAndActive(): void
    {
        $state = EmergencyPauseState::active();
        $this->assertFalse($state->isPaused());
        $this->assertNull($state->getPausedBy());
        $this->assertNull($state->getReason());
        $this->assertNull($state->getPausedAt());
        $this->assertSame('ALL', $state->getScope());

        $paused = EmergencyPauseState::paused('admin_1', 'Security audit required', 'DESTRUCTIVE');
        $this->assertTrue($paused->isPaused());
        $this->assertSame('admin_1', $paused->getPausedBy());
        $this->assertSame('Security audit required', $paused->getReason());
        $this->assertNotNull($paused->getPausedAt());
        $this->assertSame('DESTRUCTIVE', $paused->getScope());

        $arr = $paused->toArray();
        $this->assertTrue($arr['is_paused']);
        $this->assertSame('admin_1', $arr['paused_by']);
        $this->assertSame('DESTRUCTIVE', $arr['scope']);
    }

    public function testEmergencyPauseManagerPauseResumeAndScope(): void
    {
        $registry = new DestructiveActionRegistry();
        $manager = new EmergencyPauseManager($registry);

        $this->assertFalse($manager->isPaused());
        $this->assertFalse($manager->getState()->isPaused());

        // Global pause
        $manager->pause('admin_ops', 'Investigating mass deletion anomaly', 'ALL');
        $this->assertTrue($manager->isPaused());
        $this->assertTrue($manager->isPaused('service.terminate'));
        $this->assertTrue($manager->isPaused('notification.email'));
        $this->assertTrue($manager->getState()->isPaused());
        $this->assertSame('admin_ops', $manager->getState()->getPausedBy());

        // Resume
        $manager->resume('lead_eng', 'Anomaly resolved, safe to resume');
        $this->assertFalse($manager->isPaused());
        $this->assertSame('lead_eng', $manager->getState()->getResumedBy());
        $this->assertNotNull($manager->getState()->getResumedAt());

        // Destructive-only scope pause
        $manager->pause('sec_team', 'Blast radius precaution', 'DESTRUCTIVE');
        $this->assertTrue($manager->isPaused('service.terminate'));
        $this->assertTrue($manager->isPaused('service.cancel'));
        $this->assertFalse($manager->isPaused('notification.email'));
        $this->assertFalse($manager->isPaused('billing.create_invoice'));
    }

    public function testDestructiveActionRegistry(): void
    {
        $registry = new DestructiveActionRegistry();

        $this->assertTrue($registry->isDestructive('service.terminate'));
        $this->assertTrue($registry->isDestructive('server.destroy'));
        $this->assertFalse($registry->isDestructive('notification.email'));
        $this->assertSame(10, $registry->getRiskLevel('service.terminate'));

        // Register custom action with risk clamping
        $registry->registerDestructive('dns.zone_purge', 15);
        $this->assertTrue($registry->isDestructive('dns.zone_purge'));
        $this->assertSame(10, $registry->getRiskLevel('dns.zone_purge')); // Clamped to max 10

        // Unregister
        $registry->unregisterDestructive('dns.zone_purge');
        $this->assertFalse($registry->isDestructive('dns.zone_purge'));
    }

    public function testBlastRadiusLimiterWithinLimitAndTripping(): void
    {
        $pauseManager = new EmergencyPauseManager();
        $limiter = new BlastRadiusLimiter(
            maxDestructiveActions: 2,
            windowSeconds: 3600,
            registry: new DestructiveActionRegistry(),
            emergencyPause: $pauseManager
        );

        $this->assertTrue($limiter->canExecute('service.terminate'));
        $this->assertSame(0, $limiter->getRecentDestructiveCount());

        // Non-destructive actions do not increment limiter
        $this->assertTrue($limiter->recordAndCheck('notification.email'));
        $this->assertSame(0, $limiter->getRecentDestructiveCount());

        // Destructive action 1
        $this->assertTrue($limiter->recordAndCheck('service.terminate', 'srv_1'));
        $this->assertSame(1, $limiter->getRecentDestructiveCount());
        $this->assertTrue($limiter->canExecute('service.terminate'));

        // Destructive action 2
        $this->assertTrue($limiter->recordAndCheck('service.terminate', 'srv_2'));
        $this->assertSame(2, $limiter->getRecentDestructiveCount());
        $this->assertFalse($limiter->canExecute('service.terminate'));

        // Destructive action 3 (exceeds limit 2) -> trips emergency pause
        $allowed = $limiter->recordAndCheck('service.terminate', 'srv_3');
        $this->assertFalse($allowed);
        $this->assertTrue($pauseManager->isPaused('service.terminate'));
        $this->assertSame('BlastRadiusLimiter', $pauseManager->getState()->getPausedBy());
        $this->assertSame('DESTRUCTIVE', $pauseManager->getState()->getScope());

        // Reset
        $limiter->reset();
        $this->assertSame(0, $limiter->getRecentDestructiveCount());
    }

    public function testAutomationEngineWithGlobalEmergencyPauseBlocksExecution(): void
    {
        $pauseManager = new EmergencyPauseManager();
        $historyRepo = new InMemoryRunHistoryRepository();
        $registry = new ActionRegistry();

        $actionRan = false;
        $registry->register(new CallbackActionHandler(
            'service.terminate',
            function () use (&$actionRan) {
                $actionRan = true;
                return ['success' => true];
            }
        ));

        $engine = new AutomationEngine(
            actionRegistry: $registry,
            historyRepository: $historyRepo,
            emergencyPauseManager: $pauseManager
        );

        $condition = new FieldCondition('service_id', ConditionOperator::GREATER_THAN, 0);

        $rule = new AutomationRule(
            id: 'rule_clean',
            name: 'Cleanup Old Services',
            trigger: new EventTrigger('service.expired'),
            branch: new IfElseBranch(
                $condition,
                [new ActionDefinition('act_1', 'service.terminate', ['service_id' => 123])]
            )
        );

        // Pause engine globally
        $pauseManager->pause('ops_admin', 'Suspicious activity detected', 'ALL');

        $result = $engine->executeRule($rule, new TriggerContext('service.expired', ['service_id' => 123]));

        $this->assertTrue($result->isPaused());
        $this->assertFalse($result->isExecuted());
        $this->assertFalse($actionRan);
        $this->assertSame(RunStatus::PAUSED, $result->getStatus());
        $this->assertStringContainsString('Emergency pause active', (string) $result->getSkippedReason());

        // Run history should record PAUSED
        $runs = $historyRepo->findByRuleId('rule_clean');
        $this->assertCount(1, $runs);
        $this->assertSame(RunStatus::PAUSED, $runs[0]->getStatus());
    }

    public function testAutomationEngineWithDestructiveEmergencyPauseBlocksOnlyDestructiveActions(): void
    {
        $pauseManager = new EmergencyPauseManager();
        $registry = new ActionRegistry();

        $terminateRan = false;
        $registry->register(new CallbackActionHandler(
            'service.terminate',
            function () use (&$terminateRan) {
                $terminateRan = true;
                return ['terminated' => true];
            }
        ));

        $notifyRan = false;
        $registry->register(new CallbackActionHandler(
            'notification.send',
            function () use (&$notifyRan) {
                $notifyRan = true;
                return ['sent' => true];
            }
        ));

        $engine = new AutomationEngine(
            actionRegistry: $registry,
            emergencyPauseManager: $pauseManager
        );

        // Pause only destructive actions
        $pauseManager->pause('sec_bot', 'Destructive containment', 'DESTRUCTIVE');

        $alwaysTrue = new FieldCondition('status', ConditionOperator::EQUALS, 'active');

        // Rule with safe action executes successfully
        $safeRule = new AutomationRule(
            id: 'safe_rule',
            name: 'Send Customer Notice',
            trigger: new EventTrigger('service.warning'),
            branch: new IfElseBranch(
                $alwaysTrue,
                [new ActionDefinition('act_safe', 'notification.send', ['to' => 'user@example.com'])]
            )
        );

        $safeResult = $engine->executeRule($safeRule, new TriggerContext('service.warning', ['status' => 'active']));
        $this->assertTrue($safeResult->isExecuted());
        $this->assertTrue($safeResult->isSuccessful());
        $this->assertTrue($notifyRan);

        // Rule with destructive action gets action blocked
        $destructiveRule = new AutomationRule(
            id: 'dest_rule',
            name: 'Terminate Cancelled Service',
            trigger: new EventTrigger('service.cancelled'),
            branch: new IfElseBranch(
                $alwaysTrue,
                [new ActionDefinition('act_dest', 'service.terminate', ['service_id' => 99])]
            )
        );

        $destResult = $engine->executeRule($destructiveRule, new TriggerContext('service.cancelled', ['status' => 'active']));
        $this->assertTrue($destResult->isExecuted());
        $this->assertFalse($destResult->isSuccessful());
        $this->assertFalse($terminateRan);
        $this->assertSame(RunStatus::FAILED, $destResult->getStatus());
        $this->assertStringContainsString('blocked by emergency pause', (string) $destResult->getActionResults()[0]->getErrorMessage());
    }

    public function testAutomationEngineWithBlastRadiusLimiter(): void
    {
        $pauseManager = new EmergencyPauseManager();
        $limiter = new BlastRadiusLimiter(
            maxDestructiveActions: 2,
            windowSeconds: 3600,
            registry: new DestructiveActionRegistry(),
            emergencyPause: $pauseManager
        );

        $registry = new ActionRegistry();
        $terminatedCount = 0;
        $registry->register(new CallbackActionHandler(
            'service.terminate',
            function () use (&$terminatedCount) {
                $terminatedCount++;
                return ['terminated' => true];
            }
        ));

        $engine = new AutomationEngine(
            actionRegistry: $registry,
            emergencyPauseManager: $pauseManager,
            blastRadiusLimiter: $limiter
        );

        $condition = new FieldCondition('id', ConditionOperator::GREATER_THAN, 0);

        $makeRule = fn (int $id) => new AutomationRule(
            id: "rule_{$id}",
            name: "Terminate {$id}",
            trigger: new EventTrigger('cron.cleanup'),
            branch: new IfElseBranch(
                $condition,
                [new ActionDefinition("act_{$id}", 'service.terminate', ['id' => $id])]
            )
        );

        // Execution 1: Allowed
        $res1 = $engine->executeRule($makeRule(1), new TriggerContext('cron.cleanup', ['id' => 1]));
        $this->assertTrue($res1->isSuccessful());
        $this->assertSame(1, $terminatedCount);

        // Execution 2: Allowed (reaches limit 2)
        $res2 = $engine->executeRule($makeRule(2), new TriggerContext('cron.cleanup', ['id' => 2]));
        $this->assertTrue($res2->isSuccessful());
        $this->assertSame(2, $terminatedCount);

        // Execution 3: Exceeds blast radius -> trips pause and action fails
        $res3 = $engine->executeRule($makeRule(3), new TriggerContext('cron.cleanup', ['id' => 3]));
        $this->assertFalse($res3->isSuccessful());
        $this->assertSame(2, $terminatedCount); // Did not execute
        $this->assertTrue($pauseManager->isPaused('service.terminate'));

        // Execution 4: Blocked by emergency pause
        $res4 = $engine->executeRule($makeRule(4), new TriggerContext('cron.cleanup', ['id' => 4]));
        $this->assertFalse($res4->isSuccessful());
        $this->assertSame(2, $terminatedCount);
    }

    public function testOverdueLifecycleWorkflowRespectsEmergencyPause(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = new Connection($pdo, 'sqlite');

        $serviceService = new ServiceService($db);
        $serviceService->ensureTables();

        $invoiceService = new InvoiceService($db);
        $invoiceService->ensureTables();

        $pauseManager = new EmergencyPauseManager();

        $activeService = $serviceService->createService([
            'user_id' => 10,
            'organization_id' => 1,
            'product_id' => 1,
            'domain' => 'client1.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1000,
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-09-01', // 39 days overdue relative to 2026-10-10
            'metadata' => ['customer_email' => 'client1@example.com'],
        ]);

        $suspendedService = $serviceService->createService([
            'user_id' => 20,
            'organization_id' => 1,
            'product_id' => 1,
            'domain' => 'client2.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 2000,
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_SUSPENDED,
            'next_due_date' => '2026-08-01', // 70 days overdue relative to 2026-10-10
            'metadata' => ['customer_email' => 'client2@example.com'],
        ]);

        // Pause all automations
        $pauseManager->pause('incident_response', 'Billing system under maintenance', 'ALL');

        $workflow = new OverdueLifecycleWorkflow(
            serviceService: $serviceService,
            invoiceService: $invoiceService,
            emergencyPauseManager: $pauseManager
        );

        $policy = new OverdueGracePolicy(
            gracePeriodDays: 5,
            terminationGraceDays: 30
        );

        $report = $workflow->evaluateAndProcessOverdue($policy, '2026-10-10');

        $this->assertSame(2, $report->totalEvaluated());
        $this->assertSame(0, $report->getSuspendedCount());
        $this->assertSame(0, $report->getTerminatedCount());
        $this->assertSame(2, $report->getPausedCount());

        $results = $report->getResults();
        $this->assertTrue($results[0]->isPaused());
        $this->assertStringContainsString('Suspension blocked', $results[0]->getReason());
        $this->assertTrue($results[1]->isPaused());
        $this->assertStringContainsString('Termination blocked', $results[1]->getReason());

        // Verify database statuses were completely unchanged
        $service1 = $serviceService->findServiceById($activeService->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $service1->getStatus());

        $service2 = $serviceService->findServiceById($suspendedService->getId());
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $service2->getStatus());
    }
}
