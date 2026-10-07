<?php

declare(strict_types=1);

namespace Coleza\Foundation\Events;

use DateTimeImmutable;

interface EventInterface
{
    /**
     * Timestamp when the event occurred (in UTC).
     */
    public function occurredAt(): DateTimeImmutable;

    /**
     * Name identifier for the event.
     */
    public function eventName(): string;
}
