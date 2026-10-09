<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Linkage;

final class RelatedAccountResult
{
    /**
     * @param list<int> $linkedUserIds
     * @param list<int> $fraudulentUserIds
     * @param array<string, mixed> $details
     */
    public function __construct(
        private readonly int $subjectUserId,
        private readonly array $linkedUserIds,
        private readonly array $fraudulentUserIds,
        private readonly array $details = []
    ) {
    }

    public function getSubjectUserId(): int
    {
        return $this->subjectUserId;
    }

    /**
     * @return list<int>
     */
    public function getLinkedUserIds(): array
    {
        return $this->linkedUserIds;
    }

    public function getLinkedCount(): int
    {
        return count($this->linkedUserIds);
    }

    /**
     * @return list<int>
     */
    public function getFraudulentUserIds(): array
    {
        return $this->fraudulentUserIds;
    }

    public function hasFraudulentHistory(): bool
    {
        return !empty($this->fraudulentUserIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject_user_id' => $this->subjectUserId,
            'linked_user_ids' => $this->linkedUserIds,
            'linked_count' => count($this->linkedUserIds),
            'fraudulent_user_ids' => $this->fraudulentUserIds,
            'has_fraudulent_history' => $this->hasFraudulentHistory(),
            'details' => $this->details,
        ];
    }
}
