<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Preferences;

use InvalidArgumentException;

final class NotificationPreference
{
    public const CATEGORY_BILLING = 'billing';
    public const CATEGORY_SERVICES = 'services';
    public const CATEGORY_SUPPORT = 'support';
    public const CATEGORY_MARKETING = 'marketing';
    public const CATEGORY_SECURITY = 'security';

    public const MANDATORY_CATEGORIES = [
        self::CATEGORY_SECURITY,
    ];

    public function __construct(
        private int $userId,
        private string $category,
        private bool $emailEnabled = true,
        private bool $inAppEnabled = true,
        private bool $smsEnabled = false
    ) {
        if (!in_array($category, [
            self::CATEGORY_BILLING,
            self::CATEGORY_SERVICES,
            self::CATEGORY_SUPPORT,
            self::CATEGORY_MARKETING,
            self::CATEGORY_SECURITY,
        ], true)) {
            throw new InvalidArgumentException("Unknown notification preference category '{$category}'.");
        }

        // Security notifications cannot be disabled on primary channels
        if ($this->isMandatory()) {
            $this->emailEnabled = true;
            $this->inAppEnabled = true;
        }
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    public function isInAppEnabled(): bool
    {
        return $this->inAppEnabled;
    }

    public function isSmsEnabled(): bool
    {
        return $this->smsEnabled;
    }

    public function isMandatory(): bool
    {
        return in_array($this->category, self::MANDATORY_CATEGORIES, true);
    }
}
