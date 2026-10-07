<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Payments;

final class RecordManualPaymentCommand
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $invoiceId,
        public readonly int $amountMinor,
        public readonly string $currencyCode = 'TRY',
        public readonly string $paymentMethod = 'bank_transfer',
        public readonly ?string $transactionReference = null,
        public readonly ?string $proofDocumentUrl = null,
        public readonly ?string $notes = null,
        public readonly array $metadata = []
    ) {
    }
}
