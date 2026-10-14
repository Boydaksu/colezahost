<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
/** Explicit, purpose-separated synthetic keys. Never a production fallback. */
final class PrivacyFixtureKeys
{
    public static function tombstone(): string { return hash('sha256', 'Coleza test only/privacy/tombstone', true); }
    public static function redaction(): string { return hash('sha256', 'Coleza test only/privacy/redaction', true); }
    public static function email(): string { return hash('sha256', 'Coleza test only/privacy/email', true); }
}
