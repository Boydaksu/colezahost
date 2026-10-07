<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Documents;

use Coleza\Domain\Documents\Quotes\IllegalStateTransitionException;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Documents\Quotes\QuoteService;
use Coleza\Domain\Documents\Quotes\QuoteStatus;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuoteServiceTest extends TestCase
{
    private PDO $pdo;
    private QuoteService $quoteService;
    private OrderService $orderService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->quoteService = new QuoteService($this->pdo);

        // Prepare OrderService on the same PDO database
        $connection = new Connection($this->pdo);

        $this->orderService = new OrderService($connection);
        $this->orderService->ensureTables();
    }

    public function testCreateQuoteCalculations(): void
    {
        $items = [
            [
                'description' => 'Dedicated Server XEON E-2288G',
                'quantity' => 1,
                'unit_amount_minor' => 150000,
                'tax_rate' => 20.0,
                'billing_cycle' => 'monthly',
            ],
            [
                'description' => 'cPanel Premier 100 Accounts License',
                'quantity' => 1,
                'unit_amount_minor' => 45000,
                'tax_rate' => 20.0,
                'billing_cycle' => 'monthly',
            ],
        ];

        $quote = $this->quoteService->createQuote(
            userId: 10,
            itemsData: $items,
            currencyCode: 'TRY',
            validUntil: '+30 days',
            organizationId: 2,
            notes: 'Enterprise dedicated proposal'
        );

        $this->assertNotNull($quote->getId());
        $this->assertSame(QuoteStatus::DRAFT, $quote->getStatus());
        $this->assertSame(1, $quote->getVersion());
        $this->assertSame(195000, $quote->getSubtotalMinor());
        $this->assertSame(39000, $quote->getTaxTotalMinor());
        $this->assertSame(234000, $quote->getTotalMinor());
        $this->assertCount(2, $quote->getItems());
        $this->assertStringStartsWith('QUO-', $quote->getQuoteNumber());
    }

    public function testCreateRevisionIncrementsVersion(): void
    {
        $items = [
            [
                'description' => 'Custom Cloud Infrastructure',
                'quantity' => 1,
                'unit_amount_minor' => 100000,
                'tax_rate' => 0.0,
            ],
        ];

        $quote = $this->quoteService->createQuote(
            userId: 5,
            itemsData: $items,
            currencyCode: 'USD'
        );

        $revisedItems = [
            [
                'description' => 'Custom Cloud Infrastructure with 10% Partner Discount',
                'quantity' => 1,
                'unit_amount_minor' => 90000,
                'tax_rate' => 0.0,
            ],
        ];

        $revision = $this->quoteService->createRevision(
            quoteId: $quote->getId(),
            itemsData: $revisedItems,
            revisionNote: 'Applied promotional partner discount rate'
        );

        $this->assertSame(2, $revision->getVersion());
        $this->assertSame($quote->getQuoteNumber(), $revision->getOriginalQuoteNumber());
        $this->assertSame($quote->getQuoteNumber() . '-R2', $revision->getQuoteNumber());
        $this->assertSame(90000, $revision->getTotalMinor());
        $this->assertSame('Applied promotional partner discount rate', $revision->getRevisionNote());
    }

    public function testQuoteSendAcceptWorkflow(): void
    {
        $quote = $this->quoteService->createQuote(
            userId: 7,
            itemsData: [
                ['description' => 'Web Agency Hosting', 'quantity' => 1, 'unit_amount_minor' => 20000, 'tax_rate' => 20.0],
            ]
        );

        $sent = $this->quoteService->sendQuote($quote->getId());
        $this->assertSame(QuoteStatus::SENT, $sent->getStatus());
        $this->assertNotNull($sent->getSentAt());

        $accepted = $this->quoteService->acceptQuote($sent->getId(), '192.168.1.100');
        $this->assertSame(QuoteStatus::ACCEPTED, $accepted->getStatus());
        $this->assertNotNull($accepted->getAcceptedAt());
        $this->assertSame('192.168.1.100', $accepted->getAcceptedIp());
    }

    public function testQuoteRejectWorkflow(): void
    {
        $quote = $this->quoteService->createQuote(
            userId: 7,
            itemsData: [
                ['description' => 'Dedicated IP Allocation', 'quantity' => 1, 'unit_amount_minor' => 5000, 'tax_rate' => 0.0],
            ]
        );

        $sent = $this->quoteService->sendQuote($quote->getId());
        $rejected = $this->quoteService->rejectQuote($sent->getId(), 'Customer selected alternate supplier');

        $this->assertSame(QuoteStatus::REJECTED, $rejected->getStatus());
        $this->assertSame('Customer selected alternate supplier', $rejected->getRejectionReason());

        // Cannot accept a rejected quote
        $this->expectException(IllegalStateTransitionException::class);
        $this->quoteService->acceptQuote($rejected->getId());
    }

    public function testQuoteExpiryBatchWorker(): void
    {
        // Quote expired yesterday
        $quote = $this->quoteService->createQuote(
            userId: 12,
            itemsData: [
                ['description' => 'Domain Registration', 'quantity' => 1, 'unit_amount_minor' => 1500, 'tax_rate' => 0.0],
            ],
            validUntil: '-2 days'
        );

        $this->quoteService->sendQuote($quote->getId());

        $expiredCount = $this->quoteService->expireQuotes();
        $this->assertGreaterThanOrEqual(1, $expiredCount);

        $expiredQuote = $this->quoteService->find($quote->getId());
        $this->assertNotNull($expiredQuote);
        $this->assertSame(QuoteStatus::EXPIRED, $expiredQuote->getStatus());

        // Attempting to accept an expired quote directly throws error
        $this->expectException(RuntimeException::class);
        $this->quoteService->acceptQuote($quote->getId());
    }

    public function testConvertQuoteToOrder(): void
    {
        $quote = $this->quoteService->createQuote(
            userId: 20,
            itemsData: [
                [
                    'product_id' => 101,
                    'description' => 'Enterprise Cloud VPS',
                    'quantity' => 2,
                    'unit_amount_minor' => 50000,
                    'tax_rate' => 20.0,
                    'billing_cycle' => 'monthly',
                ],
            ]
        );

        $this->quoteService->sendQuote($quote->getId());
        $this->quoteService->acceptQuote($quote->getId(), '10.0.0.1');

        $result = $this->quoteService->convertToOrder($quote->getId(), $this->orderService);

        $convertedQuote = $result['quote'];
        $order = $result['order'];

        $this->assertSame(QuoteStatus::CONVERTED, $convertedQuote->getStatus());
        $this->assertNotNull($convertedQuote->getConvertedOrderId());
        $this->assertSame($order->getId(), $convertedQuote->getConvertedOrderId());

        // Order asserts
        $this->assertSame(20, $order->getUserId());
        $this->assertSame('TRY', $order->getCurrencyCode());
        $this->assertCount(1, $order->getItems());
        $this->assertSame('Enterprise Cloud VPS', $order->getItems()[0]->getProductName());
    }
}
