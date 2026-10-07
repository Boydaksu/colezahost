<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Orders;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Foundation\Exceptions\AuthorizationException;
use Coleza\Foundation\Exceptions\ResourceNotFoundException;
use Coleza\Foundation\Exceptions\ValidationException;

final class OrderApplicationService
{
    public function __construct(
        private OrderService $orderService
    ) {
    }

    public function createOrder(CreateOrderCommand $command, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        // Permission check: regular user can only create order for themselves
        if (!$isAdmin && $authUserId !== null && $command->userId !== $authUserId) {
            throw new AuthorizationException('Cannot create order for another user.');
        }

        if (empty($command->items)) {
            throw new ValidationException(['items' => 'Order must contain at least one item.'], 'Empty order items');
        }

        $orderData = [
            'user_id' => $command->userId,
            'organization_id' => $command->organizationId,
            'currency_code' => $command->currencyCode,
            'metadata' => $command->metadata,
        ];

        return $this->orderService->createOrder($orderData, $command->items);
    }

    public function getOrder(int $orderId, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        $order = $this->orderService->findOrderById($orderId);
        if ($order === null) {
            throw new ResourceNotFoundException("Order {$orderId} not found.");
        }

        // IDOR protection: non-admin can only access own orders
        if (!$isAdmin && $authUserId !== null && $order->getUserId() !== $authUserId) {
            throw new AuthorizationException('Forbidden: You do not have access to this order.');
        }

        return $order;
    }

    /**
     * @return array<Order>
     */
    public function listUserOrders(int $userId, ?int $authUserId = null, bool $isAdmin = false): array
    {
        if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
            throw new AuthorizationException('Forbidden: Cannot list orders for another user.');
        }

        return $this->orderService->listOrdersForUser($userId);
    }

    public function cancelOrder(int $orderId, ?string $reason = null, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        $order = $this->getOrder($orderId, $authUserId, $isAdmin);

        return $this->orderService->transitionOrderStatus($order->getId(), OrderStateMachine::STATUS_CANCELLED);
    }
}
