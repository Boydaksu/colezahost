<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Subscription;

final class MrrCalculator
{
    /**
     * Normalizes a recurring amount and billing cycle into an accurate Monthly Recurring Revenue (MRR).
     *
     * @param float $recurringAmount The recurring price charged per cycle
     * @param string $billingCycle Canonical billing cycle (monthly, quarterly, semi_annually, annually, etc.)
     * @return float Normalized monthly value rounded to 2 decimal places
     */
    public static function normalizeToMonthly(float $recurringAmount, string $billingCycle): float
    {
        if ($recurringAmount <= 0.0) {
            return 0.0;
        }

        $cycle = strtolower(trim($billingCycle));

        $monthlyAmount = match ($cycle) {
            'monthly' => $recurringAmount,
            'quarterly' => $recurringAmount / 3.0,
            'semi_annually', 'semiannually', 'half_yearly' => $recurringAmount / 6.0,
            'annually', 'yearly' => $recurringAmount / 12.0,
            'biennially', 'two_yearly' => $recurringAmount / 24.0,
            'triennially', 'three_yearly' => $recurringAmount / 36.0,
            'one_time', 'onetime', 'free' => 0.0,
            default => $recurringAmount, // fallback to direct value
        };

        return round($monthlyAmount, 2);
    }

    /**
     * Annualizes an MRR amount into ARR.
     */
    public static function toArr(float $mrr): float
    {
        return round($mrr * 12.0, 2);
    }
}
