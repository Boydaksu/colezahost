<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Retention;

enum LegalHoldScopeType: string
{
    case USER = 'user';
    case ORGANIZATION = 'organization';
    case RESOURCE = 'resource';
    case GLOBAL = 'global';

    public function isUser(): bool
    {
        return $this === self::USER;
    }

    public function isGlobal(): bool
    {
        return $this === self::GLOBAL;
    }
}
