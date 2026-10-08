<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Coleza\Domain\Commerce\Services\Exceptions\ServiceConcurrencyException;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorCategory;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService;
use Coleza\Domain\Provisioning\Retry\ProvisioningRetryPolicy;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Services\ProvisioningRetryRunner;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningRequest;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningStep;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class NoDuplicateRemoteAccountFailureTest extends TestCase
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
    private Server $server;

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

        $this->retryPolicy = new ProvisioningRetryPolicy();

        $this->retryRunner = new ProvisioningRetryRunner(
            operationService: $this->operationService,
            reconciliationService: $this->reconciliationService,
            provisioningWorkflow: $this->workflow,
            retryPolicy: $this->retryPolicy
        );

        // Create standard active cPanel host
        $this->server = $this->serverService->createServer([
            'name' => 'cPanel Production 01',
            'hostname' => 'cp01.colezahost.com',
            'ip_address' => '192.168.10.50',
            'provider_slug' => 'cpanel',
            'max_accounts' => 500,
            'used_accounts' => 10,
            'disk_capacity_mb' => 1000000,
            'bandwidth_capacity_mb' => 5000000,
            'auth_type' => 'api_token',
            'auth_secret' => 'SECRET_TOKEN_XYZ',
            'status' => 'active',
        ]);

        // Stage standard WHM packages
        $this->transport->stageResponse('listpkgs', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'pkg' => [
                    ['name' => 'starter', 'QUOTA' => '5120', 'BWLIMIT' => '51200'],
                    ['name' => 'pro', 'QUOTA' => '20480', 'BWLIMIT' => '204800'],
                ],
            ],
        ]);
    }

    public function testDuplicateDomainFailurePreventsAccountCreationAndReleasesReservation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 1,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'alreadyexisting.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Stage WHM createacct response for domain conflict
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'Domain alreadyexists.com already exists on this server.',
            ],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            domain: 'alreadyexisting.com',
            username: 'dupdomain'
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::REMOTE, $result->getCurrentStep());
        $this->assertSame(ProvisioningErrorCategory::CONFLICT, $result->getClassification()?->getCategory());

        // Capacity reservation must be released (not leaked)
        $token = $result->getReservationToken();
        $this->assertNotNull($token);
        $res = $this->reservationService->findReservationByToken($token);
        $this->assertNotNull($res);
        $this->assertTrue($res->isReleased());

        // Placement must be evicted
        $placement = $this->serviceService->getPlacementForService((int)$service->getId());
        $this->assertTrue($placement === null || $placement->isEvicted());

        // Service must NOT be activated
        $svc = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_PENDING, $svc->getStatus());

        // Operation status must be failed, NOT retrying
        $op = $this->operationService->findOperationByUuid((string)$result->getOperationUuid());
        $this->assertNotNull($op);
        $this->assertSame(ProvisioningOperation::STATUS_FAILED, $op->getStatus());
    }

    public function testDuplicateUsernameFailurePreventsAccountCreation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 2,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'newdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Stage WHM createacct response for user conflict
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'A user with the name "clientusr" already exists.',
            ],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            domain: 'newdomain.com',
            username: 'clientusr'
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::REMOTE, $result->getCurrentStep());
        $this->assertSame(ProvisioningErrorCategory::CONFLICT, $result->getClassification()?->getCategory());

        // Capacity reservation must be released
        $res = $this->reservationService->findReservationByToken((string)$result->getReservationToken());
        $this->assertTrue($res->isReleased());

        // Service remains pending
        $svc = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_PENDING, $svc->getStatus());

        // Operation marked failed (conflict is non-retryable)
        $op = $this->operationService->findOperationByUuid((string)$result->getOperationUuid());
        $this->assertSame(ProvisioningOperation::STATUS_FAILED, $op->getStatus());
    }

    public function testSubsequentProvisioningAttemptOnActiveServiceIsIdempotent(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 3,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'idempotent.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Stage WHM responses for successful first creation
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['ip' => '192.168.10.55', 'package' => 'starter'],
        ]);
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['acct' => [['user' => 'idempotent', 'ip' => '192.168.10.55', 'plan' => 'starter']]],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            domain: 'idempotent.com',
            username: 'idempotent'
        );

        // Run 1: first creation succeeds
        $result1 = $this->workflow->execute($request);
        $this->assertTrue($result1->isSuccess());

        // Count how many times createacct was dispatched
        $history1 = $this->transport->getRecordedRequests();
        $createCalls1 = count(array_filter($history1, fn($r) => str_contains($r['url'], 'createacct')));
        $this->assertSame(1, $createCalls1);

        // Run 2: second execution on the now-active service must be idempotent
        $result2 = $this->workflow->execute($request);
        $this->assertTrue($result2->isSuccess());
        $this->assertTrue($result2->getData()['idempotent']);

        // WHM createacct must NOT have been called a second time!
        $history2 = $this->transport->getRecordedRequests();
        $createCalls2 = count(array_filter($history2, fn($r) => str_contains($r['url'], 'createacct')));
        $this->assertSame(1, $createCalls2);
    }

    public function testConcurrentRetryDoesNotDuplicateWhenReconciled(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 4,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'reconcheck.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $op = $this->operationService->queueOperation(
            serviceId: (int)$service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: [
                'username' => 'reconusr',
                'domain' => 'reconcheck.com',
                'package' => 'starter',
            ],
            serverId: (int)$this->server->getId()
        );

        // Put in retrying status
        $this->db->statement(
            'UPDATE provisioning_operations SET status = ?, next_attempt_at = ? WHERE id = ?',
            [ProvisioningOperation::STATUS_RETRYING, date('Y-m-d H:i:s', time() - 100), $op->getId()]
        );

        // Account is found on remote node!
        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'reconusr',
                        'domain' => 'reconcheck.com',
                        'plan' => 'starter',
                        'ip' => '192.168.10.88',
                    ],
                ],
            ],
        ]);

        // Process due retries
        $results = $this->retryRunner->processDueRetries();

        $this->assertCount(1, $results);
        $this->assertSame('reconciled_exists', $results[0]['outcome']);

        // createacct was NEVER called during retry!
        $history = $this->transport->getRecordedRequests();
        $createCalls = array_filter($history, fn($r) => str_contains($r['url'], 'createacct'));
        $this->assertEmpty($createCalls);
    }

    public function testActiveOperationQueryReturnsCurrentInFlightOperation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 5,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'activecheck.com',
        ]);

        $op = $this->operationService->queueOperation(
            serviceId: (int)$service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: ['domain' => 'activecheck.com'],
            serverId: (int)$this->server->getId()
        );

        $activeOp = $this->operationService->findActiveOperationForService((int)$service->getId(), 'create_account');
        $this->assertNotNull($activeOp);
        $this->assertSame($op->getOperationUuid(), $activeOp->getOperationUuid());

        // Mark completed -> should no longer be returned as active
        $this->db->statement(
            'UPDATE provisioning_operations SET status = ? WHERE id = ?',
            [ProvisioningOperation::STATUS_COMPLETED, $op->getId()]
        );

        $noLongerActive = $this->operationService->findActiveOperationForService((int)$service->getId(), 'create_account');
        $this->assertNull($noLongerActive);
    }
}
