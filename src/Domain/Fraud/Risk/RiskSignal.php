<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk;

final class RiskSignal
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $ruleCode,
        private readonly int $score,
        private readonly string $severity,
        private readonly string $description,
        private readonly array $metadata = []
    ) {
    }

    public function getRuleCode(): string
    {
        return $this->ruleCode;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_code' => $this->ruleCode,
            'score' => $this->score,
            'severity' => $this->severity,
            'description' => $this->description,
            'metadata' => $this->metadata,
        ];
    }
}
