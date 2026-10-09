<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk;

use Coleza\Domain\Fraud\Risk\Rules\AnonymousProxyRule;
use Coleza\Domain\Fraud\Risk\Rules\DisposableEmailRule;
use Coleza\Domain\Fraud\Risk\Rules\GeoIpMismatchRule;
use Coleza\Domain\Fraud\Risk\Rules\HighOrderValueRule;
use Coleza\Domain\Fraud\Risk\Rules\NewCustomerHighValueRule;
use Coleza\Domain\Fraud\Risk\Rules\PaymentCountryMismatchRule;
use Coleza\Domain\Fraud\Risk\Rules\RiskRuleInterface;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;

final class RiskScoreService
{
    private string $evaluationsTable = 'fraud_risk_evaluations';
    private string $signalsTable = 'fraud_risk_signals';

    /**
     * @var array<string, RiskRuleInterface>
     */
    private array $rules = [];

    /**
     * @param list<RiskRuleInterface> $rules
     */
    public function __construct(
        private readonly Connection $db,
        array $rules = [],
        private readonly int $defaultReviewThreshold = 30,
        private readonly int $defaultRejectThreshold = 70
    ) {
        if (empty($rules)) {
            $this->registerRule(new DisposableEmailRule());
            $this->registerRule(new AnonymousProxyRule());
            $this->registerRule(new GeoIpMismatchRule());
            $this->registerRule(new PaymentCountryMismatchRule());
            $this->registerRule(new HighOrderValueRule());
            $this->registerRule(new NewCustomerHighValueRule());
        } else {
            foreach ($rules as $rule) {
                $this->registerRule($rule);
            }
        }
    }

    public function registerRule(RiskRuleInterface $rule): self
    {
        $this->rules[$rule->getRuleCode()] = $rule;
        return $this;
    }

    /**
     * @return array<string, RiskRuleInterface>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlEvaluations = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT NULL,
                user_id INT NULL,
                organization_id INT NULL,
                total_score INT NOT NULL,
                decision VARCHAR(20) NOT NULL,
                signals_count INT NOT NULL DEFAULT 0,
                context_snapshot_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->evaluationsTable,
            $autoInc
        );
        $this->db->statement($sqlEvaluations);

        $sqlSignals = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                evaluation_id INT NOT NULL,
                rule_code VARCHAR(100) NOT NULL,
                score INT NOT NULL,
                severity VARCHAR(30) NOT NULL,
                description TEXT NOT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->signalsTable,
            $autoInc
        );
        $this->db->statement($sqlSignals);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_fraud_eval_entity ON {$this->evaluationsTable} (entity_type, entity_id)");
                $this->db->statement("CREATE INDEX idx_fraud_eval_user ON {$this->evaluationsTable} (user_id)");
                $this->db->statement("CREATE INDEX idx_fraud_signals_eval ON {$this->signalsTable} (evaluation_id)");
            } catch (\Throwable) {
                // Ignore if indices exist
            }
        }
    }

    public function evaluate(
        RiskContext $context,
        ?int $reviewThreshold = null,
        ?int $rejectThreshold = null,
        bool $persist = true
    ): RiskEvaluationResult {
        $this->ensureTables();

        $reviewLimit = $reviewThreshold ?? $this->defaultReviewThreshold;
        $rejectLimit = $rejectThreshold ?? $this->defaultRejectThreshold;

        /** @var list<RiskSignal> $triggeredSignals */
        $triggeredSignals = [];
        $rawScore = 0;

        foreach ($this->rules as $rule) {
            $signal = $rule->evaluate($context);
            if ($signal !== null) {
                $triggeredSignals[] = $signal;
                $rawScore += $signal->getScore();
            }
        }

        // Clamp total score between 0 and 100
        $totalScore = max(0, min(100, $rawScore));

        $decision = match (true) {
            $totalScore >= $rejectLimit => RiskDecision::REJECT,
            $totalScore >= $reviewLimit => RiskDecision::REVIEW,
            default => RiskDecision::ACCEPT,
        };

        $evaluationId = null;
        $now = new DateTimeImmutable();

        if ($persist) {
            $evalRow = [
                'entity_type' => $context->getEntityType(),
                'entity_id' => $context->getEntityId(),
                'user_id' => $context->getUserId(),
                'organization_id' => $context->getOrganizationId(),
                'total_score' => $totalScore,
                'decision' => $decision->value,
                'signals_count' => count($triggeredSignals),
                'context_snapshot_json' => json_encode($context->toArray(), JSON_UNESCAPED_SLASHES),
            ];

            $evaluationId = $this->db->insert($this->evaluationsTable, $evalRow);

            foreach ($triggeredSignals as $signal) {
                $signalRow = [
                    'evaluation_id' => $evaluationId,
                    'rule_code' => $signal->getRuleCode(),
                    'score' => $signal->getScore(),
                    'severity' => $signal->getSeverity(),
                    'description' => $signal->getDescription(),
                    'metadata_json' => json_encode($signal->getMetadata(), JSON_UNESCAPED_SLASHES),
                ];
                $this->db->insert($this->signalsTable, $signalRow);
            }
        }

        return new RiskEvaluationResult(
            id: $evaluationId,
            entityType: $context->getEntityType(),
            entityId: $context->getEntityId(),
            userId: $context->getUserId(),
            organizationId: $context->getOrganizationId(),
            totalScore: $totalScore,
            decision: $decision,
            signals: $triggeredSignals,
            contextSnapshot: $context->toArray(),
            evaluatedAt: $now
        );
    }

    public function getEvaluation(int $id): ?RiskEvaluationResult
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            "SELECT * FROM {$this->evaluationsTable} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        return $this->hydrateEvaluation($row);
    }

    /**
     * @return list<RiskEvaluationResult>
     */
    public function getEvaluationsForEntity(string $entityType, int $entityId): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->evaluationsTable} WHERE entity_type = :entity_type AND entity_id = :entity_id ORDER BY id DESC",
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]
        );

        return array_map(fn (array $r) => $this->hydrateEvaluation($r), $rows);
    }

    public function getLatestEvaluationForEntity(string $entityType, int $entityId): ?RiskEvaluationResult
    {
        $evals = $this->getEvaluationsForEntity($entityType, $entityId);
        return $evals[0] ?? null;
    }

    /**
     * @return list<RiskEvaluationResult>
     */
    public function getEvaluationsForUser(int $userId): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->evaluationsTable} WHERE user_id = :user_id ORDER BY id DESC",
            ['user_id' => $userId]
        );

        return array_map(fn (array $r) => $this->hydrateEvaluation($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEvaluation(array $row): RiskEvaluationResult
    {
        $evalId = (int) $row['id'];
        $signalRows = $this->db->select(
            "SELECT * FROM {$this->signalsTable} WHERE evaluation_id = :eval_id ORDER BY id ASC",
            ['eval_id' => $evalId]
        );

        $signals = [];
        foreach ($signalRows as $sRow) {
            $metadata = !empty($sRow['metadata_json'])
                ? json_decode((string) $sRow['metadata_json'], true) ?? []
                : [];

            $signals[] = new RiskSignal(
                ruleCode: (string) $sRow['rule_code'],
                score: (int) $sRow['score'],
                severity: (string) $sRow['severity'],
                description: (string) $sRow['description'],
                metadata: $metadata
            );
        }

        $contextSnapshot = !empty($row['context_snapshot_json'])
            ? json_decode((string) $row['context_snapshot_json'], true) ?? []
            : [];

        $evaluatedAt = !empty($row['created_at'])
            ? new DateTimeImmutable((string) $row['created_at'])
            : new DateTimeImmutable();

        return new RiskEvaluationResult(
            id: $evalId,
            entityType: (string) $row['entity_type'],
            entityId: isset($row['entity_id']) ? (int) $row['entity_id'] : null,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            organizationId: isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            totalScore: (int) $row['total_score'],
            decision: RiskDecision::from((string) $row['decision']),
            signals: $signals,
            contextSnapshot: $contextSnapshot,
            evaluatedAt: $evaluatedAt
        );
    }
}
