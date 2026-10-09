<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Subscription;

use JsonSerializable;

/**
 * Immutable point-in-time snapshot of subscription and MRR economics.
 */
final class SubscriptionSnapshot implements JsonSerializable
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $snapshotDate,
        private readonly string $currency,
        private readonly int $activeSubscriptionsCount,
        private readonly int $activeCustomersCount,
        private readonly float $totalMrr,
        private readonly float $totalArr,
        private readonly float $newMrr,
        private readonly float $expansionMrr,
        private readonly float $contractionMrr,
        private readonly float $churnedMrr,
        private readonly float $netMrrGrowth,
        private readonly float $arpu,
        private readonly array $metadata = [],
        private readonly ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshotDate(): string
    {
        return $this->snapshotDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getActiveSubscriptionsCount(): int
    {
        return $this->activeSubscriptionsCount;
    }

    public function getActiveCustomersCount(): int
    {
        return $this->activeCustomersCount;
    }

    public function getTotalMrr(): float
    {
        return $this->totalMrr;
    }

    public function getTotalArr(): float
    {
        return $this->totalArr;
    }

    public function getNewMrr(): float
    {
        return $this->newMrr;
    }

    public function getExpansionMrr(): float
    {
        return $this->expansionMrr;
    }

    public function getContractionMrr(): float
    {
        return $this->contractionMrr;
    }

    public function getChurnedMrr(): float
    {
        return $this->churnedMrr;
    }

    public function getNetMrrGrowth(): float
    {
        return $this->netMrrGrowth;
    }

    public function getArpu(): float
    {
        return $this->arpu;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt ?? date('Y-m-d H:i:s');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'snapshot_date' => $this->snapshotDate,
            'currency' => $this->currency,
            'active_subscriptions_count' => $this->activeSubscriptionsCount,
            'active_customers_count' => $this->activeCustomersCount,
            'total_mrr' => $this->totalMrr,
            'total_arr' => $this->totalArr,
            'new_mrr' => $this->newMrr,
            'expansion_mrr' => $this->expansionMrr,
            'contraction_mrr' => $this->contractionMrr,
            'churned_mrr' => $this->churnedMrr,
            'net_mrr_growth' => $this->netMrrGrowth,
            'arpu' => $this->arpu,
            'metadata' => $this->metadata,
            'created_at' => $this->getCreatedAt(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $meta = $data['metadata'] ?? [];
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            snapshotDate: (string) $data['snapshot_date'],
            currency: (string) $data['currency'],
            activeSubscriptionsCount: (int) $data['active_subscriptions_count'],
            activeCustomersCount: (int) $data['active_customers_count'],
            totalMrr: (float) $data['total_mrr'],
            totalArr: (float) $data['total_arr'],
            newMrr: (float) ($data['new_mrr'] ?? 0.0),
            expansionMrr: (float) ($data['expansion_mrr'] ?? 0.0),
            contractionMrr: (float) ($data['contraction_mrr'] ?? 0.0),
            churnedMrr: (float) ($data['churned_mrr'] ?? 0.0),
            netMrrGrowth: (float) ($data['net_mrr_growth'] ?? 0.0),
            arpu: (float) ($data['arpu'] ?? 0.0),
            metadata: $meta,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
