<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceBillingRelation;
use Coleza\Domain\Commerce\Services\ServiceCancellationRequest;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleAndPlacementTest extends TestCase
{
    private Connection $db;
    private ServiceService $serviceService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();
    }

    public function testServiceCreationIncludesDefaultBillingRelation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 100,
            'product_id' => 1,
            'billing_cycle' => PriceCycle::MONTHLY,
            'recurring_amount_minor' => 2500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-01',
            'next_due_date' => '2026-11-01',
        ]);

        $billing = $service->getBillingRelation();
        $this->assertInstanceOf(ServiceBillingRelation::class, $billing);
        $this->assertSame(PriceCycle::MONTHLY, $billing->getBillingCycle());
        $this->assertSame(2500, $billing->getRecurringAmountMinor());
        $this->assertSame('USD', $billing->getCurrencyCode());
        $this->assertTrue($billing->isAutoRenew());
        $this->assertSame(7, $billing->getGracePeriodDays());
        $this->assertSame(30, $billing->getTerminationGracePeriodDays());
        $this->assertSame('2026-10-18', $billing->calculateNextInvoiceDate(14));
        $this->assertFalse($billing->isOverdue('2026-10-15'));
        $this->assertSame(0, $billing->daysOverdue('2026-10-15'));
    }

    public function testServiceCreationWithCustomGracePeriods(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 101,
            'product_id' => 2,
            'billing_cycle' => PriceCycle::ANNUALLY,
            'recurring_amount_minor' => 24000,
            'currency_code' => 'EUR',
            'registration_date' => '2026-10-01',
            'next_due_date' => '2027-10-01',
            'auto_renew' => 0,
            'grace_period_days' => 14,
            'termination_grace_period_days' => 60,
        ]);

        $billing = $service->getBillingRelation();
        $this->assertFalse($billing->isAutoRenew());
        $this->assertSame(14, $billing->getGracePeriodDays());
        $this->assertSame(60, $billing->getTerminationGracePeriodDays());
    }

    public function testPlacementAssignmentAndHydration(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 102,
            'product_id' => 3,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $this->assertNull($service->getPlacement());

        $placement = $this->serviceService->assignPlacement($service->getId(), [
            'server_id' => 5,
            'server_pool_id' => 2,
            'location_id' => 1,
            'status' => ServicePlacement::STATUS_PLACED,
            'package_identifier' => 'cpanel_starter',
            'dedicated_ip' => '192.168.1.100',
            'hostname' => 'node01.colezahost.net',
            'disk_limit_mb' => 10240,
            'bandwidth_limit_mb' => 51200,
            'resource_quotas' => ['inodes' => 250000, 'databases' => 5],
        ]);

        $this->assertInstanceOf(ServicePlacement::class, $placement);
        $this->assertSame($service->getId(), $placement->getServiceId());
        $this->assertSame(5, $placement->getServerId());
        $this->assertSame('cpanel_starter', $placement->getPackageIdentifier());
        $this->assertSame('192.168.1.100', $placement->getDedicatedIp());
        $this->assertSame('node01.colezahost.net', $placement->getHostname());
        $this->assertSame(10240, $placement->getDiskLimitMb());
        $this->assertSame(51200, $placement->getBandwidthLimitMb());
        $this->assertSame(['inodes' => 250000, 'databases' => 5], $placement->getResourceQuotas());
        $this->assertTrue($placement->isPlaced());

        // Refresh service and ensure placement is hydrated
        $refreshed = $this->serviceService->findServiceById($service->getId());
        $this->assertNotNull($refreshed->getPlacement());
        $this->assertSame($placement->getId(), $refreshed->getPlacement()->getId());
        $this->assertSame('node01.colezahost.net', $refreshed->getServerName());
        $this->assertSame('192.168.1.100', $refreshed->getIpAddress());
    }

    public function testPlacementReassignmentEvictsPreviousPlacement(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 103,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $firstPlacement = $this->serviceService->assignPlacement($service->getId(), [
            'server_id' => 1,
            'hostname' => 'node-old.colezahost.net',
        ]);
        $this->assertTrue($firstPlacement->isPlaced());

        $secondPlacement = $this->serviceService->assignPlacement($service->getId(), [
            'server_id' => 2,
            'hostname' => 'node-new.colezahost.net',
        ]);
        $this->assertSame(2, $secondPlacement->getServerId());

        $refreshed = $this->serviceService->findServiceById($service->getId());
        $this->assertSame($secondPlacement->getId(), $refreshed->getPlacement()->getId());
        $this->assertSame(2, $refreshed->getPlacement()->getServerId());
    }

    public function testPlacementRelease(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 104,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $this->serviceService->assignPlacement($service->getId(), [
            'server_id' => 1,
            'status' => ServicePlacement::STATUS_PLACED,
        ]);

        $released = $this->serviceService->releasePlacement($service->getId(), ServicePlacement::STATUS_EVICTED);
        $this->assertNotNull($released);
        $this->assertTrue($released->isEvicted());
        $this->assertNotNull($released->getReleasedAt());
    }

    public function testImmediateCancellationWorkflow(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 105,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $this->serviceService->assignPlacement($service->getId(), [
            'server_id' => 1,
            'status' => ServicePlacement::STATUS_PLACED,
        ]);

        $cancellation = $this->serviceService->requestCancellation(
            serviceId: $service->getId(),
            userId: 105,
            type: ServiceCancellationRequest::TYPE_IMMEDIATE,
            reason: 'Migrating to dedicated server'
        );

        $this->assertTrue($cancellation->isImmediate());
        $this->assertTrue($cancellation->isProcessed());
        $this->assertNotNull($cancellation->getProcessedAt());

        $refreshed = $this->serviceService->findServiceById($service->getId());
        $this->assertTrue($refreshed->isCancelled());
        $this->assertTrue($refreshed->getPlacement()->isEvicted());
    }

    public function testEndOfPeriodCancellationAndRevocation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 106,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-11-01',
        ]);

        $cancellation = $this->serviceService->requestCancellation(
            serviceId: $service->getId(),
            userId: 106,
            type: ServiceCancellationRequest::TYPE_END_OF_PERIOD,
            reason: 'Project closing soon'
        );

        $this->assertTrue($cancellation->isEndOfPeriod());
        $this->assertTrue($cancellation->isPending());
        $this->assertNull($cancellation->getProcessedAt());

        $refreshed = $this->serviceService->findServiceById($service->getId());
        $this->assertTrue($refreshed->isActive());
        $this->assertTrue($refreshed->hasPendingCancellation());

        // Revoke cancellation
        $revoked = $this->serviceService->revokeCancellation($service->getId(), 106);
        $this->assertTrue($revoked->isRevoked());

        $refreshedAfterRevoke = $this->serviceService->findServiceById($service->getId());
        $this->assertFalse($refreshedAfterRevoke->hasPendingCancellation());
    }

    public function testProcessDueCancellations(): void
    {
        $service1 = $this->serviceService->createService([
            'user_id' => 107,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-10-15',
        ]);
        $this->serviceService->assignPlacement($service1->getId(), ['server_id' => 1]);

        $service2 = $this->serviceService->createService([
            'user_id' => 108,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-10-25',
        ]);

        $this->serviceService->requestCancellation($service1->getId(), 107, ServiceCancellationRequest::TYPE_END_OF_PERIOD, 'End of term 1');
        $this->serviceService->requestCancellation($service2->getId(), 108, ServiceCancellationRequest::TYPE_END_OF_PERIOD, 'End of term 2');

        // On 2026-10-10, neither is due
        $processedEarly = $this->serviceService->processDueCancellations('2026-10-10');
        $this->assertCount(0, $processedEarly);

        // On 2026-10-15, service1 is due
        $processed = $this->serviceService->processDueCancellations('2026-10-15');
        $this->assertCount(1, $processed);
        $this->assertSame($service1->getId(), $processed[0]['service_id']);

        $refreshed1 = $this->serviceService->findServiceById($service1->getId());
        $this->assertTrue($refreshed1->isCancelled());
        $this->assertTrue($refreshed1->getPlacement()->isEvicted());

        $refreshed2 = $this->serviceService->findServiceById($service2->getId());
        $this->assertTrue($refreshed2->isActive());
        $this->assertTrue($refreshed2->hasPendingCancellation());
    }

    public function testBillingLifecycleEvaluation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 109,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-10-10',
            'grace_period_days' => 7,
            'termination_grace_period_days' => 30,
        ]);

        // Prior to due date
        $evalBefore = $this->serviceService->evaluateBillingLifecycle($service->getId(), '2026-10-09');
        $this->assertFalse($evalBefore['is_overdue']);
        $this->assertSame(0, $evalBefore['days_overdue']);
        $this->assertFalse($evalBefore['is_suspension_due']);
        $this->assertFalse($evalBefore['is_termination_due']);
        $this->assertSame('none', $evalBefore['recommended_action']);

        // Overdue by 3 days (within grace period)
        $evalInGrace = $this->serviceService->evaluateBillingLifecycle($service->getId(), '2026-10-13');
        $this->assertTrue($evalInGrace['is_overdue']);
        $this->assertSame(3, $evalInGrace['days_overdue']);
        $this->assertFalse($evalInGrace['is_suspension_due']);
        $this->assertSame('send_reminder', $evalInGrace['recommended_action']);

        // Overdue by 8 days (past 7-day grace period)
        $evalSuspension = $this->serviceService->evaluateBillingLifecycle($service->getId(), '2026-10-18');
        $this->assertTrue($evalSuspension['is_overdue']);
        $this->assertSame(8, $evalSuspension['days_overdue']);
        $this->assertTrue($evalSuspension['is_suspension_due']);
        $this->assertSame('suspend', $evalSuspension['recommended_action']);

        // Suspend the service
        $this->serviceService->suspendService($service->getId(), 'Overdue payment');

        // Past 30 days overdue (past termination grace)
        $evalTerm = $this->serviceService->evaluateBillingLifecycle($service->getId(), '2026-11-15');
        $this->assertTrue($evalTerm['is_termination_due']);
        $this->assertSame('terminate', $evalTerm['recommended_action']);
    }

    public function testProcessOverdueServicesAutomatedSuspensionAndTermination(): void
    {
        // 1. Active service past grace period (due 2026-10-01, evaluating 2026-10-12 => 11 days overdue)
        $serviceToSuspend = $this->serviceService->createService([
            'user_id' => 110,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-10-01',
            'grace_period_days' => 7,
            'termination_grace_period_days' => 30,
        ]);

        // 2. Suspended service past termination grace (due 2026-09-01, evaluating 2026-10-12 => 41 days overdue)
        $serviceToTerminate = $this->serviceService->createService([
            'user_id' => 111,
            'product_id' => 2,
            'status' => ServiceStateMachine::STATUS_SUSPENDED,
            'next_due_date' => '2026-09-01',
            'grace_period_days' => 7,
            'termination_grace_period_days' => 30,
        ]);
        $this->serviceService->assignPlacement($serviceToTerminate->getId(), ['server_id' => 1]);

        $actions = $this->serviceService->processOverdueServices('2026-10-12');
        $this->assertCount(2, $actions);

        $terminatedAction = current(array_filter($actions, fn ($a) => $a['service_id'] === $serviceToTerminate->getId()));
        $suspendedAction = current(array_filter($actions, fn ($a) => $a['service_id'] === $serviceToSuspend->getId()));

        $this->assertSame('terminated', $terminatedAction['action']);
        $this->assertSame('suspended', $suspendedAction['action']);

        $refreshedTerminated = $this->serviceService->findServiceById($serviceToTerminate->getId());
        $this->assertTrue($refreshedTerminated->isTerminated());
        $this->assertTrue($refreshedTerminated->getPlacement()->isEvicted());

        $refreshedSuspended = $this->serviceService->findServiceById($serviceToSuspend->getId());
        $this->assertTrue($refreshedSuspended->isSuspended());
    }

    public function testCancellationValidationRules(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 112,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_TERMINATED,
        ]);

        // Cannot cancel already terminated service
        $this->expectException(ValidationException::class);
        $this->serviceService->requestCancellation($service->getId(), 112, ServiceCancellationRequest::TYPE_END_OF_PERIOD, 'Too late');
    }

    public function testDuplicatePendingCancellationRejected(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 113,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $this->serviceService->requestCancellation($service->getId(), 113, ServiceCancellationRequest::TYPE_END_OF_PERIOD, 'First request');

        $this->expectException(ValidationException::class);
        $this->serviceService->requestCancellation($service->getId(), 113, ServiceCancellationRequest::TYPE_END_OF_PERIOD, 'Duplicate request');
    }

    public function testUnauthorizedCancellationRequestRejected(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 114,
            'product_id' => 1,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $this->expectException(ValidationException::class);
        $this->serviceService->requestCancellation($service->getId(), 999, ServiceCancellationRequest::TYPE_END_OF_PERIOD, 'Wrong user');
    }
}
