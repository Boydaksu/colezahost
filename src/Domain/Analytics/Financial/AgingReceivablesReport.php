<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Financial;

use JsonSerializable;

/**
 * Aging breakdown of outstanding receivables categorized by days overdue.
 */
final class AgingReceivablesReport implements JsonSerializable
{
    public function __construct(
        private readonly string $asOfDate,
        private readonly string $currency,
        private readonly float $totalOutstanding,
        private readonly float $currentBucket, // 0-30 days overdue or not yet due
        private readonly float $thirtyToSixtyBucket, // 31-60 days
        private readonly float $sixtyToNinetyBucket, // 61-90 days
        private readonly float $overNinetyBucket, // 90+ days
        private readonly int $overdueInvoicesCount
    ) {
    }

    public function getAsOfDate(): string
    {
        return $this->asOfDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTotalOutstanding(): float
    {
        return $this->totalOutstanding;
    }

    public function getCurrentBucket(): float
    {
        return $this->currentBucket;
    }

    public function getThirtyToSixtyBucket(): float
    {
        return $this->thirtyToSixtyBucket;
    }

    public function getSixtyToNinetyBucket(): float
    {
        return $this->sixtyToNinetyBucket;
    }

    public function getOverNinetyBucket(): float
    {
        return $this->overNinetyBucket;
    }

    public function getOverdueInvoicesCount(): int
    {
        return $this->overdueInvoicesCount;
    }

    public function toArray(): array
    {
        return [
            'as_of_date' => $this->asOfDate,
            'currency' => $this->currency,
            'total_outstanding' => $this->totalOutstanding,
            'current_bucket' => $this->currentBucket,
            'thirty_to_sixty_bucket' => $this->thirtyToSixtyBucket,
            'sixty_to_ninety_bucket' => $this->sixtyToNinetyBucket,
            'over_ninety_bucket' => $this->overNinetyBucket,
            'overdue_invoices_count' => $this->overdueInvoicesCount,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
