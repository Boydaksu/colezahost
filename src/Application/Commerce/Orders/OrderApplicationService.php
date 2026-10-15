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
    private \Coleza\Application\Identity\CommandAuthorizer $authorization;

    public function __construct(
        private OrderService $orderService
    ) {
        $this->authorization = new \Coleza\Application\Identity\CommandAuthorizer($this->orderService->connection());
    }

    public function createOrder(CreateOrderCommand $command, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        return $this->authorization->run($authUserId, function () use ($command, $authUserId, $isAdmin): Order {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'orders.manage');
            $this->authorization->resource($authUserId, $command->userId, $command->organizationId, 'orders.manage', 'org.orders.manage');

            // Permission check: regular user can only create order for themselves

            if (empty($command->items)) {
                throw new ValidationException(['items' => 'Order must contain at least one item.'], 'Empty order items');
            }

            return (new CatalogOrderPricing($this->orderService->connection()))->create($command);
        }, 'command.createOrder');
    }

    public function createManualOrder(CreateOrderCommand $command,string $reason,int $actor): Order
    {
        return $this->authorization->run($actor,function()use($command,$reason,$actor):Order{
            $this->authorization->requireSystem($actor,'orders.price_override');
            if(trim($reason)===''||strlen($reason)>500){throw new ValidationException(['reason'=>['Manual pricing requires a reason.']]);}
            (new \Coleza\Domain\Identity\Audit\AuditLogger($this->orderService->connection()))->log($actor,'command.manual_order_pricing',payload:['reason'=>$reason]);
            return $this->orderService->createOrder(['user_id'=>$command->userId,'organization_id'=>$command->organizationId,'currency_code'=>$command->currencyCode],$command->items);
        },'command.manual_order_created');
    }

    public function getOrder(int $orderId, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        return $this->authorization->run($authUserId, function () use ($orderId, $authUserId, $isAdmin): Order {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'orders.view');

            $order = $this->orderService->findOrderById($orderId);
            if ($order === null) {
                throw new ResourceNotFoundException("Order {$orderId} not found.");
            }

            $this->authorization->resource($authUserId, $order->getUserId(), $order->getOrganizationId(), 'orders.view', 'org.orders.view');

            // IDOR protection: non-admin can only access own orders

            return $order;

        }, null);
    }

    /**
     * @return array<Order>
     */
    public function listUserOrders(int $userId, ?int $authUserId = null, bool $isAdmin = false): array
    {
        return $this->authorization->run($authUserId, function () use ($userId, $authUserId, $isAdmin): array {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'orders.view');

            if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
                throw new AuthorizationException('Forbidden: Cannot list orders for another user.');
            }

            $rows = $this->orderService->listOrdersForUser($userId);
            return array_values(array_filter($rows, function ($row) use ($authUserId): bool {
                try { $this->authorization->resource($authUserId, $row->getUserId(), $row->getOrganizationId(), 'orders.view', 'org.orders.view'); return true; }
                catch (AuthorizationException) { return false; }
            }));

        }, null);
    }

    public function cancelOrder(int $orderId, ?string $reason = null, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        return $this->authorization->run($authUserId, function () use ($orderId, $reason, $authUserId, $isAdmin): Order {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'orders.manage');

            $order = $this->getOrder($orderId, $authUserId, $isAdmin);

            $this->authorization->resource($authUserId, $order->getUserId(), $order->getOrganizationId(), 'orders.manage', 'org.orders.manage');
            return $this->orderService->transitionOrderStatus($order->getId(), OrderStateMachine::STATUS_CANCELLED);

        }, 'command.cancelOrder');
    }
}
