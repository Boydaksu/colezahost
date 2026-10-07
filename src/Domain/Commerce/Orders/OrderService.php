<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Orders;

use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class OrderService
{
    private string $ordersTable = 'orders';
    private string $orderItemsTable = 'order_items';

    public function __construct(
        private Connection $db,
        private ?PricingService $pricingService = null,
        private ?TaxService $taxService = null,
        private ?CatalogService $catalogService = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Orders table
        $sqlOrders = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                order_number VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                organization_id INT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "pending_payment",
                currency_code VARCHAR(3) NOT NULL,
                subtotal_minor INT NOT NULL DEFAULT 0,
                tax_total_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->ordersTable,
            $autoInc
        );
        $this->db->statement($sqlOrders);

        // Order Items table
        $sqlItems = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                order_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(150) NOT NULL,
                cycle VARCHAR(50) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price_minor INT NOT NULL DEFAULT 0,
                unit_setup_fee_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL DEFAULT 0,
                option_sub_ids_json TEXT NULL,
                addon_ids_json TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->orderItemsTable,
            $autoInc
        );
        $this->db->statement($sqlItems);
    }

    /**
     * Create a new order with items, computing quotes and taxes.
     *
     * @param array<string, mixed> $orderData
     * @param array<array<string, mixed>> $itemsData
     */
    public function createOrder(array $orderData, array $itemsData): Order
    {
        $userId = (int)($orderData['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['Valid user_id required.']], 'Invalid user.');
        }

        $organizationId = isset($orderData['organization_id']) ? (int)$orderData['organization_id'] : null;
        $currencyCode = strtoupper(trim((string)($orderData['currency_code'] ?? 'TRY')));
        $status = (string)($orderData['status'] ?? OrderStateMachine::STATUS_PENDING_PAYMENT);

        if (empty($itemsData)) {
            throw new ValidationException(['items' => ['At least one order item is required.']], 'Order cannot be empty.');
        }

        $orderNumber = $this->generateOrderNumber();
        $notes = isset($orderData['notes']) ? (string)$orderData['notes'] : null;
        $ipAddress = isset($orderData['ip_address']) ? (string)$orderData['ip_address'] : null;
        $countryCode = (string)($orderData['country_code'] ?? 'TR');

        $computedItems = [];
        $orderSubtotal = 0;

        foreach ($itemsData as $idx => $itemData) {
            $productId = (int)($itemData['product_id'] ?? 0);
            $productName = trim((string)($itemData['product_name'] ?? "Product #{$productId}"));
            $cycle = (string)($itemData['cycle'] ?? 'monthly');
            $quantity = max(1, (int)($itemData['quantity'] ?? 1));
            $optionSubIds = isset($itemData['option_sub_ids']) && is_array($itemData['option_sub_ids']) ? $itemData['option_sub_ids'] : [];
            $addonIds = isset($itemData['addon_ids']) && is_array($itemData['addon_ids']) ? $itemData['addon_ids'] : [];
            $metadata = isset($itemData['metadata']) && is_array($itemData['metadata']) ? $itemData['metadata'] : [];

            if (isset($itemData['unit_price_minor'])) {
                // Direct custom/override price passed (e.g. manual admin order)
                $unitPrice = (int)$itemData['unit_price_minor'];
                $unitSetup = (int)($itemData['unit_setup_fee_minor'] ?? 0);
                $itemTotal = ($unitPrice + $unitSetup) * $quantity;
            } elseif ($this->pricingService !== null) {
                // Authoritative quote from pricing engine
                $quote = $this->pricingService->calculateQuote(
                    productId: $productId,
                    currencyCode: $currencyCode,
                    cycle: $cycle,
                    selectedOptionSubIds: $optionSubIds,
                    selectedAddonIds: $addonIds,
                    customerId: $organizationId ?? $userId
                );

                $unitPrice = $quote->getRecurringTotalMinor();
                $unitSetup = $quote->getSetupFeeMinor();
                $itemTotal = ($unitPrice + $unitSetup) * $quantity;
            } else {
                $unitPrice = 0;
                $unitSetup = 0;
                $itemTotal = 0;
            }

            $orderSubtotal += $itemTotal;
            $computedItems[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'cycle' => $cycle,
                'quantity' => $quantity,
                'unit_price_minor' => $unitPrice,
                'unit_setup_fee_minor' => $unitSetup,
                'total_minor' => $itemTotal,
                'option_sub_ids' => $optionSubIds,
                'addon_ids' => $addonIds,
                'metadata' => $metadata,
            ];
        }

        // Compute Taxes
        $orderTax = 0;
        if ($this->taxService !== null) {
            $taxClass = $this->taxService->getDefaultTaxClass();
            $taxResult = $this->taxService->calculateTax(
                amountMinor: $orderSubtotal,
                taxClassId: $taxClass->getId() ?? 1,
                countryCode: $countryCode,
                customerId: $organizationId ?? $userId
            );
            $orderTax = $taxResult->getTaxTotalMinor();
        }

        $orderTotal = $orderSubtotal + $orderTax;

        // Persist Order
        $sql = sprintf(
            'INSERT INTO %s (order_number, user_id, organization_id, status, currency_code, subtotal_minor, tax_total_minor, total_minor, notes, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->ordersTable
        );

        $this->db->statement($sql, [
            $orderNumber,
            $userId,
            $organizationId,
            $status,
            $currencyCode,
            $orderSubtotal,
            $orderTax,
            $orderTotal,
            $notes,
            $ipAddress,
        ]);

        $orderId = (int)$this->db->getPdo()->lastInsertId();

        // Persist Items
        $savedItems = [];
        foreach ($computedItems as $cItem) {
            $itemSql = sprintf(
                'INSERT INTO %s (order_id, product_id, product_name, cycle, quantity, unit_price_minor, unit_setup_fee_minor, total_minor, option_sub_ids_json, addon_ids_json, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->orderItemsTable
            );

            $this->db->statement($itemSql, [
                $orderId,
                $cItem['product_id'],
                $cItem['product_name'],
                $cItem['cycle'],
                $cItem['quantity'],
                $cItem['unit_price_minor'],
                $cItem['unit_setup_fee_minor'],
                $cItem['total_minor'],
                json_encode($cItem['option_sub_ids']),
                json_encode($cItem['addon_ids']),
                json_encode($cItem['metadata']),
            ]);

            $itemId = (int)$this->db->getPdo()->lastInsertId();
            $savedItems[] = new OrderItem(
                id: $itemId,
                orderId: $orderId,
                productId: $cItem['product_id'],
                productName: $cItem['product_name'],
                cycle: $cItem['cycle'],
                quantity: $cItem['quantity'],
                unitPriceMinor: $cItem['unit_price_minor'],
                unitSetupFeeMinor: $cItem['unit_setup_fee_minor'],
                totalMinor: $cItem['total_minor'],
                optionSubIds: $cItem['option_sub_ids'],
                addonIds: $cItem['addon_ids'],
                metadata: $cItem['metadata']
            );
        }

        return new Order(
            id: $orderId,
            orderNumber: $orderNumber,
            userId: $userId,
            organizationId: $organizationId,
            status: $status,
            currencyCode: $currencyCode,
            subtotalMinor: $orderSubtotal,
            taxTotalMinor: $orderTax,
            totalMinor: $orderTotal,
            notes: $notes,
            ipAddress: $ipAddress,
            items: $savedItems,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findOrderById(int $id): ?Order
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->ordersTable), [$id]);
        return $row ? $this->hydrateOrder($row) : null;
    }

    public function findOrderByNumber(string $orderNumber): ?Order
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE order_number = ?', $this->ordersTable), [$orderNumber]);
        return $row ? $this->hydrateOrder($row) : null;
    }

    /**
     * Transition order to a new state through the state machine.
     */
    public function transitionOrderStatus(int $orderId, string $newStatus): Order
    {
        $order = $this->findOrderById($orderId);
        if ($order === null) {
            throw new RuntimeException("Order {$orderId} not found.");
        }

        OrderStateMachine::validateTransition($order->getStatus(), $newStatus);

        $this->db->statement(
            sprintf('UPDATE %s SET status = ? WHERE id = ?', $this->ordersTable),
            [$newStatus, $orderId]
        );

        return $this->findOrderById($orderId);
    }

    /**
     * @return array<Order>
     */
    public function listOrdersForUser(int $userId, ?int $orgId = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE user_id = ?', $this->ordersTable);
        $params = [$userId];

        if ($orgId !== null) {
            $sql .= ' AND organization_id = ?';
            $params[] = $orgId;
        }

        $sql .= ' ORDER BY id DESC';
        $rows = $this->db->select($sql, $params);

        return array_map([$this, 'hydrateOrder'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateOrder(array $row): Order
    {
        $orderId = (int)$row['id'];
        $itemRows = $this->db->select(sprintf('SELECT * FROM %s WHERE order_id = ?', $this->orderItemsTable), [$orderId]);

        $items = [];
        foreach ($itemRows as $iRow) {
            $optSubs = !empty($iRow['option_sub_ids_json']) ? json_decode((string)$iRow['option_sub_ids_json'], true) : [];
            $addons = !empty($iRow['addon_ids_json']) ? json_decode((string)$iRow['addon_ids_json'], true) : [];
            $meta = !empty($iRow['metadata_json']) ? json_decode((string)$iRow['metadata_json'], true) : [];

            $items[] = new OrderItem(
                id: (int)$iRow['id'],
                orderId: (int)$iRow['order_id'],
                productId: (int)$iRow['product_id'],
                productName: (string)$iRow['product_name'],
                cycle: (string)$iRow['cycle'],
                quantity: (int)$iRow['quantity'],
                unitPriceMinor: (int)$iRow['unit_price_minor'],
                unitSetupFeeMinor: (int)$iRow['unit_setup_fee_minor'],
                totalMinor: (int)$iRow['total_minor'],
                optionSubIds: is_array($optSubs) ? array_map('intval', $optSubs) : [],
                addonIds: is_array($addons) ? array_map('intval', $addons) : [],
                metadata: is_array($meta) ? $meta : []
            );
        }

        return new Order(
            id: $orderId,
            orderNumber: (string)$row['order_number'],
            userId: (int)$row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            status: (string)$row['status'],
            currencyCode: (string)$row['currency_code'],
            subtotalMinor: (int)$row['subtotal_minor'],
            taxTotalMinor: (int)$row['tax_total_minor'],
            totalMinor: (int)$row['total_minor'],
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            ipAddress: $row['ip_address'] !== null ? (string)$row['ip_address'] : null,
            items: $items,
            createdAt: (string)$row['created_at']
        );
    }

    private function generateOrderNumber(): string
    {
        $prefix = date('Ymd');
        $random = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
        return "ORD-{$prefix}-{$random}";
    }
}
