<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains;

use Coleza\Foundation\Exceptions\ValidationException;

final class DomainStateMachine
{
    public const STATUS_PENDING_REGISTRATION = 'pending_registration';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_TRANSFER = 'pending_transfer';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_GRACE = 'grace';
    public const STATUS_REDEMPTION = 'redemption';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_TRANSFERRED_OUT = 'transferred_out';

    /**
     * State transition matrix: current_state => array of allowed target states.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING_REGISTRATION => [
            self::STATUS_ACTIVE,
            self::STATUS_PENDING_TRANSFER,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PENDING_TRANSFER => [
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED,
            self::STATUS_TRANSFERRED_OUT,
        ],
        self::STATUS_ACTIVE => [
            self::STATUS_ACTIVE, // renewal retains active
            self::STATUS_PENDING_TRANSFER,
            self::STATUS_EXPIRED,
            self::STATUS_GRACE,
            self::STATUS_REDEMPTION,
            self::STATUS_CANCELLED,
            self::STATUS_TRANSFERRED_OUT,
        ],
        self::STATUS_EXPIRED => [
            self::STATUS_ACTIVE, // renewal/re-activation
            self::STATUS_GRACE,
            self::STATUS_REDEMPTION,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_GRACE => [
            self::STATUS_ACTIVE, // renewed during grace period
            self::STATUS_REDEMPTION,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_REDEMPTION => [
            self::STATUS_ACTIVE, // restored during redemption
            self::STATUS_CANCELLED,
        ],
        self::STATUS_CANCELLED => [],
        self::STATUS_TRANSFERRED_OUT => [],
    ];

    public static function canTransition(string $fromStatus, string $toStatus): bool
    {
        $from = strtolower(trim($fromStatus));
        $to = strtolower(trim($toStatus));

        if ($from === $to) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    public static function assertCanTransition(string $fromStatus, string $toStatus): void
    {
        if (!self::canTransition($fromStatus, $toStatus)) {
            throw new ValidationException(
                ['status' => "Cannot transition domain from status '{$fromStatus}' to '{$toStatus}'."],
                'Invalid domain status transition'
            );
        }
    }

    public static function isOperable(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            self::STATUS_ACTIVE,
            self::STATUS_GRACE,
        ], true);
    }

    public static function isPending(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            self::STATUS_PENDING_REGISTRATION,
            self::STATUS_PENDING_TRANSFER,
        ], true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            self::STATUS_CANCELLED,
            self::STATUS_TRANSFERRED_OUT,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING_REGISTRATION,
            self::STATUS_ACTIVE,
            self::STATUS_PENDING_TRANSFER,
            self::STATUS_EXPIRED,
            self::STATUS_GRACE,
            self::STATUS_REDEMPTION,
            self::STATUS_CANCELLED,
            self::STATUS_TRANSFERRED_OUT,
        ];
    }
}
