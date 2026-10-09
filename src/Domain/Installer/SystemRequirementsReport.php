<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use JsonSerializable;

/**
 * Report encapsulating all evaluated system environment and server requirement checks.
 */
final class SystemRequirementsReport implements JsonSerializable
{
    /**
     * @param list<SystemRequirementItem> $items
     */
    public function __construct(
        private array $items,
        private ?string $checkedAt = null
    ) {
        $this->checkedAt ??= date('c');
    }

    /**
     * @return list<SystemRequirementItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getItem(string $key): ?SystemRequirementItem
    {
        foreach ($this->items as $item) {
            if ($item->getKey() === $key) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Installation can only proceed if all REQUIRED prerequisites pass.
     */
    public function isInstallable(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isRequired() && !$item->isPassed()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<SystemRequirementItem>
     */
    public function getMissingRequired(): array
    {
        $missing = [];
        foreach ($this->items as $item) {
            if ($item->isRequired() && !$item->isPassed()) {
                $missing[] = $item;
            }
        }
        return $missing;
    }

    /**
     * @return list<SystemRequirementItem>
     */
    public function getWarnings(): array
    {
        $warnings = [];
        foreach ($this->items as $item) {
            if (!$item->isRequired() && !$item->isPassed()) {
                $warnings[] = $item;
            }
        }
        return $warnings;
    }

    public function getCheckedAt(): string
    {
        return $this->checkedAt ?? date('c');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serialized = [];
        foreach ($this->items as $item) {
            $serialized[] = $item->toArray();
        }

        return [
            'is_installable' => $this->isInstallable(),
            'total_checks' => count($this->items),
            'missing_required_count' => count($this->getMissingRequired()),
            'warning_count' => count($this->getWarnings()),
            'checked_at' => $this->checkedAt,
            'items' => $serialized,
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
