<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Conditions;

enum ConditionOperator: string
{
    case EQUALS = 'eq';
    case NOT_EQUALS = 'neq';
    case GREATER_THAN = 'gt';
    case GREATER_THAN_OR_EQUAL = 'gte';
    case LESS_THAN = 'lt';
    case LESS_THAN_OR_EQUAL = 'lte';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case STARTS_WITH = 'starts_with';
    case ENDS_WITH = 'ends_with';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case IS_NULL = 'is_null';
    case IS_NOT_NULL = 'is_not_null';
    case MATCHES_REGEX = 'regex';
    case BETWEEN = 'between';

    public static function fromString(string $operator): self
    {
        $normalized = strtolower(trim($operator));
        return match ($normalized) {
            'eq', '=', '==', 'equals' => self::EQUALS,
            'neq', '!=', '<>', 'not_equals' => self::NOT_EQUALS,
            'gt', '>', 'greater_than' => self::GREATER_THAN,
            'gte', '>=', 'greater_than_or_equal' => self::GREATER_THAN_OR_EQUAL,
            'lt', '<', 'less_than' => self::LESS_THAN,
            'lte', '<=', 'less_than_or_equal' => self::LESS_THAN_OR_EQUAL,
            'contains' => self::CONTAINS,
            'not_contains' => self::NOT_CONTAINS,
            'starts_with' => self::STARTS_WITH,
            'ends_with' => self::ENDS_WITH,
            'in' => self::IN,
            'not_in' => self::NOT_IN,
            'is_null', 'null' => self::IS_NULL,
            'is_not_null', 'not_null' => self::IS_NOT_NULL,
            'regex', 'matches_regex' => self::MATCHES_REGEX,
            'between' => self::BETWEEN,
            default => self::from($normalized),
        };
    }
}
