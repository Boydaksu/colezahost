<?php

declare(strict_types=1);

namespace Coleza\Foundation\Queue;

final class QueueJob
{
    public function __construct(
        private int|string $id,
        private string $queue,
        private JobInterface $job,
        private int $attempts,
        private ?string $reservationToken = null
    ) {
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getReservationToken(): ?string
    {
        return $this->reservationToken;
    }
}
