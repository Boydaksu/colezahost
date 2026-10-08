<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Contracts;

final class ProviderCapability
{
    public const CREATE_ACCOUNT = 'create_account';
    public const SUSPEND_ACCOUNT = 'suspend_account';
    public const UNSUSPEND_ACCOUNT = 'unsuspend_account';
    public const TERMINATE_ACCOUNT = 'terminate_account';
    public const CHANGE_PACKAGE = 'change_package';
    public const CHANGE_PASSWORD = 'change_password';
    public const USAGE_METRICS = 'usage_metrics';
    public const SINGLE_SIGN_ON = 'single_sign_on';
    public const CUSTOM_ACTION = 'custom_action';

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::CREATE_ACCOUNT,
            self::SUSPEND_ACCOUNT,
            self::UNSUSPEND_ACCOUNT,
            self::TERMINATE_ACCOUNT,
            self::CHANGE_PACKAGE,
            self::CHANGE_PASSWORD,
            self::USAGE_METRICS,
            self::SINGLE_SIGN_ON,
            self::CUSTOM_ACTION,
        ];
    }

    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }
}
