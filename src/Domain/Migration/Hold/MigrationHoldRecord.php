<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Hold;

use JsonSerializable;

/**
 * Represents a migration hold placed upon an entity or the entire platform.
 */
final class MigrationHoldRecord implements JsonSerializable
{
    /**
     * @param list<string> $suppressedActions
     */
    public function __construct(
        private string $batchId,
        private string $entityType,
        private string $entityId,
        private string $reason,
        private MigrationHoldStatus $status = MigrationHoldStatus::ACTIVE,
        private array $suppressedActions = ['*'],
        private ?string $heldAt = null,
        private ?string $releasedAt = null,
        private ?string $releasedBy = null,
        private ?string $releaseNotes = null,
        private ?int $id = null
    ) {
        $this->heldAt ??= date('Y-m-d H:i:s');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): MigrationHoldStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === MigrationHoldStatus::ACTIVE;
    }

    /**
     * @return list<string>
     */
    public function getSuppressedActions(): array
    {
        return $this->suppressedActions;
    }

    public function isActionSuppressed(?string $action = null): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($action === null || empty($this->suppressedActions) || in_array('*', $this->suppressedActions, true)) {
            return true;
        }

        $needle = strtolower(trim($action));
        foreach ($this->suppressedActions as $suppressed) {
            if (strtolower(trim($suppressed)) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function getHeldAt(): string
    {
        return $this->heldAt ?? date('Y-m-d H:i:s');
    }

    public function getReleasedAt(): ?string
    {
        return $this->releasedAt;
    }

    public function getReleasedBy(): ?string
    {
        return $this->releasedBy;
    }

    public function getReleaseNotes(): ?string
    {
        return $this->releaseNotes;
    }

    public function release(string $releasedBy = 'admin', ?string $notes = null): void
    {
        $this->status = MigrationHoldStatus::RELEASED;
        $this->releasedAt = date('Y-m-d H:i:s');
        $this->releasedBy = $releasedBy;
        $this->releaseNotes = $notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batchId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'suppressed_actions' => $this->suppressedActions,
            'held_at' => $this->heldAt,
            'released_at' => $this->releasedAt,
            'released_by' => $this->releasedBy,
            'release_notes' => $this->releaseNotes,
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
