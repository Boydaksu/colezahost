<?php

declare(strict_types=1);

namespace Coleza\Api\Controllers\Commerce;

use Coleza\Api\Response\ApiResponse;
use Coleza\Application\Commerce\Orders\CreateOrderCommand;
use Coleza\Application\Commerce\Orders\OrderApplicationService;
use Coleza\Foundation\Exceptions\ValidationException;

final class OrderApiController
{
    public function __construct(
        private OrderApplicationService $orderAppService
    ) {
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function create(array $requestData, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $targetUserId = isset($requestData['user_id']) && $isAdmin
            ? (int) $requestData['user_id']
            : $userId;

        if ($targetUserId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id required.'], 'Authentication required');
        }

        $items = (array) ($requestData['items'] ?? []);
        $currencyCode = (string) ($requestData['currency_code'] ?? 'TRY');
        $orgId = isset($requestData['organization_id']) ? (int) $requestData['organization_id'] : null;
        $metadata = (array) ($requestData['metadata'] ?? []);

        $command = new CreateOrderCommand(
            userId: $targetUserId,
            items: $items,
            currencyCode: $currencyCode,
            organizationId: $orgId,
            metadata: $metadata
        );

        $order = $this->orderAppService->createOrder($command, $userId, $isAdmin);

        return ApiResponse::success([
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'status' => $order->getStatus(),
            'total_minor' => $order->getTotalMinor(),
            'currency_code' => $order->getCurrencyCode(),
        ], ['action' => 'order_created'], 201);
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function show(int $orderId, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $order = $this->orderAppService->getOrder($orderId, $userId, $isAdmin);

        return ApiResponse::success([
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'user_id' => $order->getUserId(),
            'status' => $order->getStatus(),
            'total_minor' => $order->getTotalMinor(),
            'currency_code' => $order->getCurrencyCode(),
            'items_count' => count($order->getItems()),
            'created_at' => $order->getCreatedAt(),
        ]);
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function list(array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $orders = $this->orderAppService->listUserOrders($userId, $userId, $isAdmin);

        $data = array_map(fn($o) => [
            'id' => $o->getId(),
            'order_number' => $o->getOrderNumber(),
            'status' => $o->getStatus(),
            'total_minor' => $o->getTotalMinor(),
            'currency_code' => $o->getCurrencyCode(),
        ], $orders);

        return ApiResponse::success($data, ['total' => count($data)]);
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function cancel(int $orderId, array $requestData, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);
        $reason = isset($requestData['reason']) ? (string) $requestData['reason'] : null;

        $order = $this->orderAppService->cancelOrder($orderId, $reason, $userId, $isAdmin);

        return ApiResponse::success([
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'status' => $order->getStatus(),
        ], ['action' => 'order_cancelled']);
    }
}
