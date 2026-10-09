<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use JsonSerializable;

final class RestoreReconciliationReport implements JsonSerializable
{
    public function __construct(
        private readonly int $tombstonesEvaluated,
        private readonly int $resurrectedUsersDetected,
        private readonly int $resurrectedUsersReScrubbed,
        private readonly array $scrubbedUserIds,
        private readonly bool $isClean,
        private readonly string $reconciledAt,
        private readonly string $auditDigest
    ) {
    }

    public function getTombstonesEvaluated(): int
    {
        return $this->tombstonesEvaluated;
    }

    public function getResurrectedUsersDetected(): int
    {
        return $this->resurrectedUsersDetected;
    }

    public function getResurrectedUsersReScrubbed(): int
    {
        return $this->resurrectedUsersReScrubbed;
    }

    public function getScrubbedUserIds(): array
    {
        return $this->scrubbedUserIds;
    }

    public function isClean(): bool
    {
        return $this->isClean;
    }

    public function getReconciledAt(): string
    {
        return $this->reconciledAt;
    }

    public function getAuditDigest(): string
    {
        return $this->auditDigest;
    }

    public function isProductionReady(): bool
    {
        return $this->isClean && ($this->resurrectedUsersDetected === $this->resurrectedUsersReScrubbed);
    }

    public function toArray(): array
    {
        return [
            'tombstones_evaluated' => $this->tombstonesEvaluated,
            'resurrected_users_detected' => $this->resurrectedUsersDetected,
            'resurrected_users_rescrubbed' => $this->resurrectedUsersReScrubbed,
            'scrubbed_user_ids' => $this->scrubbedUserIds,
            'is_clean' => $this->isClean,
            'reconciled_at' => $this->reconciledAt,
            'audit_digest' => $this->auditDigest,
            'production_ready' => $this->isProductionReady(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
