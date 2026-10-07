<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents;

enum DocumentType: string
{
    case INVOICE = 'invoice';
    case QUOTE = 'quote';
    case PROFORMA = 'proforma';
    case RECEIPT = 'receipt';
    case CREDIT_NOTE = 'credit_note';
    case CONTRACT = 'contract';

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::INVOICE => $locale === 'tr' ? 'Fatura' : 'Invoice',
            self::QUOTE => $locale === 'tr' ? 'Fiyat Teklifi' : 'Quote',
            self::PROFORMA => $locale === 'tr' ? 'Proforma Fatura' : 'Proforma Invoice',
            self::RECEIPT => $locale === 'tr' ? 'Ödeme Makbuzu' : 'Payment Receipt',
            self::CREDIT_NOTE => $locale === 'tr' ? 'Alacak Dekontu' : 'Credit Note',
            self::CONTRACT => $locale === 'tr' ? 'Hizmet Sözleşmesi' : 'Service Agreement',
        };
    }
}
