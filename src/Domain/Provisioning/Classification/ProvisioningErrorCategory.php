<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Classification;

final class ProvisioningErrorCategory
{
    public const TRANSIENT_NETWORK = 'transient_network';
    public const RATE_LIMITED = 'rate_limited';
    public const AUTHENTICATION = 'authentication';
    public const RESOURCE_EXHAUSTED = 'resource_exhausted';
    public const VALIDATION = 'validation';
    public const CONFLICT = 'conflict';
    public const PROVIDER_FAULT = 'provider_fault';
    public const UNKNOWN = 'unknown';

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::TRANSIENT_NETWORK,
            self::RATE_LIMITED,
            self::AUTHENTICATION,
            self::RESOURCE_EXHAUSTED,
            self::VALIDATION,
            self::CONFLICT,
            self::PROVIDER_FAULT,
            self::UNKNOWN,
        ];
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    public static function isRetryable(string $category): bool
    {
        return match ($category) {
            self::TRANSIENT_NETWORK, self::RATE_LIMITED => true,
            default => false,
        };
    }
}
