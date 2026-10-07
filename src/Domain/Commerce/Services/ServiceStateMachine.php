<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

use Coleza\Foundation\Exceptions\ValidationException;

final class ServiceStateMachine
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_FRAUD = 'fraud';

    /**
     * @var array<string, array<string>>
     */
    private static array $validTransitions = [
        self::STATUS_PENDING => [
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED,
            self::STATUS_FRAUD,
        ],
        self::STATUS_ACTIVE => [
            self::STATUS_SUSPENDED,
            self::STATUS_CANCELLED,
            self::STATUS_TERMINATED,
        ],
        self::STATUS_SUSPENDED => [
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED,
            self::STATUS_TERMINATED,
        ],
        self::STATUS_CANCELLED => [
            self::STATUS_ACTIVE,
            self::STATUS_TERMINATED,
        ],
        self::STATUS_TERMINATED => [], // Terminal state
        self::STATUS_FRAUD => [],      // Terminal state
    ];

    public static function canTransition(string $fromStatus, string $toStatus): bool
    {
        $allowed = self::$validTransitions[$fromStatus] ?? [];
        return in_array($toStatus, $allowed, true);
    }

    public static function assertCanTransition(string $fromStatus, string $toStatus): void
    {
        if (!self::canTransition($fromStatus, $toStatus)) {
            throw new ValidationException(
                ['status' => "Cannot transition service status from '{$fromStatus}' to '{$toStatus}'."],
                'Invalid service state transition'
            );
        }
    }

    /**
     * @return array<string>
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
            self::STATUS_SUSPENDED,
            self::STATUS_CANCELLED,
            self::STATUS_TERMINATED,
            self::STATUS_FRAUD,
        ];
    }
}
