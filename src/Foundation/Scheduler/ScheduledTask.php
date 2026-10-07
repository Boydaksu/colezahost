<?php

declare(strict_types=1);

namespace Coleza\Foundation\Scheduler;

use Closure;
use Coleza\Foundation\Lock\LockInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class ScheduledTask
{
    private bool $withoutOverlapping = false;
    private int $lockTtlSeconds = 300;
    private ?string $name = null;
    /** @var Closure(DateTimeImmutable): bool */
    private Closure $expression;

    /**
     * @param Closure(): mixed $callback
     */
    public function __construct(
        private Closure $callback,
        ?string $name = null
    ) {
        $this->name = $name;
        // Default: runs every minute
        $this->expression = fn (DateTimeImmutable $time): bool => true;
    }

    public function getName(): string
    {
        return $this->name ?? ('task_' . spl_object_hash($this));
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withoutOverlapping(int $lockTtlSeconds = 300): self
    {
        $this->withoutOverlapping = true;
        $this->lockTtlSeconds = $lockTtlSeconds;
        return $this;
    }

    public function everyMinute(): self
    {
        $this->expression = fn (DateTimeImmutable $time): bool => true;
        return $this;
    }

    public function everyFiveMinutes(): self
    {
        $this->expression = fn (DateTimeImmutable $time): bool => ((int) $time->format('i') % 5 === 0);
        return $this;
    }

    public function everyTenMinutes(): self
    {
        $this->expression = fn (DateTimeImmutable $time): bool => ((int) $time->format('i') % 10 === 0);
        return $this;
    }

    public function everyFifteenMinutes(): self
    {
        $this->expression = fn (DateTimeImmutable $time): bool => ((int) $time->format('i') % 15 === 0);
        return $this;
    }

    public function hourly(): self
    {
        $this->expression = fn (DateTimeImmutable $time): bool => ($time->format('i') === '00');
        return $this;
    }

    public function dailyAt(string $timeString = '00:00'): self
    {
        $this->expression = fn (DateTimeImmutable $time): bool => ($time->format('H:i') === $timeString);
        return $this;
    }

    /**
     * Custom matching condition.
     *
     * @param Closure(DateTimeImmutable): bool $condition
     */
    public function when(Closure $condition): self
    {
        $prev = $this->expression;
        $this->expression = fn (DateTimeImmutable $time): bool => $prev($time) && $condition($time);
        return $this;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return ($this->expression)($now);
    }

    /**
     * Run the scheduled task callback.
     */
    public function run(?LockInterface $lock = null, ?LoggerInterface $logger = null): bool
    {
        $taskName = $this->getName();
        $resourceKey = 'scheduler:lock:' . $taskName;

        if ($this->withoutOverlapping && $lock !== null) {
            if (!$lock->acquire($resourceKey, $this->lockTtlSeconds)) {
                $logger?->info(sprintf('Scheduled task [%s] skipped: another instance is running.', $taskName));
                return false;
            }
        }

        try {
            $logger?->info(sprintf('Starting scheduled task [%s].', $taskName));
            ($this->callback)();
            $logger?->info(sprintf('Finished scheduled task [%s].', $taskName));
            return true;
        } catch (Throwable $e) {
            $logger?->error(sprintf('Scheduled task [%s] failed: %s', $taskName, $e->getMessage()), [
                'exception' => $e,
            ]);
            throw $e;
        } finally {
            if ($this->withoutOverlapping && $lock !== null) {
                $lock->release($resourceKey);
            }
        }
    }
}
