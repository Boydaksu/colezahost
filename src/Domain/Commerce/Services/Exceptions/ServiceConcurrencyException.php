<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services\Exceptions;

use Coleza\Foundation\Exceptions\ConcurrencyException;

final class ServiceConcurrencyException extends ConcurrencyException
{
    public static function versionMismatch(int $serviceId, int $expectedVersion, int $actualVersion): self
    {
        return new self(
            message: "Service {$serviceId} could not be updated due to a concurrent modification. Expected version {$expectedVersion}, but current version is {$actualVersion}.",
            errorCode: 'SERVICE_CONCURRENCY_CONFLICT',
            context: [
                'service_id' => $serviceId,
                'expected_version' => $expectedVersion,
                'actual_version' => $actualVersion,
            ]
        );
    }
}
