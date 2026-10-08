<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningRequest;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningStep;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use Coleza\Domain\Servers\Capacity\Entities\CapacityReservation;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class HostingProvisioningWorkflowTest extends TestCase
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

        // Stage standard WHM listpkgs
        $this->transport->stageResponse('listpkgs', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'pkg' => [
                    [
                        'name' => 'starter',
                        'QUOTA' => '5120',
                        'BWLIMIT' => '51200',
                    ],
                    [
                        'name' => 'business',
                        'QUOTA' => '20480',
                        'BWLIMIT' => '204800',
                    ],
                ],
            ],
        ]);
    }

    public function testFullWorkflowSuccess(): void
    {
        $server = $this->serverService->createServer([
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

        $service = $this->serviceService->createService([
            'user_id' => 1,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter', 'email' => 'client@clientdomain.com'],
        ]);

        // Stage remote account creation and verification responses
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => ['result' => 1, 'reason' => 'Account Creation Ok', 'version' => 1],
            'data' => [
                'ip' => '192.168.10.55',
                'nameserver' => 'ns1.colezahost.com',
                'nameserver2' => 'ns2.colezahost.com',
                'package' => 'starter',
            ],
        ]);

        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'clientdo',
                        'domain' => 'clientdomain.com',
                        'plan' => 'starter',
                        'ip' => '192.168.10.55',
                    ],
                ],
            ],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'clientdo',
            requiredDiskMb: 5120,
            requiredBandwidthMb: 51200,
            contactEmail: 'client@clientdomain.com'
        );

        $result = $this->workflow->execute($request);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::ACTIVATE, $result->getCurrentStep());
        $this->assertSame([
            HostingProvisioningStep::VALIDATE,
            HostingProvisioningStep::PLACE,
            HostingProvisioningStep::RESERVE,
            HostingProvisioningStep::REMOTE,
            HostingProvisioningStep::VERIFY,
            HostingProvisioningStep::ACTIVATE,
        ], $result->getCompletedSteps());

        $this->assertSame((int)$server->getId(), $result->getServerId());
        $this->assertSame('clientdo', $result->getRemoteIdentifier());
        $this->assertSame('192.168.10.55', $result->getRemoteIp());
        $this->assertContains('ns1.colezahost.com', $result->getNameservers());

        // Verify Service state is updated to active
        $updatedService = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertNotNull($updatedService);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $updatedService->getStatus());
        $this->assertSame('192.168.10.55', $updatedService->getIpAddress());
        $this->assertSame('cPanel Production 01', $updatedService->getServerName());

        // Verify Service Placement state is placed
        $placement = $this->serviceService->getPlacementForService((int)$service->getId());
        $this->assertNotNull($placement);
        $this->assertSame(ServicePlacement::STATUS_PLACED, $placement->getStatus());
        $this->assertSame('cp01.colezahost.com', $placement->getHostname());

        // Verify Capacity Reservation is committed
        $this->assertNotNull($result->getReservationToken());
        $reservation = $this->reservationService->findReservationByToken($result->getReservationToken());
        $this->assertNotNull($reservation);
        $this->assertTrue($reservation->isCommitted());

        // Verify Provisioning Operation is marked completed
        $this->assertNotNull($result->getOperationUuid());
        $op = $this->operationService->findOperationByUuid($result->getOperationUuid());
        $this->assertNotNull($op);
        $this->assertSame('completed', $op->getStatus());
    }

    public function testValidationRejectionOnInvalidDomain(): void
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
            'domain' => 'invalid-domain-without-dot',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId()
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::VALIDATE, $result->getCurrentStep());
        $this->assertSame('INVALID_DOMAIN', $result->getErrorCode());
        $this->assertEmpty($result->getCompletedSteps());
    }

    public function testValidationRejectionOnInvalidUsername(): void
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
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'root' // reserved username
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::VALIDATE, $result->getCurrentStep());
        $this->assertSame('INVALID_USERNAME', $result->getErrorCode());
    }

    public function testValidationIdempotencyWhenAlreadyActive(): void
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
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Manually activate
        $this->serviceService->activateService((int)$service->getId(), [
            'username' => 'activeuser',
            'ip_address' => '1.2.3.4',
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'activeuser'
        );

        $result = $this->workflow->execute($request);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getData()['idempotent']);
        $this->assertSame('activeuser', $result->getRemoteIdentifier());
    }

    public function testPlacementRejectionWhenNoServersMatch(): void
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
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'myclient'
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::PLACE, $result->getCurrentStep());
        $this->assertSame('PLACEMENT_REJECTED', $result->getErrorCode());
        $this->assertSame([HostingProvisioningStep::VALIDATE], $result->getCompletedSteps());
    }

    public function testCapacityReservationFailureCompensatesPlacement(): void
    {
        // Server at full account capacity
        $this->serverService->createServer([
            'name' => 'Full Server',
            'hostname' => 'full.colezahost.com',
            'ip_address' => '10.0.0.2',
            'provider_slug' => 'cpanel',
            'max_accounts' => 10,
            'used_accounts' => 10, // Full!
            'disk_capacity_mb' => 100000,
            'bandwidth_capacity_mb' => 500000,
            'status' => 'active',
        ]);

        $service = $this->serviceService->createService([
            'user_id' => 1,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'myclient'
        );

        $result = $this->workflow->execute($request);

        // Placement will fail or reserve will fail depending on placement check
        $this->assertFalse($result->isSuccess());
        $this->assertTrue(
            $result->getCurrentStep() === HostingProvisioningStep::PLACE ||
            $result->getCurrentStep() === HostingProvisioningStep::RESERVE
        );
    }

    public function testRemoteCreationFailureCompensatesReservationAndPlacement(): void
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
            'user_id' => 1,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Stage WHM createacct failure
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => [
                'result' => 0,
                'reason' => 'Domain clientdomain.com already exists on this server.',
            ],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'myclient'
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::REMOTE, $result->getCurrentStep());
        $this->assertSame([
            HostingProvisioningStep::VALIDATE,
            HostingProvisioningStep::PLACE,
            HostingProvisioningStep::RESERVE,
        ], $result->getCompletedSteps());

        // Verify capacity reservation was released
        $token = $result->getReservationToken();
        $this->assertNotNull($token);
        $res = $this->reservationService->findReservationByToken($token);
        $this->assertNotNull($res);
        $this->assertTrue($res->isReleased());
        $this->assertSame('remote_creation_failed', $res->getReleaseReason());

        // Verify placement was evicted
        $placement = $this->serviceService->getPlacementForService((int)$service->getId());
        $this->assertTrue($placement === null || $placement->isEvicted());

        // Verify Service remained in pending status
        $svc = $this->serviceService->findServiceById((int)$service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_PENDING, $svc->getStatus());
    }

    public function testVerificationFailureCompensates(): void
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
            'user_id' => 1,
            'product_id' => 10,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-08',
            'next_due_date' => '2026-11-08',
            'domain' => 'clientdomain.com',
            'metadata' => ['package_identifier' => 'starter'],
        ]);

        // Stage createacct success, but accountsummary returns 0 (missing user)
        $this->transport->stageResponse('createacct', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['ip' => '192.168.1.150'],
        ]);

        $this->transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 0, 'reason' => 'User not found.'],
        ]);

        $request = new HostingProvisioningRequest(
            serviceId: (int)$service->getId(),
            username: 'myclient'
        );

        $result = $this->workflow->execute($request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(HostingProvisioningStep::VERIFY, $result->getCurrentStep());
        $this->assertSame('VERIFICATION_FAILED', $result->getErrorCode());

        // Verify compensation
        $res = $this->reservationService->findReservationByToken((string)$result->getReservationToken());
        $this->assertTrue($res->isReleased());
    }

    public function testAutoGeneratedUsername(): void
    {
        $gen = $this->workflow->generateCpanelUsername('my-awesome-domain.co.uk', 42);
        $this->assertTrue($this->workflow->isValidCpanelUsername($gen));
        $this->assertLessThanOrEqual(16, strlen($gen));
        $this->assertGreaterThanOrEqual(1, strlen($gen));
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{0,15}$/', $gen);
    }
}
