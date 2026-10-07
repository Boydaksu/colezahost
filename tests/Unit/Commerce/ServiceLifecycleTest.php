<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleTest extends TestCase
{
    private Connection $db;
    private PricingService $pricingService;
    private TaxService $taxService;
    private OrderService $orderService;
    private ServiceService $serviceService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->pricingService = new PricingService($this->db);
        $this->pricingService->ensureTables();

        $this->taxService = new TaxService($this->db);
        $this->taxService->ensureTables();

        $this->orderService = new OrderService($this->db, $this->pricingService, $this->taxService);
        $this->orderService->ensureTables();

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();
    }

    public function testServiceCreationAndNextDueDateCalculation(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 10,
            'product_id' => 1,
            'billing_cycle' => PriceCycle::MONTHLY,
            'recurring_amount_minor' => 1500,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-01',
            'domain' => 'example.com',
        ]);

        $this->assertNotNull($service->getId());
        $this->assertStringStartsWith('SRV-', $service->getServiceNumber());
        $this->assertSame(10, $service->getUserId());
        $this->assertSame(ServiceStateMachine::STATUS_PENDING, $service->getStatus());
        $this->assertTrue($service->isPending());
        $this->assertSame('2026-10-01', $service->getRegistrationDate());
        $this->assertSame('2026-11-01', $service->getNextDueDate());
        $this->assertSame('example.com', $service->getDomain());
    }

    public function testServiceActivationWithProvisioningParameters(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 20,
            'product_id' => 2,
            'billing_cycle' => PriceCycle::ANNUALLY,
            'recurring_amount_minor' => 12000,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-01',
        ]);

        $this->assertTrue($service->isPending());

        $activeService = $this->serviceService->activateService($service->getId(), [
            'domain' => 'mysite.org',
            'username' => 'mysiteuser',
            'password_encrypted' => 'enc:secretPass123',
            'server_name' => 'cpanel-eu-01',
            'ip_address' => '192.168.10.50',
        ]);

        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $activeService->getStatus());
        $this->assertTrue($activeService->isActive());
        $this->assertSame('mysite.org', $activeService->getDomain());
        $this->assertSame('mysiteuser', $activeService->getUsername());
        $this->assertSame('cpanel-eu-01', $activeService->getServerName());
        $this->assertSame('192.168.10.50', $activeService->getIpAddress());
    }

    public function testServiceSuspensionAndUnsuspension(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 30,
            'product_id' => 3,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        // Suspend
        $suspended = $this->serviceService->suspendService($service->getId(), 'Overdue payment by 7 days');
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $suspended->getStatus());
        $this->assertTrue($suspended->isSuspended());
        $this->assertSame('Overdue payment by 7 days', $suspended->getSuspensionReason());

        // Unsuspend
        $unsuspended = $this->serviceService->unsuspendService($service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $unsuspended->getStatus());
        $this->assertTrue($unsuspended->isActive());
        $this->assertNull($unsuspended->getSuspensionReason());
    }

    public function testServiceTerminationIsTerminal(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 40,
            'product_id' => 4,
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        $terminated = $this->serviceService->terminateService($service->getId(), 'Customer requested deletion');
        $this->assertSame(ServiceStateMachine::STATUS_TERMINATED, $terminated->getStatus());
        $this->assertTrue($terminated->isTerminated());
        $this->assertNotNull($terminated->getTerminationDate());

        // Attempting to activate a terminated service throws ValidationException
        $this->expectException(ValidationException::class);
        $this->serviceService->activateService($service->getId());
    }

    public function testServiceRenewalAdvancesDueDateAndReactivatesSuspended(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 50,
            'product_id' => 5,
            'billing_cycle' => PriceCycle::MONTHLY,
            'registration_date' => '2026-10-01',
            'next_due_date' => '2026-11-01',
            'status' => ServiceStateMachine::STATUS_ACTIVE,
        ]);

        // Suspend due to overdue
        $this->serviceService->suspendService($service->getId(), 'Overdue');

        // Renew service
        $renewed = $this->serviceService->renewService($service->getId());
        $this->assertSame('2026-12-01', $renewed->getNextDueDate());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $renewed->getStatus());
        $this->assertNull($renewed->getSuspensionReason());
    }

    public function testCreateServicesFromOrder(): void
    {
        $userId = 60;
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [
                [
                    'product_id' => 10,
                    'cycle' => PriceCycle::MONTHLY,
                    'unit_price_minor' => 2000,
                    'domain' => 'firstdomain.com',
                ],
                [
                    'product_id' => 20,
                    'cycle' => PriceCycle::ANNUALLY,
                    'unit_price_minor' => 15000,
                    'domain' => 'seconddomain.com',
                ],
            ]
        );

        $services = $this->serviceService->createServicesFromOrder($order);
        $this->assertCount(2, $services);

        $this->assertSame(10, $services[0]->getProductId());
        $this->assertSame('firstdomain.com', $services[0]->getDomain());
        $this->assertSame(PriceCycle::MONTHLY, $services[0]->getBillingCycle());
        $this->assertSame($order->getId(), $services[0]->getOrderId());

        $this->assertSame(20, $services[1]->getProductId());
        $this->assertSame('seconddomain.com', $services[1]->getDomain());
        $this->assertSame(PriceCycle::ANNUALLY, $services[1]->getBillingCycle());

        $userServices = $this->serviceService->listServicesForUser($userId);
        $this->assertCount(2, $userServices);
    }
}
