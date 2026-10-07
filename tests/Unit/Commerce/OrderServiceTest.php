<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Entities\TaxClass;
use Coleza\Domain\Tax\Entities\TaxRate;
use Coleza\Domain\Tax\Entities\TaxZone;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    private Connection $db;
    private PricingService $pricingService;
    private TaxService $taxService;
    private OrderService $orderService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->pricingService = new PricingService($this->db);
        $this->pricingService->ensureTables();

        $this->taxService = new TaxService($this->db);
        $this->taxService->ensureTables();

        $this->orderService = new OrderService(
            $this->db,
            $this->pricingService,
            $this->taxService
        );
        $this->orderService->ensureTables();
    }

    public function testCreateOrderWithAuthoritativePricingAndTaxes(): void
    {
        $productId = 1;
        $userId = 10;
        $orgId = 5;

        // Pricing: 200.00 TRY/mo + 50.00 TRY setup
        $this->pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => $productId,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 20000,
            'setup_fee_minor' => 5000,
        ]);

        // Tax: 20% KDV
        $taxClass = $this->taxService->createTaxClass(['code' => 'standard', 'name' => 'Standard', 'is_default' => true]);
        $taxZone = $this->taxService->createTaxZone(['code' => 'TR', 'name' => 'Turkey', 'country_codes' => ['TR']]);
        $this->taxService->createTaxRate([
            'tax_class_id' => $taxClass->getId(),
            'tax_zone_id' => $taxZone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
        ]);

        $order = $this->orderService->createOrder(
            orderData: [
                'user_id' => $userId,
                'organization_id' => $orgId,
                'currency_code' => 'TRY',
                'country_code' => 'TR',
                'notes' => 'New web hosting purchase',
                'ip_address' => '127.0.0.1',
            ],
            itemsData: [
                [
                    'product_id' => $productId,
                    'product_name' => 'Web Hosting Plan 1',
                    'cycle' => PriceCycle::MONTHLY,
                    'quantity' => 1,
                ],
            ]
        );

        $this->assertNotNull($order->getId());
        $this->assertStringStartsWith('ORD-', $order->getOrderNumber());
        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $order->getStatus());
        $this->assertSame($userId, $order->getUserId());
        $this->assertSame($orgId, $order->getOrganizationId());

        // Subtotal = 200.00 (recurring) + 50.00 (setup) = 250.00 TRY (25000 minor)
        $this->assertSame(25000, $order->getSubtotalMinor());
        // Tax = 20% of 250.00 = 50.00 TRY (5000 minor)
        $this->assertSame(5000, $order->getTaxTotalMinor());
        // Total = 300.00 TRY (30000 minor)
        $this->assertSame(30000, $order->getTotalMinor());

        $this->assertCount(1, $order->getItems());
        $item = $order->getItems()[0];
        $this->assertSame('Web Hosting Plan 1', $item->getProductName());
        $this->assertSame(20000, $item->getUnitPriceMinor());
        $this->assertSame(5000, $item->getUnitSetupFeeMinor());
        $this->assertSame(25000, $item->getTotalMinor());
    }

    public function testOrderStateMachineTransitions(): void
    {
        $userId = 15;
        $order = $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'currency_code' => 'USD'],
            itemsData: [
                [
                    'product_id' => 99,
                    'unit_price_minor' => 1000,
                    'unit_setup_fee_minor' => 0,
                ],
            ]
        );

        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $order->getStatus());

        // 1. Pending payment -> Active (payment settled)
        $activeOrder = $this->orderService->transitionOrderStatus($order->getId(), OrderStateMachine::STATUS_ACTIVE);
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $activeOrder->getStatus());
        $this->assertTrue($activeOrder->isActive());

        // 2. Active -> Completed
        $completedOrder = $this->orderService->transitionOrderStatus($order->getId(), OrderStateMachine::STATUS_COMPLETED);
        $this->assertSame(OrderStateMachine::STATUS_COMPLETED, $completedOrder->getStatus());
        $this->assertTrue($completedOrder->isCompleted());

        // 3. Invalid transition from terminal Completed -> Pending Payment throws ValidationException
        $this->expectException(ValidationException::class);
        $this->orderService->transitionOrderStatus($order->getId(), OrderStateMachine::STATUS_PENDING_PAYMENT);
    }

    public function testEmptyItemsThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        $this->orderService->createOrder(
            orderData: ['user_id' => 1, 'currency_code' => 'TRY'],
            itemsData: []
        );
    }

    public function testListOrdersForUserAndOrganization(): void
    {
        $userId = 20;
        $orgId = 100;

        $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'organization_id' => $orgId, 'currency_code' => 'TRY'],
            itemsData: [['product_id' => 1, 'unit_price_minor' => 1000]]
        );

        $this->orderService->createOrder(
            orderData: ['user_id' => $userId, 'organization_id' => null, 'currency_code' => 'TRY'],
            itemsData: [['product_id' => 2, 'unit_price_minor' => 2000]]
        );

        // All orders for user
        $allUserOrders = $this->orderService->listOrdersForUser($userId);
        $this->assertCount(2, $allUserOrders);

        // Org-scoped orders for user
        $orgOrders = $this->orderService->listOrdersForUser($userId, $orgId);
        $this->assertCount(1, $orgOrders);
        $this->assertSame($orgId, $orgOrders[0]->getOrganizationId());
    }
}
