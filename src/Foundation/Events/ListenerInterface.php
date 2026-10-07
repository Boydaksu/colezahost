<?php

declare(strict_types=1);

namespace Coleza\Foundation\Events;

interface ListenerInterface
{
    /**
     * Handle an observed domain event.
     */
    public function handle(EventInterface $event): void;
}
