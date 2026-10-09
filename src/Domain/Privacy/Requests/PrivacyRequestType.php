<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Requests;

enum PrivacyRequestType: string
{
    case EXPORT = 'export';
    case ERASURE = 'erasure';
    case RESTRICTION = 'restriction';
    case RECTIFICATION = 'rectification';

    public function isExport(): bool
    {
        return $this === self::EXPORT;
    }

    public function isErasure(): bool
    {
        return $this === self::ERASURE;
    }

    public function isRestriction(): bool
    {
        return $this === self::RESTRICTION;
    }
}
