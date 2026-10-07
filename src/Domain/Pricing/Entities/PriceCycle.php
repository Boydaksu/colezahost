<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Entities;

final class PriceCycle
{
    public const ONE_TIME = 'one_time';
    public const MONTHLY = 'monthly';
    public const QUARTERLY = 'quarterly';
    public const SEMI_ANNUALLY = 'semi_annually';
    public const ANNUALLY = 'annually';
    public const BIENNIALLY = 'biennially';
    public const TRIENNIALLY = 'triennially';

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::ONE_TIME,
            self::MONTHLY,
            self::QUARTERLY,
            self::SEMI_ANNUALLY,
            self::ANNUALLY,
            self::BIENNIALLY,
            self::TRIENNIALLY,
        ];
    }

    public static function isValid(string $cycle): bool
    {
        return in_array($cycle, self::all(), true);
    }

    /**
     * Number of months represented by this cycle. 0 for one-time.
     */
    public static function getMonths(string $cycle): int
    {
        return match ($cycle) {
            self::MONTHLY => 1,
            self::QUARTERLY => 3,
            self::SEMI_ANNUALLY => 6,
            self::ANNUALLY => 12,
            self::BIENNIALLY => 24,
            self::TRIENNIALLY => 36,
            default => 0,
        };
    }
}
