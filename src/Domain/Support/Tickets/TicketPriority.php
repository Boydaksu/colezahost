<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Tickets;

use Coleza\Foundation\Exceptions\ValidationException;

final class TicketPriority
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::LOW,
            self::MEDIUM,
            self::HIGH,
            self::CRITICAL,
        ];
    }

    public static function isValid(string $priority): bool
    {
        return in_array($priority, self::all(), true);
    }

    public static function assertValid(string $priority): void
    {
        if (!self::isValid($priority)) {
            throw new ValidationException(
                ['priority' => "Invalid ticket priority: '{$priority}'"],
                'Invalid ticket priority'
            );
        }
    }

    public static function getWeight(string $priority): int
    {
        return match ($priority) {
            self::CRITICAL => 40,
            self::HIGH => 30,
            self::MEDIUM => 20,
            self::LOW => 10,
            default => 0,
        };
    }

    public static function isHigherThan(string $p1, string $p2): bool
    {
        return self::getWeight($p1) > self::getWeight($p2);
    }

    public static function getDefaultFirstResponseMinutes(string $priority): int
    {
        return match ($priority) {
            self::CRITICAL => 60,
            self::HIGH => 240,
            self::MEDIUM => 720,
            self::LOW => 1440,
            default => 720,
        };
    }

    public static function getDefaultResolutionMinutes(string $priority): int
    {
        return match ($priority) {
            self::CRITICAL => 240,
            self::HIGH => 720,
            self::MEDIUM => 1440,
            self::LOW => 2880,
            default => 1440,
        };
    }

    public static function getLabel(string $priority, string $locale = 'en'): string
    {
        $labelsEn = [
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::CRITICAL => 'Critical',
        ];

        $labelsTr = [
            self::LOW => 'Düşük',
            self::MEDIUM => 'Orta',
            self::HIGH => 'Yüksek',
            self::CRITICAL => 'Kritik',
        ];

        $map = ($locale === 'tr') ? $labelsTr : $labelsEn;

        return $map[$priority] ?? ucfirst($priority);
    }

    public static function getBadgeClass(string $priority): string
    {
        return match ($priority) {
            self::CRITICAL => 'badge-danger',
            self::HIGH => 'badge-warning',
            self::MEDIUM => 'badge-info',
            self::LOW => 'badge-secondary',
            default => 'badge-light',
        };
    }
}
