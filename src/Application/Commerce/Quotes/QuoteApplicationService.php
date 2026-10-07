<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Quotes;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Documents\Quotes\Quote;
use Coleza\Domain\Documents\Quotes\QuoteService;
use Coleza\Domain\Documents\Quotes\QuoteStatus;
use Coleza\Foundation\Exceptions\AuthorizationException;
use Coleza\Foundation\Exceptions\ResourceNotFoundException;
use RuntimeException;

final class QuoteApplicationService
{
    public function __construct(
        private QuoteService $quoteService,
        private ?OrderService $orderService = null
    ) {
    }

    public function getQuote(int $quoteId, ?int $authUserId = null, bool $isAdmin = false): Quote
    {
        $quote = $this->quoteService->find($quoteId);
        if ($quote === null) {
            throw new ResourceNotFoundException("Quote {$quoteId} not found.");
        }

        if (!$isAdmin && $authUserId !== null && $quote->getUserId() !== $authUserId) {
            throw new AuthorizationException('Forbidden: You do not have access to this quote.');
        }

        return $quote;
    }

    public function acceptQuote(int $quoteId, string $ipAddress, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        $quote = $this->getQuote($quoteId, $authUserId, $isAdmin);

        if ($quote->getStatus() === QuoteStatus::DRAFT) {
            $quote = $this->quoteService->sendQuote($quote->getId());
        }

        if ($quote->getStatus() === QuoteStatus::SENT) {
            $quote = $this->quoteService->acceptQuote($quote->getId(), $ipAddress);
        }

        if ($this->orderService === null) {
            throw new RuntimeException('OrderService required to convert quote to order.');
        }

        $conversion = $this->quoteService->convertToOrder($quote->getId(), $this->orderService, $ipAddress);

        return $conversion['order'];
    }
}
