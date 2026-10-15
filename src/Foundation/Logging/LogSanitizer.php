<?php

declare(strict_types=1);
namespace Coleza\Foundation\Logging;

/** Backward-compatible diagnostic facade over the shared secret redactor. */
final class LogSanitizer
{
    public static function sanitize(array $context): array
    {
        return \Coleza\Foundation\Security\SecretRedactor::diagnostic($context);
    }
}
