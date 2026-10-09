<?php

declare(strict_types=1);

namespace Coleza\Domain\Health;

enum HealthStatus: string
{
    case HEALTHY = 'healthy';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function isPassing(): bool
    {
        return $this === self::HEALTHY || $this === self::WARNING;
    }
}
