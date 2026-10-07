<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy;

final class DataClassification
{
    public const PUBLIC = 'PUBLIC';
    public const INTERNAL = 'INTERNAL';
    public const CONFIDENTIAL = 'CONFIDENTIAL';
    public const RESTRICTED_PII = 'RESTRICTED_PII';

    /**
     * Map of common platform attributes to GDPR/KVKK classification tags.
     *
     * @var array<string, string>
     */
    private const FIELD_REGISTRY = [
        'users.name' => self::RESTRICTED_PII,
        'users.email' => self::RESTRICTED_PII,
        'users.phone' => self::RESTRICTED_PII,
        'users.address' => self::RESTRICTED_PII,
        'users.tax_number' => self::RESTRICTED_PII,
        'users.identity_number' => self::RESTRICTED_PII,
        'users.password_hash' => self::CONFIDENTIAL,
        'sessions.ip_address' => self::RESTRICTED_PII,
        'sessions.user_agent' => self::INTERNAL,
        'brands.name' => self::PUBLIC,
        'brands.domain' => self::PUBLIC,
        'vault.secret' => self::CONFIDENTIAL,
    ];

    /**
     * Determine sensitivity classification of a resource field.
     */
    public static function classify(string $resourceField): string
    {
        return self::FIELD_REGISTRY[$resourceField] ?? self::INTERNAL;
    }

    /**
     * Determine if field contains PII (Personally Identifiable Information).
     */
    public static function isPii(string $resourceField): bool
    {
        return self::classify($resourceField) === self::RESTRICTED_PII;
    }
}
