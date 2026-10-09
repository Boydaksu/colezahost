<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Lists;

enum FraudListType: string
{
    case ALLOW = 'allow';
    case WATCH = 'watch';
    case DENY = 'deny';

    public function isAllow(): bool
    {
        return $this === self::ALLOW;
    }

    public function isWatch(): bool
    {
        return $this === self::WATCH;
    }

    public function isDeny(): bool
    {
        return $this === self::DENY;
    }
}
