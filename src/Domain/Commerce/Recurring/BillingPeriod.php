<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring;

use Coleza\Domain\Pricing\Entities\PriceCycle;
use DateTimeImmutable;

final class BillingPeriod
{
    /**
     * Calculate next due date from a base date and billing cycle.
     */
    public static function calculateNextDueDate(string $baseDate, string $cycle): string
    {
        $dt = new DateTimeImmutable($baseDate);
        $months = PriceCycle::getMonths($cycle);
        if ($months <= 0) {
            return $baseDate;
        }

        return $dt->modify("+{$months} month")->format('Y-m-d');
    }

    /**
     * Compute [period_start, period_end] string pair.
     *
     * @return array{start: string, end: string}
     */
    public static function computePeriod(string $startDate, string $cycle): array
    {
        $start = new DateTimeImmutable($startDate);
        $months = PriceCycle::getMonths($cycle);
        if ($months <= 0) {
            return [
                'start' => $startDate,
                'end' => $startDate,
            ];
        }

        $end = $start->modify("+{$months} month");
        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    /**
     * Determine if a renewal should be generated given the service next due date,
     * target reference date (default today), and lead days.
     */
    public static function isDueForRenewal(string $nextDueDate, string $referenceDate, int $leadDays = 14): bool
    {
        $due = new DateTimeImmutable($nextDueDate);
        $ref = new DateTimeImmutable($referenceDate);
        $cutoff = $due->modify("-{$leadDays} days");

        return $ref >= $cutoff;
    }
}
