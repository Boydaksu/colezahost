<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

use Coleza\Domain\Documents\DocumentType;

final class DocumentLocalization
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $translations = [
        'en' => [
            'invoice' => 'Invoice',
            'quote' => 'Quote',
            'proforma' => 'Proforma Invoice',
            'receipt' => 'Payment Receipt',
            'credit_note' => 'Credit Note',
            'contract' => 'Service Agreement',
            'document_no' => 'Document No',
            'issue_date' => 'Issue Date',
            'due_date' => 'Due Date',
            'paid_date' => 'Payment Date',
            'status' => 'Status',
            'billed_by' => 'Billed By',
            'billed_to' => 'Billed To',
            'tax_number' => 'Tax No / VAT',
            'tax_office' => 'Tax Office',
            'phone' => 'Phone',
            'email' => 'Email',
            'description' => 'Description',
            'unit_price' => 'Unit Price',
            'quantity' => 'Qty',
            'tax_rate' => 'Tax %',
            'tax_amount' => 'Tax Amount',
            'discount' => 'Discount',
            'amount' => 'Amount',
            'subtotal' => 'Subtotal',
            'discount_total' => 'Total Discount',
            'taxable_amount' => 'Taxable Amount',
            'total_tax' => 'Total Tax',
            'grand_total' => 'Grand Total',
            'amount_paid' => 'Amount Paid',
            'balance_due' => 'Balance Due',
            'payment_details' => 'Payment & Bank Details',
            'notes' => 'Notes',
            'terms' => 'Terms & Conditions',
            'status_paid' => 'PAID',
            'status_unpaid' => 'UNPAID',
            'status_cancelled' => 'CANCELLED',
            'status_draft' => 'DRAFT',
            'status_sent' => 'SENT',
            'status_accepted' => 'ACCEPTED',
            'status_rejected' => 'REJECTED',
            'status_expired' => 'EXPIRED',
            'bank_name' => 'Bank',
            'account_holder' => 'Account Holder',
            'iban' => 'IBAN',
            'swift' => 'SWIFT / BIC',
            'page' => 'Page',
            'of' => 'of',
            'verification' => 'Scan or visit to verify document',
        ],
        'tr' => [
            'invoice' => 'Fatura',
            'quote' => 'Fiyat Teklifi',
            'proforma' => 'Proforma Fatura',
            'receipt' => 'Tahsilat Makbuzu',
            'credit_note' => 'Alacak Dekontu',
            'contract' => 'Hizmet Sözleşmesi',
            'document_no' => 'Belge No',
            'issue_date' => 'Düzenleme Tarihi',
            'due_date' => 'Son Ödeme Tarihi',
            'paid_date' => 'Ödeme Tarihi',
            'status' => 'Durum',
            'billed_by' => 'Düzenleyen',
            'billed_to' => 'Sayın (Alıcı)',
            'tax_number' => 'Vergi No / TCKN',
            'tax_office' => 'Vergi Dairesi',
            'phone' => 'Telefon',
            'email' => 'E-Posta',
            'description' => 'Hizmet / Ürün Açıklaması',
            'unit_price' => 'Birim Fiyat',
            'quantity' => 'Miktar',
            'tax_rate' => 'KDV %',
            'tax_amount' => 'KDV Tutarı',
            'discount' => 'İndirim',
            'amount' => 'Tutar',
            'subtotal' => 'Ara Toplam',
            'discount_total' => 'Toplam İndirim',
            'taxable_amount' => 'Matrah',
            'total_tax' => 'Hesaplanan KDV',
            'grand_total' => 'Genel Toplam',
            'amount_paid' => 'Ödenen Tutar',
            'balance_due' => 'Kalan Tutar',
            'payment_details' => 'Ödeme ve Banka Bilgileri',
            'notes' => 'Notlar',
            'terms' => 'Şartlar ve Koşullar',
            'status_paid' => 'ÖDENDİ',
            'status_unpaid' => 'ÖDENMEDİ',
            'status_cancelled' => 'İPTAL EDİLDİ',
            'status_draft' => 'TASLAK',
            'status_sent' => 'GÖNDERİLDİ',
            'status_accepted' => 'KABUL EDİLDİ',
            'status_rejected' => 'REDDEDİLDİ',
            'status_expired' => 'SÜRESİ DOLDU',
            'bank_name' => 'Banka',
            'account_holder' => 'Hesap Sahibi',
            'iban' => 'IBAN',
            'swift' => 'SWIFT / BIC',
            'page' => 'Sayfa',
            'of' => '/',
            'verification' => 'Belge doğrulama için karekodu okutunuz',
        ],
    ];

    public static function trans(string $key, string $locale = 'en'): string
    {
        $lang = strtolower($locale) === 'tr' ? 'tr' : 'en';

        return self::$translations[$lang][$key] ?? self::$translations['en'][$key] ?? $key;
    }

    public static function formatMoney(float $amount, string $currencySymbol, string $locale = 'en'): string
    {
        $lang = strtolower($locale) === 'tr' ? 'tr' : 'en';
        if ($lang === 'tr') {
            $formatted = number_format($amount, 2, ',', '.');
            return $formatted . ' ' . $currencySymbol;
        }

        $formatted = number_format($amount, 2, '.', ',');
        return $currencySymbol . $formatted;
    }

    public static function formatDate(?string $date, string $locale = 'en'): string
    {
        if ($date === null || trim($date) === '') {
            return '-';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $lang = strtolower($locale) === 'tr' ? 'tr' : 'en';
        if ($lang === 'tr') {
            return date('d.m.Y', $timestamp);
        }

        return date('Y-m-d', $timestamp);
    }
}
