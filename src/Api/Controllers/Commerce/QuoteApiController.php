<?php

declare(strict_types=1);

namespace Coleza\Api\Controllers\Commerce;

use Coleza\Api\Response\ApiResponse;
use Coleza\Application\Commerce\Quotes\QuoteApplicationService;

final class QuoteApiController
{
    public function __construct(
        private QuoteApplicationService $quoteAppService
    ) {
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function show(int $quoteId, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $quote = $this->quoteAppService->getQuote($quoteId, $userId, $isAdmin);

        return ApiResponse::success([
            'id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'user_id' => $quote->getUserId(),
            'status' => $quote->getStatus(),
            'total_minor' => $quote->getTotalMinor(),
            'currency_code' => $quote->getCurrencyCode(),
            'valid_until' => $quote->getValidUntil(),
        ]);
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function accept(int $quoteId, array $requestData, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);
        $ip = (string) ($requestData['ip'] ?? '127.0.0.1');

        $order = $this->quoteAppService->acceptQuote($quoteId, $ip, $userId, $isAdmin);

        return ApiResponse::success([
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'status' => $order->getStatus(),
            'total_minor' => $order->getTotalMinor(),
        ], ['action' => 'quote_accepted_converted_to_order'], 201);
    }
}
