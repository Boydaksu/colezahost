<?php

declare(strict_types=1);

namespace Coleza\Foundation\Logging;

final class LogSanitizer
{
    private const array SENSITIVE_KEYWORDS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth_header',
        'credit_card',
        'card_number',
        'pan',
        'cvv',
        'cvc',
        'private_key',
    ];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $sanitized[$key] = '********';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value);
            } elseif (is_string($value) && self::looksLikeJson($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $sanitized[$key] = json_encode(self::sanitize($decoded));
                } else {
                    $sanitized[$key] = $value;
                }
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));
        foreach (self::SENSITIVE_KEYWORDS as $kw) {
            $normalizedKw = str_replace('_', '', $kw);
            if (str_contains($normalized, $normalizedKw)) {
                return true;
            }
        }
        return false;
    }

    private static function looksLikeJson(string $str): bool
    {
        $str = trim($str);
        return (str_starts_with($str, '{') && str_ends_with($str, '}'))
            || (str_starts_with($str, '[') && str_ends_with($str, ']'));
    }
}
