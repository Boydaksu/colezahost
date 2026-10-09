<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Retention;

use DateTimeImmutable;

final class RetentionPolicy
{
    public function __construct(
        private readonly string $policyKey,
        private readonly string $category,
        private readonly int $retentionDays,
        private readonly string $actionOnExpiry,
        private readonly bool $isStatutory,
        private readonly string $description
    ) {
    }

    public function getPolicyKey(): string
    {
        return $this->policyKey;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function getActionOnExpiry(): string
    {
        return $this->actionOnExpiry;
    }

    public function isStatutory(): bool
    {
        return $this->isStatutory;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getExpirationDate(DateTimeImmutable $recordDate): DateTimeImmutable
    {
        return $recordDate->modify(sprintf('+%d days', $this->retentionDays));
    }

    public function isExpired(DateTimeImmutable $recordDate, ?DateTimeImmutable $now = null): bool
    {
        $current = $now ?? new DateTimeImmutable();
        return $current >= $this->getExpirationDate($recordDate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'policy_key' => $this->policyKey,
            'category' => $this->category,
            'retention_days' => $this->retentionDays,
            'action_on_expiry' => $this->actionOnExpiry,
            'is_statutory' => $this->isStatutory,
            'description' => $this->description,
        ];
    }
}
