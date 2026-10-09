<?php

declare(strict_types=1);

namespace Coleza\Domain\Abuse;

enum AbuseSeverity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function getDefaultDeadlineHours(): int
    {
        return match ($this) {
            self::CRITICAL => 4,
            self::HIGH => 24,
            self::MEDIUM => 48,
            self::LOW => 72,
        };
    }

    public function isEmergency(): bool
    {
        return $this === self::CRITICAL;
    }
}
