<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Retry;

use Coleza\Domain\Provisioning\Classification\ProvisioningErrorCategory;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;
use DateTimeImmutable;

final class ProvisioningRetryPolicy
{
    public const DEFAULT_BASE_DELAY_SECONDS = 15;
    public const DEFAULT_MULTIPLIER = 2.0;
    public const DEFAULT_MAX_DELAY_SECONDS = 3600;
    public const DEFAULT_JITTER_FACTOR = 0.1;
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct(
        private int $baseDelaySeconds = self::DEFAULT_BASE_DELAY_SECONDS,
        private float $multiplier = self::DEFAULT_MULTIPLIER,
        private int $maxDelaySeconds = self::DEFAULT_MAX_DELAY_SECONDS,
        private float $jitterFactor = self::DEFAULT_JITTER_FACTOR,
        private int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ) {
    }

    /**
     * Determines whether an error classification and attempt count qualify for automated retry.
     */
    public function isRetryable(ProvisioningErrorClassification $classification, int $attemptCount): bool
    {
        if ($attemptCount >= $this->maxAttempts) {
            return false;
        }

        return $classification->isRetryable();
    }

    /**
     * Calculates the exponential backoff delay in seconds for a specific attempt number.
     *
     * Formula: min(maxDelay, baseDelay * (multiplier ^ (attempt - 1)) + jitter)
     */
    public function calculateDelaySeconds(int $attemptNumber, ?string $errorCategory = null, bool $applyJitter = true): int
    {
        $attempt = max(1, $attemptNumber);

        // Adjust base delay for rate limits
        $base = $this->baseDelaySeconds;
        if ($errorCategory === ProvisioningErrorCategory::RATE_LIMIT) {
            $base = max($base, 60);
        }

        $exponential = $base * ($this->multiplier ** ($attempt - 1));
        $delay = (int)round($exponential);

        if ($applyJitter && $this->jitterFactor > 0.0) {
            // Apply randomized pseudo-jitter between 0 and jitterFactor * delay
            $maxJitter = (int)ceil($delay * $this->jitterFactor);
            $jitter = random_int(0, max(1, $maxJitter));
            $delay += $jitter;
        }

        return min($this->maxDelaySeconds, max(1, $delay));
    }

    /**
     * Calculate the timestamp for the next attempt.
     */
    public function calculateNextAttemptAt(
        int $attemptNumber,
        ?string $errorCategory = null,
        ?DateTimeImmutable $baseTime = null,
        bool $applyJitter = true
    ): DateTimeImmutable {
        $now = $baseTime ?? new DateTimeImmutable('now');
        $delay = $this->calculateDelaySeconds($attemptNumber, $errorCategory, $applyJitter);

        return $now->modify("+{$delay} seconds");
    }

    public function getBaseDelaySeconds(): int
    {
        return $this->baseDelaySeconds;
    }

    public function getMultiplier(): float
    {
        return $this->multiplier;
    }

    public function getMaxDelaySeconds(): int
    {
        return $this->maxDelaySeconds;
    }

    public function getJitterFactor(): float
    {
        return $this->jitterFactor;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
