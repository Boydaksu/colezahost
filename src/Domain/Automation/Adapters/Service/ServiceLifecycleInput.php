<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Adapters\Service;
final class ServiceLifecycleInput
{
    public static function serviceId(mixed $value): int { return self::integer($value, false, 'service_id'); }
    public static function lockVersion(mixed $value): ?int { return $value === null ? null : self::integer($value, true, 'expected_lock_version'); }
    private static function integer(mixed $value, bool $zeroAllowed, string $field): int
    {
        if (is_int($value) && $value >= ($zeroAllowed ? 0 : 1)) { return $value; }
        if (is_string($value) && preg_match($zeroAllowed ? '/^(0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $value)
            && (string) (int) $value === $value) { return (int) $value; }
        throw new \InvalidArgumentException($field . ' must be a canonical integer within the supported range.');
    }
}
