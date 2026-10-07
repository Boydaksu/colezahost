<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Documents\DocumentType;

final class DocumentRenderService
{
    public function __construct(
        private ?DocumentTemplateEngine $templateEngine = null,
        private ?PdfRendererInterface $pdfRenderer = null
    ) {
        $this->templateEngine ??= new DocumentTemplateEngine();
        $this->pdfRenderer ??= new PurePhpPdfRenderer();
    }

    public function renderHtml(DocumentViewModel $model): string
    {
        return $this->templateEngine->render($model);
    }

    /**
     * @param DocumentViewModel $model
     * @param array<string, mixed> $options
     * @return string
     */
    public function renderPdf(DocumentViewModel $model, array $options = []): string
    {
        $html = $this->renderHtml($model);

        $defaultOptions = [
            'title' => $model->getType()->label($model->getLocale()) . ' ' . $model->getDocumentNumber(),
            'author' => $model->getIssuer()->getDisplayName(),
            'subject' => $model->getDocumentNumber(),
        ];

        return $this->pdfRenderer->render($html, array_merge($defaultOptions, $options));
    }

    public function calculateHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Factory that maps a domain Invoice into a standardized DocumentViewModel.
     *
     * @param Invoice $invoice
     * @param DocumentParty $issuer
     * @param DocumentParty $recipient
     * @param array<array{bankName: string, accountHolder: string, iban: string, swift?: ?string, currency: string}> $bankAccounts
     * @param string $locale
     * @param string|null $verificationUrl
     * @return DocumentViewModel
     */
    public function buildFromInvoice(
        Invoice $invoice,
        DocumentParty $issuer,
        DocumentParty $recipient,
        array $bankAccounts = [],
        string $locale = 'en',
        ?string $verificationUrl = null
    ): DocumentViewModel {
        $currencyCode = $invoice->getCurrencyCode();
        $currencySymbol = match (strtoupper($currencyCode)) {
            'TRY' => '₺',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $currencyCode,
        };

        $itemLines = [];
        $taxBreakdownsMap = [];

        foreach ($invoice->getItems() as $item) {
            $unitPrice = $item->getUnitAmountMinor() / 100.0;
            $subtotal = $item->getSubtotalMinor() / 100.0;
            $taxAmount = $item->getTaxAmountMinor() / 100.0;
            $total = $item->getTotalMinor() / 100.0;

            // Compute effective tax rate from snapshot or amounts
            $taxRate = 0.0;
            $taxSnapshot = $item->getTaxSnapshot();
            if (!empty($taxSnapshot['rate'])) {
                $taxRate = (float) $taxSnapshot['rate'];
            } elseif ($subtotal > 0 && $taxAmount > 0) {
                $taxRate = round(($taxAmount / $subtotal) * 100.0, 2);
            }

            $itemLines[] = new DocumentItemLine(
                description: $item->getDescription(),
                quantity: (float) $item->getQuantity(),
                unitPrice: $unitPrice,
                taxRate: $taxRate,
                taxAmount: $taxAmount,
                discountAmount: 0.0,
                lineTotal: $total
            );

            // Group taxes
            $rateKey = (string) $taxRate;
            if (!isset($taxBreakdownsMap[$rateKey])) {
                $taxBreakdownsMap[$rateKey] = [
                    'rate' => $taxRate,
                    'taxable' => 0.0,
                    'tax' => 0.0,
                ];
            }
            $taxBreakdownsMap[$rateKey]['taxable'] += $subtotal;
            $taxBreakdownsMap[$rateKey]['tax'] += $taxAmount;
        }

        $taxBreakdowns = [];
        foreach ($taxBreakdownsMap as $tb) {
            $taxBreakdowns[] = new DocumentTaxBreakdown(
                taxRate: $tb['rate'],
                taxableAmount: round($tb['taxable'], 2),
                taxAmount: round($tb['tax'], 2)
            );
        }

        $subtotal = $invoice->getSubtotalMinor() / 100.0;
        $taxTotal = $invoice->getTaxTotalMinor() / 100.0;
        $grandTotal = $invoice->getTotalMinor() / 100.0;
        $paidAmount = $invoice->getPaidAmountMinor() / 100.0;

        $totals = new DocumentTotals(
            subtotal: $subtotal,
            discountTotal: 0.0,
            taxableTotal: $subtotal,
            taxBreakdowns: $taxBreakdowns,
            taxTotal: $taxTotal,
            grandTotal: $grandTotal,
            paidAmount: $paidAmount
        );

        return new DocumentViewModel(
            documentId: (string) ($invoice->getId() ?? $invoice->getInvoiceNumber()),
            documentNumber: $invoice->getInvoiceNumber(),
            type: DocumentType::INVOICE,
            status: $invoice->getStatus(),
            issueDate: $invoice->getIssueDate(),
            dueDate: $invoice->getDueDate(),
            paidAt: $invoice->getPaidAt(),
            currency: $currencyCode,
            currencySymbol: $currencySymbol,
            issuer: $issuer,
            recipient: $recipient,
            items: $itemLines,
            totals: $totals,
            locale: $locale,
            paymentInstructions: null,
            bankAccounts: $bankAccounts,
            notes: $invoice->getNotes(),
            terms: 'Payment is due according to the specified due date. Late payments may result in service suspension.',
            qrCodeData: $verificationUrl,
            verificationUrl: $verificationUrl,
            metadata: [
                'userId' => $invoice->getUserId(),
                'orderId' => $invoice->getOrderId(),
            ]
        );
    }
}
