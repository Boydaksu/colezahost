<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Erasure;

enum ErasureAction: string
{
    case DELETE = 'delete';
    case ANONYMIZE = 'anonymize';
    case RETAIN = 'retain';

    public function isDelete(): bool
    {
        return $this === self::DELETE;
    }

    public function isAnonymize(): bool
    {
        return $this === self::ANONYMIZE;
    }

    public function isRetain(): bool
    {
        return $this === self::RETAIN;
    }
}
