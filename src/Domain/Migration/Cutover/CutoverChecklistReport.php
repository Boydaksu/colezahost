<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Cutover;

use JsonSerializable;

/**
 * Report encapsulating the full pre-cutover checklist status.
 */
final class CutoverChecklistReport implements JsonSerializable
{
    /**
     * @param array<string, CutoverChecklistItem> $items
     */
    public function __construct(
        private string $batchId,
        private array $items,
        private ?string $generatedAt = null
    ) {
        $this->generatedAt ??= date('c');
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    /**
     * @return array<string, CutoverChecklistItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getItem(string $key): ?CutoverChecklistItem
    {
        return $this->items[$key] ?? null;
    }

    public function isReadyForCutover(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isRequired() && !$item->isPassed()) {
                return false;
            }
        }
        return true;
    }

    public function getPassedCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            if ($item->isPassed()) {
                $count++;
            }
        }
        return $count;
    }

    public function getFailedCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            if (!$item->isPassed()) {
                $count++;
            }
        }
        return $count;
    }

    public function getGeneratedAt(): string
    {
        return $this->generatedAt ?? date('c');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serializedItems = [];
        foreach ($this->items as $k => $item) {
            $serializedItems[$k] = $item->toArray();
        }

        return [
            'batch_id' => $this->batchId,
            'ready_for_cutover' => $this->isReadyForCutover(),
            'passed_count' => $this->getPassedCount(),
            'failed_count' => $this->getFailedCount(),
            'total_items' => count($this->items),
            'generated_at' => $this->generatedAt,
            'items' => $serializedItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
