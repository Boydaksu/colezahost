<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Coleza\Domain\Commerce\Services\Exceptions\ServiceConcurrencyException;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ConcurrencyException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ServiceConcurrencyTest extends TestCase
{
    private Connection $db;
    private ServiceService $serviceService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo);
        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();
    }

    private function createSampleService(string $status = ServiceStateMachine::STATUS_PENDING): Service
    {
        return $this->serviceService->createService([
            'user_id' => 101,
            'product_id' => 5,
            'status' => $status,
            'billing_cycle' => PriceCycle::MONTHLY,
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-01',
            'next_due_date' => '2026-11-01',
            'domain' => 'example.com',
            'username' => 'user123',
        ]);
    }

    public function testServiceInitializesWithLockVersionOne(): void
    {
        $service = $this->createSampleService();

        $this->assertSame(1, $service->getLockVersion());

        $array = $service->toArray();
        $this->assertArrayHasKey('lock_version', $array);
        $this->assertSame(1, $array['lock_version']);

        $loaded = $this->serviceService->findServiceById($service->getId());
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->getLockVersion());
    }

    public function testSuccessfulUpdateIncrementsLockVersion(): void
    {
        $service = $this->createSampleService();

        $updated = $this->serviceService->updateService(
            $service->getId(),
            ['domain' => 'new-domain.org', 'notes' => 'Domain updated'],
            expectedLockVersion: 1
        );

        $this->assertSame(2, $updated->getLockVersion());
        $this->assertSame('new-domain.org', $updated->getDomain());
        $this->assertSame('Domain updated', $updated->getNotes());

        $reloaded = $this->serviceService->findServiceById($service->getId());
        $this->assertSame(2, $reloaded->getLockVersion());
    }

    public function testConcurrentUpdateMismatchThrowsServiceConcurrencyException(): void
    {
        $service = $this->createSampleService();

        // Worker A updates service with expected version 1 -> succeeds, increments to 2
        $workerAUpdated = $this->serviceService->updateService(
            $service->getId(),
            ['domain' => 'worker-a.com'],
            expectedLockVersion: 1
        );
        $this->assertSame(2, $workerAUpdated->getLockVersion());

        // Worker B tries to update using stale version 1 -> must fail
        $this->expectException(ServiceConcurrencyException::class);
        $this->expectExceptionMessage('could not be updated due to a concurrent modification');

        try {
            $this->serviceService->updateService(
                $service->getId(),
                ['domain' => 'worker-b.com'],
                expectedLockVersion: 1
            );
        } catch (ServiceConcurrencyException $e) {
            $this->assertInstanceOf(ConcurrencyException::class, $e);
            $this->assertSame('SERVICE_CONCURRENCY_CONFLICT', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatusCode());
            $this->assertSame(1, $e->getContext()['expected_version']);
            $this->assertSame(2, $e->getContext()['actual_version']);
            $this->assertSame($service->getId(), $e->getContext()['service_id']);
            throw $e;
        }
    }

    public function testOptimisticLockingOnActivate(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_PENDING);

        // Success path with expected version 1
        $activated = $this->serviceService->activateService(
            $service->getId(),
            ['ip_address' => '192.168.1.10', 'server_name' => 'srv1.node'],
            expectedLockVersion: 1
        );

        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $activated->getStatus());
        $this->assertSame(2, $activated->getLockVersion());
        $this->assertSame('192.168.1.10', $activated->getIpAddress());

        // Attempting another activation with stale expectedLockVersion 1 throws
        $this->expectException(ServiceConcurrencyException::class);
        $this->serviceService->activateService(
            $service->getId(),
            ['ip_address' => '192.168.1.11'],
            expectedLockVersion: 1
        );
    }

    public function testOptimisticLockingOnSuspend(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_ACTIVE);
        $this->assertSame(1, $service->getLockVersion());

        // Worker 1 suspends service
        $suspended = $this->serviceService->suspendService(
            $service->getId(),
            'Non-payment overdue',
            expectedLockVersion: 1
        );

        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $suspended->getStatus());
        $this->assertSame(2, $suspended->getLockVersion());

        // Worker 2 attempts concurrent suspension with stale version 1
        $this->expectException(ServiceConcurrencyException::class);
        $this->serviceService->suspendService(
            $service->getId(),
            'Abuse report',
            expectedLockVersion: 1
        );
    }

    public function testOptimisticLockingOnUnsuspend(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_SUSPENDED);

        // Successful unsuspend with version 1
        $unsuspended = $this->serviceService->unsuspendService(
            $service->getId(),
            expectedLockVersion: 1
        );

        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $unsuspended->getStatus());
        $this->assertSame(2, $unsuspended->getLockVersion());

        // Stale attempt with version 1
        $this->expectException(ServiceConcurrencyException::class);
        $this->serviceService->unsuspendService(
            $service->getId(),
            expectedLockVersion: 1
        );
    }

    public function testOptimisticLockingOnTerminate(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_ACTIVE);

        // Terminate with expected version 1
        $terminated = $this->serviceService->terminateService(
            $service->getId(),
            'Customer requested termination',
            expectedLockVersion: 1
        );

        $this->assertSame(ServiceStateMachine::STATUS_TERMINATED, $terminated->getStatus());
        $this->assertSame(2, $terminated->getLockVersion());

        // Stale attempt throws concurrency exception before state machine check
        $this->expectException(ServiceConcurrencyException::class);
        $this->serviceService->terminateService(
            $service->getId(),
            'Duplicate termination attempt',
            expectedLockVersion: 1
        );
    }

    public function testOptimisticLockingOnCancel(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_ACTIVE);

        $cancelled = $this->serviceService->cancelService(
            $service->getId(),
            'Immediate cancellation',
            expectedLockVersion: 1
        );

        $this->assertSame(ServiceStateMachine::STATUS_CANCELLED, $cancelled->getStatus());
        $this->assertSame(2, $cancelled->getLockVersion());

        $this->expectException(ServiceConcurrencyException::class);
        $this->serviceService->cancelService(
            $service->getId(),
            'Stale cancellation',
            expectedLockVersion: 1
        );
    }

    public function testOptimisticLockingOnRenew(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_ACTIVE);

        $renewed = $this->serviceService->renewService(
            $service->getId(),
            expectedLockVersion: 1
        );

        $this->assertSame('2026-12-01', $renewed->getNextDueDate());
        $this->assertSame(2, $renewed->getLockVersion());

        $this->expectException(ServiceConcurrencyException::class);
        $this->serviceService->renewService(
            $service->getId(),
            expectedLockVersion: 1
        );
    }

    public function testUnspecifiedLockVersionAlwaysIncrements(): void
    {
        $service = $this->createSampleService();
        $this->assertSame(1, $service->getLockVersion());

        $s1 = $this->serviceService->updateService($service->getId(), ['notes' => 'Update 1']);
        $this->assertSame(2, $s1->getLockVersion());

        $s2 = $this->serviceService->updateService($service->getId(), ['notes' => 'Update 2']);
        $this->assertSame(3, $s2->getLockVersion());
    }

    public function testSimultaneousRaceConditionResolution(): void
    {
        $service = $this->createSampleService(ServiceStateMachine::STATUS_ACTIVE);

        // Two transactions read state at version 1
        $clientAVersion = $service->getLockVersion();
        $clientBVersion = $service->getLockVersion();

        $this->assertSame(1, $clientAVersion);
        $this->assertSame(1, $clientBVersion);

        // Transaction A commits first
        $resultA = $this->serviceService->updateService(
            $service->getId(),
            ['notes' => 'Updated by Client A'],
            expectedLockVersion: $clientAVersion
        );
        $this->assertSame(2, $resultA->getLockVersion());

        // Transaction B tries to commit with stale version -> rejected
        $caught = false;
        try {
            $this->serviceService->updateService(
                $service->getId(),
                ['notes' => 'Updated by Client B'],
                expectedLockVersion: $clientBVersion
            );
        } catch (ServiceConcurrencyException $e) {
            $caught = true;
            $this->assertSame(1, $e->getContext()['expected_version']);
            $this->assertSame(2, $e->getContext()['actual_version']);
        }

        $this->assertTrue($caught, 'Second transaction should fail with concurrency exception');

        // Verify final state matches Client A's update
        $final = $this->serviceService->findServiceById($service->getId());
        $this->assertSame('Updated by Client A', $final->getNotes());
        $this->assertSame(2, $final->getLockVersion());
    }
}
