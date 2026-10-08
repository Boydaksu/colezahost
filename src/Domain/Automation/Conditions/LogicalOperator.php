<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Conditions;

enum LogicalOperator: string
{
    case AND = 'AND';
    case OR = 'OR';

    public static function fromString(string $operator): self
    {
        $normalized = strtoupper(trim($operator));
        return match ($normalized) {
            'AND', '&&' => self::AND,
            'OR', '||' => self::OR,
            default => self::from($normalized),
        };
    }
}
