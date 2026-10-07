<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Orders;

use Coleza\Foundation\Exceptions\ValidationException;

final class OrderStateMachine
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FRAUD = 'fraud';

    /**
     * @var array<string, array<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PENDING_PAYMENT => [
            self::STATUS_PROCESSING,
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED,
            self::STATUS_FRAUD,
        ],
        self::STATUS_PROCESSING => [
            self::STATUS_ACTIVE,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_ACTIVE => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_FRAUD => [],
    ];

    public static function canTransition(string $fromStatus, string $toStatus): bool
    {
        $transitions = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        return in_array($toStatus, $transitions, true);
    }

    public static function validateTransition(string $fromStatus, string $toStatus): void
    {
        if (!self::canTransition($fromStatus, $toStatus)) {
            throw new ValidationException(
                ['status' => ["Cannot transition order from status '{$fromStatus}' to '{$toStatus}'."]],
                "Invalid order state transition."
            );
        }
    }
}
