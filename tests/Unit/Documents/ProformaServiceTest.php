<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Documents;

use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Documents\Proforma\ProformaService;
use Coleza\Domain\Documents\Proforma\ProformaStatus;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProformaServiceTest extends TestCase
{
    private PDO $pdo;
    private ProformaService $proformaService;
    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->proformaService = new ProformaService($this->pdo);

        $connection = new Connection($this->pdo);
        $this->invoiceService = new InvoiceService($connection);
        $this->invoiceService->ensureTables();
    }

    public function testCreateProformaInvoice(): void
    {
        $items = [
            [
                'description' => 'Colocation Rack Unit (1U) + 1Gbps Port',
                'quantity' => 2,
                'unit_amount_minor' => 120000,
                'tax_rate' => 20.0,
            ],
        ];

        $proforma = $this->proformaService->createProforma(
            userId: 15,
            itemsData: $items,
            currencyCode: 'TRY',
            organizationId: 3,
            notes: 'Advance prepayment proforma'
        );

        $this->assertNotNull($proforma->getId());
        $this->assertSame(ProformaStatus::DRAFT, $proforma->getStatus());
        $this->assertStringStartsWith('PRO-', $proforma->getProformaNumber());
        $this->assertSame(240000, $proforma->getSubtotalMinor());
        $this->assertSame(48000, $proforma->getTaxTotalMinor());
        $this->assertSame(288000, $proforma->getTotalMinor());
        $this->assertSame(0, $proforma->getPaidAmountMinor());
        $this->assertSame(288000, $proforma->getBalanceDueMinor());
    }

    public function testIssueProformaInvoice(): void
    {
        $proforma = $this->proformaService->createProforma(
            userId: 15,
            itemsData: [
                ['description' => 'IP Transit 10G', 'quantity' => 1, 'unit_amount_minor' => 500000, 'tax_rate' => 0.0],
            ]
        );

        $issued = $this->proformaService->issueProforma($proforma->getId());
        $this->assertSame(ProformaStatus::ISSUED, $issued->getStatus());
    }

    public function testRecordPaymentPartialAndFull(): void
    {
        $proforma = $this->proformaService->createProforma(
            userId: 15,
            itemsData: [
                ['description' => 'Server Migration Consultation', 'quantity' => 1, 'unit_amount_minor' => 100000, 'tax_rate' => 20.0],
            ]
        );

        $issued = $this->proformaService->issueProforma($proforma->getId());

        // Partial payment: 500.00 TRY of 1,200.00 TRY
        $partiallyPaid = $this->proformaService->recordPayment($issued->getId(), 50000);
        $this->assertSame(ProformaStatus::ISSUED, $partiallyPaid->getStatus());
        $this->assertSame(50000, $partiallyPaid->getPaidAmountMinor());
        $this->assertSame(70000, $partiallyPaid->getBalanceDueMinor());
        $this->assertFalse($partiallyPaid->isFullyPaid());

        // Settle remaining 700.00 TRY
        $fullyPaid = $this->proformaService->recordPayment($partiallyPaid->getId(), 70000, '2026-10-07 15:00:00');
        $this->assertSame(ProformaStatus::PAID, $fullyPaid->getStatus());
        $this->assertSame(120000, $fullyPaid->getPaidAmountMinor());
        $this->assertSame(0, $fullyPaid->getBalanceDueMinor());
        $this->assertTrue($fullyPaid->isFullyPaid());
        $this->assertSame('2026-10-07 15:00:00', $fullyPaid->getPaidAt());
    }

    public function testConvertToTaxInvoice(): void
    {
        $proforma = $this->proformaService->createProforma(
            userId: 18,
            itemsData: [
                ['description' => 'Dedicated SAN Storage LUN 1TB', 'quantity' => 1, 'unit_amount_minor' => 80000, 'tax_rate' => 20.0],
            ]
        );

        $this->proformaService->issueProforma($proforma->getId());
        $this->proformaService->recordPayment($proforma->getId(), 96000);

        $conversion = $this->proformaService->convertToTaxInvoice($proforma->getId(), $this->invoiceService);

        $convertedProforma = $conversion['proforma'];
        $invoice = $conversion['invoice'];

        $this->assertSame(ProformaStatus::CONVERTED, $convertedProforma->getStatus());
        $this->assertNotNull($convertedProforma->getConvertedInvoiceId());
        $this->assertSame($invoice->getId(), $convertedProforma->getConvertedInvoiceId());
        $this->assertSame($invoice->getInvoiceNumber(), $convertedProforma->getConvertedInvoiceNumber());
        $this->assertStringStartsWith('INV-', $invoice->getInvoiceNumber());

        // Re-converting must fail
        $this->expectException(RuntimeException::class);
        $this->proformaService->convertToTaxInvoice($proforma->getId(), $this->invoiceService);
    }

    public function testCancelProforma(): void
    {
        $proforma = $this->proformaService->createProforma(
            userId: 25,
            itemsData: [
                ['description' => 'Consulting Hours', 'quantity' => 5, 'unit_amount_minor' => 10000, 'tax_rate' => 0.0],
            ]
        );

        $cancelled = $this->proformaService->cancelProforma($proforma->getId(), 'Client requested order scope adjustment');
        $this->assertSame(ProformaStatus::CANCELLED, $cancelled->getStatus());
        $this->assertStringContainsString('Cancellation reason: Client requested order scope adjustment', $cancelled->getNotes());

        // Cannot convert cancelled proforma
        $this->expectException(RuntimeException::class);
        $this->proformaService->convertToTaxInvoice($cancelled->getId(), $this->invoiceService);
    }
}
