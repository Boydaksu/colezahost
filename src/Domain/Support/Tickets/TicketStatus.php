<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Tickets;

use Coleza\Foundation\Exceptions\ValidationException;

final class TicketStatus
{
    public const OPEN = 'open';
    public const CUSTOMER_REPLY = 'customer_reply';
    public const IN_PROGRESS = 'in_progress';
    public const ANSWERED = 'answered';
    public const ON_HOLD = 'on_hold';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::OPEN,
            self::CUSTOMER_REPLY,
            self::IN_PROGRESS,
            self::ANSWERED,
            self::ON_HOLD,
            self::RESOLVED,
            self::CLOSED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, [
            self::OPEN,
            self::CUSTOMER_REPLY,
            self::IN_PROGRESS,
            self::ANSWERED,
            self::ON_HOLD,
        ], true);
    }

    public static function isWaitingStaff(string $status): bool
    {
        return in_array($status, [
            self::OPEN,
            self::CUSTOMER_REPLY,
        ], true);
    }

    public static function isWaitingCustomer(string $status): bool
    {
        return $status === self::ANSWERED;
    }

    public static function isPaused(string $status): bool
    {
        return $status === self::ON_HOLD;
    }

    public static function isResolved(string $status): bool
    {
        return $status === self::RESOLVED;
    }

    public static function isClosed(string $status): bool
    {
        return $status === self::CLOSED;
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::CLOSED;
    }

    /**
     * Allowed target transitions from a given status.
     *
     * @return array<int, string>
     */
    public static function allowedTransitions(string $from): array
    {
        if (!self::isValid($from)) {
            return [];
        }

        return match ($from) {
            self::OPEN => [
                self::OPEN,
                self::CUSTOMER_REPLY,
                self::IN_PROGRESS,
                self::ANSWERED,
                self::ON_HOLD,
                self::RESOLVED,
                self::CLOSED,
            ],
            self::CUSTOMER_REPLY => [
                self::CUSTOMER_REPLY,
                self::IN_PROGRESS,
                self::ANSWERED,
                self::ON_HOLD,
                self::RESOLVED,
                self::CLOSED,
            ],
            self::IN_PROGRESS => [
                self::IN_PROGRESS,
                self::CUSTOMER_REPLY,
                self::ANSWERED,
                self::ON_HOLD,
                self::RESOLVED,
                self::CLOSED,
            ],
            self::ANSWERED => [
                self::ANSWERED,
                self::CUSTOMER_REPLY,
                self::IN_PROGRESS,
                self::ON_HOLD,
                self::RESOLVED,
                self::CLOSED,
            ],
            self::ON_HOLD => [
                self::ON_HOLD,
                self::CUSTOMER_REPLY,
                self::IN_PROGRESS,
                self::ANSWERED,
                self::RESOLVED,
                self::CLOSED,
            ],
            self::RESOLVED => [
                self::RESOLVED,
                self::CUSTOMER_REPLY,
                self::OPEN,
                self::IN_PROGRESS,
                self::CLOSED,
            ],
            self::CLOSED => [
                self::CLOSED,
                self::OPEN,
                self::CUSTOMER_REPLY,
            ],
            default => [],
        };
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        return in_array($to, self::allowedTransitions($from), true);
    }

    public static function assertValidTransition(string $from, string $to): void
    {
        if (!self::isValid($from)) {
            throw new ValidationException(
                ['from_status' => "Invalid initial ticket status: '{$from}'"],
                'Invalid initial ticket status'
            );
        }

        if (!self::isValid($to)) {
            throw new ValidationException(
                ['to_status' => "Invalid target ticket status: '{$to}'"],
                'Invalid target ticket status'
            );
        }

        if (!self::canTransition($from, $to)) {
            throw new ValidationException(
                ['status' => "Illegal status transition from '{$from}' to '{$to}'"],
                'Illegal ticket status transition'
            );
        }
    }

    public static function getLabel(string $status, string $locale = 'en'): string
    {
        $labelsEn = [
            self::OPEN => 'Open',
            self::CUSTOMER_REPLY => 'Customer-Reply',
            self::IN_PROGRESS => 'In Progress',
            self::ANSWERED => 'Answered',
            self::ON_HOLD => 'On Hold',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed',
        ];

        $labelsTr = [
            self::OPEN => 'Açık',
            self::CUSTOMER_REPLY => 'Müşteri Yanıtladı',
            self::IN_PROGRESS => 'İşlemde',
            self::ANSWERED => 'Yanıtlandı',
            self::ON_HOLD => 'Beklemede',
            self::RESOLVED => 'Çözüldü',
            self::CLOSED => 'Kapatıldı',
        ];

        $map = ($locale === 'tr') ? $labelsTr : $labelsEn;

        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function getBadgeClass(string $status): string
    {
        return match ($status) {
            self::OPEN => 'badge-danger',
            self::CUSTOMER_REPLY => 'badge-warning',
            self::IN_PROGRESS => 'badge-info',
            self::ANSWERED => 'badge-primary',
            self::ON_HOLD => 'badge-secondary',
            self::RESOLVED => 'badge-success',
            self::CLOSED => 'badge-dark',
            default => 'badge-light',
        };
    }
}
