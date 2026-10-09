<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Erasure;

final class ErasurePlan
{
    /**
     * @param list<string> $blockers
     * @param list<ErasurePlanItem> $items
     */
    public function __construct(
        private readonly int $userId,
        private readonly bool $isEligible,
        private readonly array $blockers,
        private readonly array $items
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function isEligible(): bool
    {
        return $this->isEligible;
    }

    /**
     * @return list<string>
     */
    public function getBlockers(): array
    {
        return $this->blockers;
    }

    /**
     * @return list<ErasurePlanItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getDeleteCount(): int
    {
        return count(array_filter($this->items, fn (ErasurePlanItem $i) => $i->getAction()->isDelete()));
    }

    public function getAnonymizeCount(): int
    {
        return count(array_filter($this->items, fn (ErasurePlanItem $i) => $i->getAction()->isAnonymize()));
    }

    public function getRetainCount(): int
    {
        return count(array_filter($this->items, fn (ErasurePlanItem $i) => $i->getAction()->isRetain()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'is_eligible' => $this->isEligible,
            'blockers' => $this->blockers,
            'summary' => [
                'delete_count' => $this->getDeleteCount(),
                'anonymize_count' => $this->getAnonymizeCount(),
                'retain_count' => $this->getRetainCount(),
                'total_items' => count($this->items),
            ],
            'items' => array_map(fn (ErasurePlanItem $i) => $i->toArray(), $this->items),
        ];
    }
}
