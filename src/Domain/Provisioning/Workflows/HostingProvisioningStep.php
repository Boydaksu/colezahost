<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Workflows;

final class HostingProvisioningStep
{
    public const VALIDATE = 'validate';
    public const PLACE = 'place';
    public const RESERVE = 'reserve';
    public const REMOTE = 'remote';
    public const VERIFY = 'verify';
    public const ACTIVATE = 'activate';

    /**
     * @return array<string>
     */
    public static function allSteps(): array
    {
        return [
            self::VALIDATE,
            self::PLACE,
            self::RESERVE,
            self::REMOTE,
            self::VERIFY,
            self::ACTIVATE,
        ];
    }
}
