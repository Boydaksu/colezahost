<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Exceptions;

final class UnsupportedCapabilityException extends ProviderException
{
    public function __construct(
        string $providerSlug,
        string $capability,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: "Provider '{$providerSlug}' does not support capability '{$capability}'.",
            errorCode: 'UNSUPPORTED_CAPABILITY',
            context: [
                'provider_slug' => $providerSlug,
                'capability' => $capability,
            ],
            previous: $previous
        );
    }
}
