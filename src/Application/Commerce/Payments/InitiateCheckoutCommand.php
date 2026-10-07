<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Payments;

final class InitiateCheckoutCommand
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $buyerId,
        public readonly string $buyerName,
        public readonly string $buyerSurname,
        public readonly string $buyerEmail,
        public readonly string $buyerIp,
        public readonly string $callbackUrl,
        public readonly ?string $buyerGsm = null,
        public readonly string $buyerCity = 'Istanbul',
        public readonly string $buyerCountry = 'Turkey',
        public readonly string $buyerAddress = 'Default Address'
    ) {
    }
}
