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
    private \Coleza\Application\Identity\CommandAuthorizer $authorization;

    public function __construct(
        private QuoteService $quoteService,
        private ?OrderService $orderService = null
    ) {
        $this->authorization = new \Coleza\Application\Identity\CommandAuthorizer($this->quoteService->connection());
    }

    public function getQuote(int $quoteId, ?int $authUserId = null, bool $isAdmin = false): Quote
    {
        return $this->authorization->run($authUserId, function () use ($quoteId, $authUserId, $isAdmin): Quote {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'quotes.view');

            $quote = $this->quoteService->find($quoteId);
            if ($quote === null) {
                throw new ResourceNotFoundException("Quote {$quoteId} not found.");
            }

            $this->authorization->resource($authUserId, $quote->getUserId(), $quote->getOrganizationId(), 'quotes.view', 'org.quotes.view');


            return $quote;

        }, null);
    }

    public function acceptQuote(int $quoteId, string $ipAddress, ?int $authUserId = null, bool $isAdmin = false): Order
    {
        return $this->authorization->run($authUserId, function () use ($quoteId, $ipAddress, $authUserId, $isAdmin): Order {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'quotes.manage');

            $quote = $this->getQuote($quoteId, $authUserId, $isAdmin);

            $this->authorization->resource($authUserId, $quote->getUserId(), $quote->getOrganizationId(), 'quotes.manage', 'org.quotes.manage');

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

        }, 'command.acceptQuote');
    }
}
