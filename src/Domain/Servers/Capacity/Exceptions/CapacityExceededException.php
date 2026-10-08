<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Capacity\Exceptions;

final class CapacityExceededException extends ReservationException
{
    public function __construct(
        int $serverId,
        string $resource,
        int $requested,
        int $available,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: "Server {$serverId} does not have sufficient {$resource} capacity (requested: {$requested}, available: {$available}).",
            errorCode: 'CAPACITY_EXCEEDED',
            context: [
                'server_id' => $serverId,
                'resource' => $resource,
                'requested' => $requested,
                'available' => $available,
            ],
            previous: $previous
        );
    }
}
