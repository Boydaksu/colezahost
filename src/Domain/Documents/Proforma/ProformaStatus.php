<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Proforma;

enum ProformaStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case PAID = 'paid';
    case CONVERTED = 'converted';
    case CANCELLED = 'cancelled';

    public function isPaidOrConverted(): bool
    {
        return in_array($this, [self::PAID, self::CONVERTED], true);
    }
}
