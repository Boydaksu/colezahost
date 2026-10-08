<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Conditions;

final class ConditionEvaluator
{
    /**
     * Compare actual and expected values using the specified operator.
     */
    public static function compare(mixed $actual, ConditionOperator $operator, mixed $expected): bool
    {
        return match ($operator) {
            ConditionOperator::EQUALS => self::equals($actual, $expected),
            ConditionOperator::NOT_EQUALS => !self::equals($actual, $expected),
            ConditionOperator::GREATER_THAN => self::greaterThan($actual, $expected),
            ConditionOperator::GREATER_THAN_OR_EQUAL => self::greaterThanOrEqual($actual, $expected),
            ConditionOperator::LESS_THAN => self::lessThan($actual, $expected),
            ConditionOperator::LESS_THAN_OR_EQUAL => self::lessThanOrEqual($actual, $expected),
            ConditionOperator::CONTAINS => self::contains($actual, $expected),
            ConditionOperator::NOT_CONTAINS => !self::contains($actual, $expected),
            ConditionOperator::STARTS_WITH => self::startsWith($actual, $expected),
            ConditionOperator::ENDS_WITH => self::endsWith($actual, $expected),
            ConditionOperator::IN => self::in($actual, $expected),
            ConditionOperator::NOT_IN => !self::in($actual, $expected),
            ConditionOperator::IS_NULL => $actual === null,
            ConditionOperator::IS_NOT_NULL => $actual !== null,
            ConditionOperator::MATCHES_REGEX => self::matchesRegex($actual, (string) $expected),
            ConditionOperator::BETWEEN => self::between($actual, $expected),
        };
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === (bool) $expected;
        }

        return $actual === $expected;
    }

    private static function greaterThan(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual > (float) $expected;
        }

        return strcmp((string) $actual, (string) $expected) > 0;
    }

    private static function greaterThanOrEqual(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual >= (float) $expected;
        }

        return strcmp((string) $actual, (string) $expected) >= 0;
    }

    private static function lessThan(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual < (float) $expected;
        }

        return strcmp((string) $actual, (string) $expected) < 0;
    }

    private static function lessThanOrEqual(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <= (float) $expected;
        }

        return strcmp((string) $actual, (string) $expected) <= 0;
    }

    private static function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, false);
        }

        if (is_string($actual) || is_numeric($actual)) {
            return str_contains((string) $actual, (string) $expected);
        }

        return false;
    }

    private static function startsWith(mixed $actual, mixed $expected): bool
    {
        return str_starts_with((string) $actual, (string) $expected);
    }

    private static function endsWith(mixed $actual, mixed $expected): bool
    {
        return str_ends_with((string) $actual, (string) $expected);
    }

    private static function in(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected)) {
            return in_array($actual, $expected, false);
        }

        if (is_string($expected)) {
            $items = array_map('trim', explode(',', $expected));
            return in_array((string) $actual, $items, true);
        }

        return false;
    }

    private static function matchesRegex(mixed $actual, string $pattern): bool
    {
        if ($actual === null) {
            return false;
        }

        // Add delimiters if not present
        if (!str_starts_with($pattern, '/') && !str_starts_with($pattern, '#')) {
            $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
        }

        $result = @preg_match($pattern, (string) $actual);
        return $result === 1;
    }

    private static function between(mixed $actual, mixed $expected): bool
    {
        if (!is_array($expected) || count($expected) < 2) {
            return false;
        }

        $min = $expected[0];
        $max = $expected[1];

        if (is_numeric($actual) && is_numeric($min) && is_numeric($max)) {
            $val = (float) $actual;
            return $val >= (float) $min && $val <= (float) $max;
        }

        return strcmp((string) $actual, (string) $min) >= 0 && strcmp((string) $actual, (string) $max) <= 0;
    }
}
