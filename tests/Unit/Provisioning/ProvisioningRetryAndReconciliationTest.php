<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorCategory;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Reconciliation\ReconciliationStatus;
use Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService;
use Coleza\Domain\Provisioning\Retry\ProvisioningRetryPolicy;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Services\ProvisioningRetryRunner;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProvisioningRetryAndReconciliationTest extends TestCase
{
    private Connection $db;
    private ServiceService $serviceService;
    private ServerService $serverService;
    private CapacityReservationService $reservationService;
    private PlacementEngine $placementEngine;
    private ProvisioningOperationService $operationService;
    private CpanelMemoryTransport $transport;
    private CpanelProvider $cpanelProvider;
    private ProviderRegistry $providerRegistry;
    private HostingProvisioningWorkflow $workflow;
    private UncertainResponseReconciliationService $reconciliationService;
    private ProvisioningRetryPolicy $retryPolicy;
    private ProvisioningRetryRunner $retryRunner;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        $this->serverService = new ServerService($this->db);
        $this->serverService->ensureTables();

        $this->reservationService = new CapacityReservationService($this->db, $this->serverService);
        $this->reservationService->ensureTables();

        $this->placementEngine = new PlacementEngine($this->serverService);

        $this->operationService = new ProvisioningOperationService($this->db);
        $this->operationService->ensureTables();

        $this->transport = new CpanelMemoryTransport();
        $this->cpanelProvider = new CpanelProvider($this->transport);
        $this->providerRegistry = new ProviderRegistry([$this->cpanelProvider]);

        $this->workflow = new HostingProvisioningWorkflow(
            serviceService: $this->serviceService,
            serverService: $this->serverService,
            placementEngine: $this->placementEngine,
            reservationService: $this->reservationService,
            providerRegistry: $this->providerRegistry,
            operationService: $this->operationService
        );

        $this->reconciliationService = new UncertainResponseReconciliationService(
            serverService: $this->serverService,
            providerRegistry: $this->providerRegistry,
            serviceService: $this->serviceService,
            operationService: $this->operationService
        );

        $this->retryPolicy = new ProvisioningRetryPolicy(
            baseDelaySeconds: 15,
            multiplier: 2.0,
            maxDelaySeconds: 1000,
            jitterFactor: 0.0, // deterministic for math assertions
            maxAttempts: 4
        );

        $this->retryRunner = new ProvisioningRetryRunner(
            operationService: $this->operationService,
            reconciliationService: $this->reconciliationService,
            provisioningWorkflow: $this->workflow,
            retryPolicy: $this->retryPolicy
        );
    }

    public function testExponentialBackoffCalculation(): void
    {
        $this->assertSame(15, $this->retryPolicy->calculateDelaySeconds(1, null, false));
        $this->assertSame(30, $this->retryPolicy->calculateDelaySeconds(2, null, false));
        $this->assertSame(60, $this->retryPolicy->calculateDelaySeconds(3, null, false));
        $this->assertSame(120, $this->retryPolicy->calculateDelaySeconds(4, null, false));

        // Rate limit category uses higher base delay
        $this->assertSame(60, $this->retryPolicy->calculateDelaySeconds(1, ProvisioningErrorCategory::RATE_LIMIT, false));
        $this->assertSame(120, $this->retryPolicy->calculateDelaySeconds(2, ProvisioningErrorCategory::RATE_LIMIT, false));

        // Test with jitter enabled produces values >= base delay
        $jittered = $this->retryPolicy->calculateDelaySeconds(2, null, true);
        $this->assertGreaterThanOrEqual(30, $jittered);
    }

    public function testRetryabilityClassification(): void
    {
        $transient = ProvisioningErrorClassification::transientNetwork('Timeout', 'TIMEOUT');
        $this->assertTrue($this->retryPolicy->isRetryable($transient, 1));
        $this->assertTrue($this->retryPolicy->isRetryable($transient, 3));
        $this->assertFalse($this->retryPolicy->isRetryable($transient, 4)); // Max attempts reached

        $authFail = ProvisioningErrorClassification::authentication('Invalid token', 'AUTH_FAIL');
        $this->assertFalse($this->retryPolicy->isRetryable($authFail, 1)); // Non-retryable category

        $conflict = ProvisioningErrorClassification::conflict('Account exists', 'CONFLICT');
        $this->assertFalse($this->retryPolicy->isRetryable($conflict, 1));
    }

    public function testUncertainResponseReconcilesGhostAccountAsSuccess(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'cPanel Recon 01',
            'hostname' => 'rec01.colezahost.com',
            'ip_address' => '10.0.1.20',
            'provider_slug' => 'cpanel',
            'max_accounts' => 100,
            'used_accounts' => 5,
            'disk_capacity_mb' => 500000,
            'bandwidth_capacity_mb' => 2000000,
            'auth_type' => 'api_token',
            'auth_secret' => 'SECRET_TOKEN_XYZ',
            'status' => 'active',
        ]);

        $service = $this->serviceService->createService([
            'user_id' => 10,
            'product_id' => 100,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 2000,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'ghostsite.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Operation in retrying state after uncertain response / timeout
        $op = $this->operationService->queueOperation(
            serviceId: (int)$service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: [
                'username' => 'ghostusr',
                'domain' => 'ghostsite.com',
                'package' => 'starter',
            ],
            serverId: (int)$server->getId()
        );

        // Remote WHM returns account summary indicating the account WAS actually created
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'ghostusr',
                        'domain' => 'ghostsite.com',
                        'plan' => 'starter',
                        'ip' => '10.0.1.25',
                    ],
                ],
            ],
        ]);

        $reconResult = $this->reconciliationService->reconcile($op);

        $this->assertTrue($reconResult->isExists());
        $this->assertTrue($reconResult->isAccountFound());
        $this->assertSame(ReconciliationStatus::RECONCILED_EXISTS, $reconResult->getStatus());

        // Verify Service transitioned to active automatically
        $svc = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertNotNull($svc);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $svc->getStatus());
        $this->assertSame('10.0.1.25', $svc->getIpAddress());

        // Verify Placement transitioned to placed
        $placement = $this->serviceService->getPlacementForService((int)$service->getId());
        $this->assertNotNull($placement);
        $this->assertSame(ServicePlacement::STATUS_PLACED, $placement->getStatus());

        // Verify Operation marked completed
        $updatedOp = $this->operationService->findOperationByUuid($op->getOperationUuid());
        $this->assertNotNull($updatedOp);
        $this->assertSame('completed', $updatedOp->getStatus());
    }

    public function testUncertainResponseReconciliationPermitsSafeRetryWhenAccountNotFound(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'cPanel Recon 01',
            'hostname' => 'rec01.colezahost.com',
            'ip_address' => '10.0.1.20',
            'provider_slug' => 'cpanel',
            'auth_type' => 'api_token',
            'auth_secret' => 'SECRET_TOKEN_XYZ',
            'status' => 'active',
        ]);

        $service = $this->serviceService->createService([
            'user_id' => 10,
            'product_id' => 100,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 2000,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'cleanretry.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $op = $this->operationService->queueOperation(
            serviceId: (int)$service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: [
                'username' => 'retryusr',
                'domain' => 'cleanretry.com',
                'package' => 'starter',
            ],
            serverId: (int)$server->getId()
        );

        // Remote WHM confirms account does NOT exist
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 0, 'reason' => 'User retryusr does not exist'],
        ]);

        $reconResult = $this->reconciliationService->reconcile($op);

        $this->assertTrue($reconResult->isNotFound());
        $this->assertFalse($reconResult->isAccountFound());
        $this->assertSame(ReconciliationStatus::RECONCILED_NOT_FOUND, $reconResult->getStatus());

        // Service remains in pending status
        $svc = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_PENDING, $svc->getStatus());
    }

    public function testUncertainResponseReconciliationHandlesUnreachableRemoteNode(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'cPanel Recon 01',
            'hostname' => 'rec01.colezahost.com',
            'ip_address' => '10.0.1.20',
            'provider_slug' => 'cpanel',
            'auth_type' => 'api_token',
            'auth_secret' => 'SECRET_TOKEN_XYZ',
            'status' => 'active',
        ]);

        $service = $this->serviceService->createService([
            'user_id' => 10,
            'product_id' => 100,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 2000,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'unreachable.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $op = $this->operationService->queueOperation(
            serviceId: (int)$service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: [
                'username' => 'unreachusr',
                'domain' => 'unreachable.com',
                'package' => 'starter',
            ],
            serverId: (int)$server->getId()
        );

        // Remote WHM returns 503 gateway error on probe
        $this->transport->stageResponse('accountsummary', 503, 'Gateway Timeout');

        $reconResult = $this->reconciliationService->reconcile($op);

        $this->assertTrue($reconResult->isUnreachable());
        $this->assertSame(ReconciliationStatus::RECONCILED_UNREACHABLE, $reconResult->getStatus());
    }

    public function testRetryRunnerProcessesDueRetriesAndReconciles(): void
    {
        $server = $this->serverService->createServer([
            'name' => 'cPanel Node 01',
            'hostname' => 'node01.colezahost.com',
            'ip_address' => '192.168.1.100',
            'provider_slug' => 'cpanel',
            'max_accounts' => 100,
            'used_accounts' => 5,
            'disk_capacity_mb' => 500000,
            'bandwidth_capacity_mb' => 2000000,
            'auth_type' => 'api_token',
            'auth_secret' => 'SECRET_TOKEN_XYZ',
            'status' => 'active',
        ]);

        $service = $this->serviceService->createService([
            'user_id' => 5,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'dueclient.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $op = $this->operationService->queueOperation(
            serviceId: (int)$service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: [
                'username' => 'dueusr',
                'domain' => 'dueclient.com',
                'package' => 'starter',
                'disk_limit_mb' => 2048,
                'bandwidth_limit_mb' => 20480,
            ],
            serverId: (int)$server->getId()
        );

        // Transition operation to retrying status with due time in the past
        $pastTime = date('Y-m-d H:i:s', time() - 300);
        $this->db->statement(
            'UPDATE provisioning_operations SET status = ?, next_attempt_at = ? WHERE id = ?',
            [ProvisioningOperation::STATUS_RETRYING, $pastTime, $op->getId()]
        );

        // Account exists remotely -> should reconcile to success
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'dueusr',
                        'domain' => 'dueclient.com',
                        'plan' => 'starter',
                        'ip' => '192.168.1.110',
                    ],
                ],
            ],
        ]);

        $results = $this->retryRunner->processDueRetries();

        $this->assertCount(1, $results);
        $this->assertSame('reconciled_exists', $results[0]['outcome']);
        $this->assertSame($op->getOperationUuid(), $results[0]['operation_uuid']);

        // Confirm service active
        $updatedService = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $updatedService?->getStatus());
    }
}
