<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Documents;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceItem;
use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Rendering\DocumentItemLine;
use Coleza\Domain\Documents\Rendering\DocumentLocalization;
use Coleza\Domain\Documents\Rendering\DocumentParty;
use Coleza\Domain\Documents\Rendering\DocumentRenderService;
use Coleza\Domain\Documents\Rendering\DocumentTaxBreakdown;
use Coleza\Domain\Documents\Rendering\DocumentTemplateEngine;
use Coleza\Domain\Documents\Rendering\DocumentTotals;
use Coleza\Domain\Documents\Rendering\DocumentViewModel;
use Coleza\Domain\Documents\Rendering\PurePhpPdfRenderer;
use PHPUnit\Framework\TestCase;

final class DocumentRenderServiceTest extends TestCase
{
    private DocumentRenderService $service;

    protected function setUp(): void
    {
        $this->service = new DocumentRenderService();
    }

    public function testRenderHtmlWithTurkishLocale(): void
    {
        $issuer = new DocumentParty(
            name: 'Coleza Bulut Bilişim A.Ş.',
            companyName: 'Coleza Bulut Bilişim A.Ş.',
            taxNumber: '1234567890',
            taxOffice: 'Kadıköy',
            email: 'finans@coleza.com',
            phone: '+90 216 555 0100',
            addressLine1: 'Bağdat Caddesi No: 42',
            city: 'İstanbul',
            postalCode: '34710',
            countryCode: 'TR'
        );

        $recipient = new DocumentParty(
            name: 'Ahmet Yılmaz',
            companyName: 'Yılmaz Yazılım Ltd. Şti.',
            taxNumber: '9876543210',
            taxOffice: 'Beşiktaş',
            email: 'ahmet@yilmazyazilim.com',
            addressLine1: 'Büyükdere Cad. No: 100',
            city: 'İstanbul',
            postalCode: '34394',
            countryCode: 'TR'
        );

        $items = [
            new DocumentItemLine(
                description: 'Kurumsal Bulut Sunucu - 4 vCPU, 8 GB RAM (Çiçek & Şeker Paket)',
                quantity: 1.0,
                unitPrice: 1000.0,
                taxRate: 20.0,
                taxAmount: 200.0,
                discountAmount: 0.0,
                lineTotal: 1200.0
            ),
        ];

        $taxBreakdowns = [
            new DocumentTaxBreakdown(taxRate: 20.0, taxableAmount: 1000.0, taxAmount: 200.0),
        ];

        $totals = new DocumentTotals(
            subtotal: 1000.0,
            discountTotal: 0.0,
            taxableTotal: 1000.0,
            taxBreakdowns: $taxBreakdowns,
            taxTotal: 200.0,
            grandTotal: 1200.0,
            paidAmount: 1200.0
        );

        $model = new DocumentViewModel(
            documentId: '101',
            documentNumber: 'INV-2026-000101',
            type: DocumentType::INVOICE,
            status: 'PAID',
            issueDate: '2026-10-01',
            dueDate: '2026-10-15',
            paidAt: '2026-10-02 14:30:00',
            currency: 'TRY',
            currencySymbol: '₺',
            issuer: $issuer,
            recipient: $recipient,
            items: $items,
            totals: $totals,
            locale: 'tr',
            bankAccounts: [
                [
                    'bankName' => 'Garanti BBVA',
                    'accountHolder' => 'Coleza Bulut Bilişim A.Ş.',
                    'iban' => 'TR120006200000001234567890',
                    'swift' => 'TGBATR2A',
                    'currency' => 'TRY',
                ],
            ],
            notes: 'Ödemeniz için teşekkür ederiz. Destek taleplerinizi müşteri panelinden iletebilirsiniz.',
            verificationUrl: 'https://verify.coleza.com/doc/INV-2026-000101'
        );

        $html = $this->service->renderHtml($model);

        $this->assertStringContainsString('Fatura', $html);
        $this->assertStringContainsString('INV-2026-000101', $html);
        $this->assertStringContainsString('ÖDENDİ', $html);
        $this->assertStringContainsString('Sayın (Alıcı)', $html);
        $this->assertStringContainsString('Yılmaz Yazılım Ltd. Şti.', $html);
        $this->assertStringContainsString('Kurumsal Bulut Sunucu - 4 vCPU, 8 GB RAM (Çiçek &amp; Şeker Paket)', $html);
        $this->assertStringContainsString('Ara Toplam', $html);
        $this->assertStringContainsString('Genel Toplam', $html);
        $this->assertStringContainsString('Garanti BBVA', $html);
        $this->assertStringContainsString('TR120006200000001234567890', $html);
        $this->assertStringContainsString('https://verify.coleza.com/doc/INV-2026-000101', $html);
        $this->assertStringContainsString('charset="UTF-8"', $html);
    }

    public function testRenderHtmlWithEnglishLocale(): void
    {
        $issuer = new DocumentParty(
            name: 'Coleza Cloud Inc.',
            companyName: 'Coleza Cloud Inc.',
            taxNumber: 'US-992819',
            email: 'billing@coleza.com',
            addressLine1: '500 Howard St',
            city: 'San Francisco',
            state: 'CA',
            postalCode: '94105',
            countryCode: 'US'
        );

        $recipient = new DocumentParty(
            name: 'John Doe',
            companyName: 'Acme Global Corp',
            email: 'john@acme.com',
            addressLine1: '123 Market St',
            city: 'San Francisco',
            state: 'CA',
            postalCode: '94103',
            countryCode: 'US'
        );

        $items = [
            new DocumentItemLine(
                description: 'cPanel Dedicated Server Hosting',
                quantity: 2.0,
                unitPrice: 150.0,
                taxRate: 0.0,
                taxAmount: 0.0,
                discountAmount: 20.0,
                lineTotal: 280.0
            ),
        ];

        $totals = new DocumentTotals(
            subtotal: 300.0,
            discountTotal: 20.0,
            taxableTotal: 280.0,
            taxBreakdowns: [],
            taxTotal: 0.0,
            grandTotal: 280.0,
            paidAmount: 0.0
        );

        $model = new DocumentViewModel(
            documentId: '202',
            documentNumber: 'INV-2026-000202',
            type: DocumentType::INVOICE,
            status: 'UNPAID',
            issueDate: '2026-10-05',
            dueDate: '2026-10-20',
            paidAt: null,
            currency: 'USD',
            currencySymbol: '$',
            issuer: $issuer,
            recipient: $recipient,
            items: $items,
            totals: $totals,
            locale: 'en'
        );

        $html = $this->service->renderHtml($model);

        $this->assertStringContainsString('Invoice', $html);
        $this->assertStringContainsString('INV-2026-000202', $html);
        $this->assertStringContainsString('UNPAID', $html);
        $this->assertStringContainsString('Billed To', $html);
        $this->assertStringContainsString('Acme Global Corp', $html);
        $this->assertStringContainsString('cPanel Dedicated Server Hosting', $html);
        $this->assertStringContainsString('Total Discount', $html);
        $this->assertStringContainsString('Balance Due', $html);
        $this->assertStringContainsString('$280.00', $html);
    }

    public function testRenderPdfValidBinaryOutput(): void
    {
        $issuer = new DocumentParty(
            name: 'Coleza Host',
            addressLine1: 'Test Str 1',
            city: 'Istanbul',
            countryCode: 'TR'
        );

        $recipient = new DocumentParty(
            name: 'Client Customer',
            email: 'client@example.com',
            countryCode: 'TR'
        );

        $totals = new DocumentTotals(
            subtotal: 50.0,
            discountTotal: 0.0,
            taxableTotal: 50.0,
            taxBreakdowns: [],
            taxTotal: 0.0,
            grandTotal: 50.0
        );

        $model = new DocumentViewModel(
            documentId: '301',
            documentNumber: 'QUO-2026-000301',
            type: DocumentType::QUOTE,
            status: 'SENT',
            issueDate: '2026-10-07',
            dueDate: '2026-10-21',
            paidAt: null,
            currency: 'EUR',
            currencySymbol: '€',
            issuer: $issuer,
            recipient: $recipient,
            items: [
                new DocumentItemLine(
                    description: 'Managed VPS Setup & Maintenance',
                    quantity: 1.0,
                    unitPrice: 50.0,
                    taxRate: 0.0,
                    taxAmount: 0.0
                ),
            ],
            totals: $totals,
            locale: 'en'
        );

        $pdf = $this->service->renderPdf($model);

        // PDF specification binary checks
        $this->assertStringStartsWith("%PDF-1.4\n", $pdf);
        $this->assertStringEndsWith("%%EOF", $pdf);
        $this->assertStringContainsString('/Type /Catalog', $pdf);
        $this->assertStringContainsString('/Type /Pages', $pdf);
        $this->assertStringContainsString('/Type /Page', $pdf);
        $this->assertStringContainsString('/MediaBox [ 0 0 595.28 841.89 ]', $pdf);
        $this->assertStringContainsString('stream', $pdf);
        $this->assertStringContainsString('endstream', $pdf);
        $this->assertStringContainsString('xref', $pdf);
        $this->assertStringContainsString('trailer', $pdf);
        $this->assertStringContainsString('startxref', $pdf);

        // Verification hash calculation
        $hash = $this->service->calculateHash($pdf);
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testBuildFromInvoiceFactory(): void
    {
        $items = [
            new InvoiceItem(
                id: 1,
                invoiceId: 10,
                description: 'Linux Shared Hosting Premium',
                quantity: 1,
                unitAmountMinor: 50000,
                subtotalMinor: 50000,
                taxAmountMinor: 10000,
                totalMinor: 60000,
                taxSnapshot: ['rate' => 20.0]
            ),
        ];

        $invoice = new Invoice(
            id: 10,
            invoiceNumber: 'INV-2026-000010',
            userId: 5,
            organizationId: null,
            orderId: 100,
            status: Invoice::STATUS_PAID,
            currencyCode: 'TRY',
            subtotalMinor: 50000,
            taxTotalMinor: 10000,
            totalMinor: 60000,
            paidAmountMinor: 60000,
            issueDate: '2026-10-01',
            dueDate: '2026-10-08',
            paidAt: '2026-10-01 12:00:00',
            currencySnapshot: ['code' => 'TRY', 'rate' => 1.0],
            taxSnapshot: ['rules' => []],
            notes: 'Test invoice notes',
            items: $items
        );

        $issuer = new DocumentParty(name: 'Coleza Platform');
        $recipient = new DocumentParty(name: 'Test Client');

        $viewModel = $this->service->buildFromInvoice(
            invoice: $invoice,
            issuer: $issuer,
            recipient: $recipient,
            locale: 'tr'
        );

        $this->assertSame('INV-2026-000010', $viewModel->getDocumentNumber());
        $this->assertSame(DocumentType::INVOICE, $viewModel->getType());
        $this->assertSame('PAID', $viewModel->getStatus());
        $this->assertSame(500.0, $viewModel->getTotals()->getSubtotal());
        $this->assertSame(100.0, $viewModel->getTotals()->getTaxTotal());
        $this->assertSame(600.0, $viewModel->getTotals()->getGrandTotal());
        $this->assertSame(600.0, $viewModel->getTotals()->getPaidAmount());
        $this->assertSame(0.0, $viewModel->getTotals()->getBalanceDue());

        $html = $this->service->renderHtml($viewModel);
        $this->assertStringContainsString('INV-2026-000010', $html);
        $this->assertStringContainsString('Linux Shared Hosting Premium', $html);
        $this->assertStringContainsString('ÖDENDİ', $html);
    }
}
