<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

final class DocumentTemplateEngine
{
    public function render(DocumentViewModel $model): string
    {
        $loc = $model->getLocale();
        $type = $model->getType();
        $typeTitle = DocumentLocalization::trans($type->value, $loc);
        $issuer = $model->getIssuer();
        $recipient = $model->getRecipient();
        $totals = $model->getTotals();
        $currencySymbol = $model->getCurrencySymbol();

        $statusKey = 'status_' . strtolower($model->getStatus());
        $statusLabel = DocumentLocalization::trans($statusKey, $loc);
        if ($statusLabel === $statusKey) {
            $statusLabel = $model->getStatus();
        }

        $statusColor = match ($model->getStatus()) {
            'PAID', 'ACCEPTED' => '#16a34a', // green
            'UNPAID', 'EXPIRED' => '#dc2626', // red
            'SENT', 'DRAFT' => '#0284c7', // blue
            'CANCELLED', 'REJECTED' => '#64748b', // slate
            default => '#475569',
        };

        $e = fn(?string $val): string => htmlspecialchars($val ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $itemsHtml = '';
        foreach ($model->getItems() as $item) {
            $formattedPrice = DocumentLocalization::formatMoney($item->getUnitPrice(), $currencySymbol, $loc);
            $formattedDiscount = $item->getDiscountAmount() > 0 
                ? DocumentLocalization::formatMoney($item->getDiscountAmount(), $currencySymbol, $loc) 
                : '-';
            $formattedTax = DocumentLocalization::formatMoney($item->getTaxAmount(), $currencySymbol, $loc);
            $formattedLineTotal = DocumentLocalization::formatMoney($item->getLineTotal(), $currencySymbol, $loc);

            $itemsHtml .= <<<HTML
            <tr>
                <td class="col-desc">
                    <div class="item-name">{$e($item->getDescription())}</div>
                </td>
                <td class="col-qty text-center">{$item->getQuantity()}</td>
                <td class="col-price text-right">{$formattedPrice}</td>
                <td class="col-discount text-right">{$formattedDiscount}</td>
                <td class="col-tax text-right">{$item->getTaxRate()}% ({$formattedTax})</td>
                <td class="col-total text-right font-semibold">{$formattedLineTotal}</td>
            </tr>
HTML;
        }

        $taxBreakdownHtml = '';
        foreach ($totals->getTaxBreakdowns() as $tb) {
            $tbFormatted = DocumentLocalization::formatMoney($tb->getTaxAmount(), $currencySymbol, $loc);
            $taxBreakdownHtml .= <<<HTML
            <div class="summary-row text-muted text-sm">
                <span>{$e(DocumentLocalization::trans('tax_amount', $loc))} ({$tb->getTaxRate()}%):</span>
                <span>{$tbFormatted}</span>
            </div>
HTML;
        }

        $bankAccountsHtml = '';
        if (!empty($model->getBankAccounts())) {
            $bankAccountsHtml .= '<div class="bank-accounts"><div class="section-title">' . $e(DocumentLocalization::trans('payment_details', $loc)) . '</div>';
            foreach ($model->getBankAccounts() as $ba) {
                $swiftHtml = !empty($ba['swift']) ? ' | ' . $e(DocumentLocalization::trans('swift', $loc)) . ': ' . $e($ba['swift']) : '';
                $bankAccountsHtml .= <<<HTML
                <div class="bank-card">
                    <div class="bank-name font-semibold">{$e($ba['bankName'])} ({$e($ba['currency'])})</div>
                    <div class="text-sm">{$e(DocumentLocalization::trans('account_holder', $loc))}: {$e($ba['accountHolder'])}</div>
                    <div class="text-sm font-mono font-semibold">{$e(DocumentLocalization::trans('iban', $loc))}: {$e($ba['iban'])}{$swiftHtml}</div>
                </div>
HTML;
            }
            $bankAccountsHtml .= '</div>';
        }

        $notesHtml = '';
        if ($model->getNotes() !== null && trim($model->getNotes()) !== '') {
            $notesHtml = <<<HTML
            <div class="notes-section">
                <div class="section-title">{$e(DocumentLocalization::trans('notes', $loc))}</div>
                <div class="text-sm text-muted">{$e($model->getNotes())}</div>
            </div>
HTML;
        }

        $termsHtml = '';
        if ($model->getTerms() !== null && trim($model->getTerms()) !== '') {
            $termsHtml = <<<HTML
            <div class="terms-section">
                <div class="section-title">{$e(DocumentLocalization::trans('terms', $loc))}</div>
                <div class="text-sm text-muted">{$e($model->getTerms())}</div>
            </div>
HTML;
        }

        $verificationHtml = '';
        if ($model->getVerificationUrl() !== null || $model->getQrCodeData() !== null) {
            $verifyText = DocumentLocalization::trans('verification', $loc);
            $verifyUrl = $e($model->getVerificationUrl() ?? $model->getQrCodeData());
            $verificationHtml = <<<HTML
            <div class="verification-badge text-center">
                <span class="text-xs text-muted">{$e($verifyText)}:</span>
                <span class="text-xs font-mono">{$verifyUrl}</span>
            </div>
HTML;
        }

        $logoHtml = $issuer->getLogoUrl() !== null 
            ? '<img src="' . $e($issuer->getLogoUrl()) . '" alt="Logo" class="brand-logo" />' 
            : '<h1 class="brand-name">' . $e($issuer->getDisplayName()) . '</h1>';

        $issuerTaxInfo = array_filter([
            $issuer->getTaxOffice() ? DocumentLocalization::trans('tax_office', $loc) . ': ' . $issuer->getTaxOffice() : null,
            $issuer->getTaxNumber() ? DocumentLocalization::trans('tax_number', $loc) . ': ' . $issuer->getTaxNumber() : null,
        ]);
        $issuerTaxStr = !empty($issuerTaxInfo) ? implode(' | ', $issuerTaxInfo) : '';

        $recipientTaxInfo = array_filter([
            $recipient->getTaxOffice() ? DocumentLocalization::trans('tax_office', $loc) . ': ' . $recipient->getTaxOffice() : null,
            $recipient->getTaxNumber() ? DocumentLocalization::trans('tax_number', $loc) . ': ' . $recipient->getTaxNumber() : null,
        ]);
        $recipientTaxStr = !empty($recipientTaxInfo) ? implode(' | ', $recipientTaxInfo) : '';

        $dueDateHtml = '';
        if ($model->getDueDate() !== null) {
            $dueDateFormatted = DocumentLocalization::formatDate($model->getDueDate(), $loc);
            $dueDateHtml = <<<HTML
            <div class="meta-row">
                <span class="meta-label">{$e(DocumentLocalization::trans('due_date', $loc))}:</span>
                <span class="meta-value font-semibold">{$dueDateFormatted}</span>
            </div>
HTML;
        }

        $subtotalFormatted = DocumentLocalization::formatMoney($totals->getSubtotal(), $currencySymbol, $loc);
        $taxTotalFormatted = DocumentLocalization::formatMoney($totals->getTaxTotal(), $currencySymbol, $loc);
        $grandTotalFormatted = DocumentLocalization::formatMoney($totals->getGrandTotal(), $currencySymbol, $loc);
        $paidFormatted = DocumentLocalization::formatMoney($totals->getPaidAmount(), $currencySymbol, $loc);
        $balanceFormatted = DocumentLocalization::formatMoney($totals->getBalanceDue(), $currencySymbol, $loc);

        $discountRowHtml = '';
        if ($totals->getDiscountTotal() > 0) {
            $discountFormatted = DocumentLocalization::formatMoney($totals->getDiscountTotal(), $currencySymbol, $loc);
            $discountRowHtml = <<<HTML
            <div class="summary-row text-muted">
                <span>{$e(DocumentLocalization::trans('discount_total', $loc))}:</span>
                <span>-{$discountFormatted}</span>
            </div>
HTML;
        }

        $issueDateFormatted = DocumentLocalization::formatDate($model->getIssueDate(), $loc);

        return <<<HTML
<!DOCTYPE html>
<html lang="{$e($loc)}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$e($typeTitle)} - {$e($model->getDocumentNumber())}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            background-color: #ffffff;
            font-size: 13px;
            line-height: 1.5;
            padding: 24px;
        }
        .document-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header-grid {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
        }
        .brand-col {
            max-width: 50%;
        }
        .brand-name {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .brand-logo {
            max-height: 60px;
            max-width: 220px;
            object-fit: contain;
        }
        .doc-meta-col {
            text-align: right;
        }
        .doc-title {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-number {
            font-size: 15px;
            font-weight: 600;
            color: #475569;
            margin-top: 2px;
        }
        .status-badge {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #ffffff;
            background-color: {$statusColor};
            text-transform: uppercase;
        }
        .parties-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 28px;
            gap: 24px;
        }
        .party-card {
            flex: 1;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }
        .party-heading {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .party-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .dates-box {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed #cbd5e1;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            margin-bottom: 3px;
        }
        .meta-label {
            color: #64748b;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            page-break-inside: auto;
        }
        .items-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e1;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            page-break-inside: avoid;
        }
        .col-desc { width: 40%; text-align: left; }
        .col-qty { width: 10%; }
        .col-price { width: 15%; }
        .col-discount { width: 10%; }
        .col-tax { width: 12%; }
        .col-total { width: 13%; }
        .item-name {
            font-weight: 600;
            color: #0f172a;
        }
        .summary-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 28px;
        }
        .summary-card {
            width: 320px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .summary-total {
            border-top: 2px solid #cbd5e1;
            padding-top: 8px;
            margin-top: 8px;
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }
        .summary-balance {
            background-color: #fef2f2;
            border-radius: 4px;
            padding: 6px 8px;
            margin-top: 8px;
            color: #b91c1c;
            font-weight: 700;
        }
        .bank-accounts, .notes-section, .terms-section {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        .bank-card {
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .bank-card:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .verification-badge {
            margin-top: 24px;
            padding: 10px;
            background-color: #f8fafc;
            border-radius: 6px;
            border: 1px dashed #cbd5e1;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-muted { color: #64748b; }
        .text-sm { font-size: 12px; }
        .text-xs { font-size: 10px; }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: 700; }
        .font-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }

        @media print {
            body { padding: 0; }
            .document-container { max-width: 100%; }
            .party-card, .summary-card, .bank-accounts, .notes-section {
                border-color: #cbd5e1;
            }
        }
    </style>
</head>
<body>
    <div class="document-container">
        <!-- Header -->
        <div class="header-grid">
            <div class="brand-col">
                {$logoHtml}
                <div class="text-sm text-muted" style="margin-top: 6px;">
                    <div>{$e($issuer->getAddressLine1())}</div>
                    <div>{$e($issuer->getCity())} {$e($issuer->getPostalCode())} {$e($issuer->getCountryCode())}</div>
                    <div>{$e($issuerTaxStr)}</div>
                    <div>{$e($issuer->getEmail())} | {$e($issuer->getPhone())}</div>
                </div>
            </div>
            <div class="doc-meta-col">
                <div class="doc-title">{$e($typeTitle)}</div>
                <div class="doc-number">{$e($model->getDocumentNumber())}</div>
                <div class="status-badge">{$e($statusLabel)}</div>
                <div class="dates-box">
                    <div class="meta-row">
                        <span class="meta-label">{$e(DocumentLocalization::trans('issue_date', $loc))}:</span>
                        <span class="meta-value font-semibold">{$issueDateFormatted}</span>
                    </div>
                    {$dueDateHtml}
                </div>
            </div>
        </div>

        <!-- Parties -->
        <div class="parties-grid">
            <div class="party-card">
                <div class="party-heading">{$e(DocumentLocalization::trans('billed_to', $loc))}</div>
                <div class="party-name">{$e($recipient->getDisplayName())}</div>
                <div class="text-sm text-muted">
                    <div>{$e($recipient->getAddressLine1())}</div>
                    <div>{$e($recipient->getCity())} {$e($recipient->getPostalCode())} {$e($recipient->getCountryCode())}</div>
                    <div>{$e($recipientTaxStr)}</div>
                    <div>{$e($recipient->getEmail())} | {$e($recipient->getPhone())}</div>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="col-desc">{$e(DocumentLocalization::trans('description', $loc))}</th>
                    <th class="col-qty text-center">{$e(DocumentLocalization::trans('quantity', $loc))}</th>
                    <th class="col-price text-right">{$e(DocumentLocalization::trans('unit_price', $loc))}</th>
                    <th class="col-discount text-right">{$e(DocumentLocalization::trans('discount', $loc))}</th>
                    <th class="col-tax text-right">{$e(DocumentLocalization::trans('tax_rate', $loc))}</th>
                    <th class="col-total text-right">{$e(DocumentLocalization::trans('amount', $loc))}</th>
                </tr>
            </thead>
            <tbody>
                {$itemsHtml}
            </tbody>
        </table>

        <!-- Summary -->
        <div class="summary-container">
            <div class="summary-card">
                <div class="summary-row">
                    <span>{$e(DocumentLocalization::trans('subtotal', $loc))}:</span>
                    <span>{$subtotalFormatted}</span>
                </div>
                {$discountRowHtml}
                {$taxBreakdownHtml}
                <div class="summary-row summary-total">
                    <span>{$e(DocumentLocalization::trans('grand_total', $loc))}:</span>
                    <span>{$grandTotalFormatted}</span>
                </div>
                <div class="summary-row text-muted text-sm" style="margin-top: 6px;">
                    <span>{$e(DocumentLocalization::trans('amount_paid', $loc))}:</span>
                    <span>{$paidFormatted}</span>
                </div>
                <div class="summary-row summary-balance">
                    <span>{$e(DocumentLocalization::trans('balance_due', $loc))}:</span>
                    <span>{$balanceFormatted}</span>
                </div>
            </div>
        </div>

        <!-- Payment & Bank Accounts -->
        {$bankAccountsHtml}

        <!-- Notes & Terms -->
        {$notesHtml}
        {$termsHtml}

        <!-- Verification -->
        {$verificationHtml}
    </div>
</body>
</html>
HTML;
    }
}
