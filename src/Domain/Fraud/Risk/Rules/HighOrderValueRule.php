<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class HighOrderValueRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_HIGH_ORDER_VALUE';

    public function __construct(
        private readonly float $mediumThreshold = 500.0,
        private readonly float $highThreshold = 2000.0,
        private readonly int $mediumScore = 20,
        private readonly int $highScore = 40
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Elevated Transaction / High Order Amount Threshold';
    }

    public function getDefaultWeight(): int
    {
        return $this->highScore;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $amount = $context->getOrderAmount();

        if ($amount >= $this->highThreshold) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->highScore,
                severity: 'high',
                description: sprintf(
                    'Order transaction amount (%.2f %s) exceeds major enterprise threshold (%.2f).',
                    $amount,
                    $context->getCurrency(),
                    $this->highThreshold
                ),
                metadata: [
                    'order_amount' => $amount,
                    'currency' => $context->getCurrency(),
                    'threshold' => $this->highThreshold,
                    'tier' => 'critical_value',
                ]
            );
        }

        if ($amount >= $this->mediumThreshold) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->mediumScore,
                severity: 'medium',
                description: sprintf(
                    'Order transaction amount (%.2f %s) exceeds standard threshold (%.2f).',
                    $amount,
                    $context->getCurrency(),
                    $this->mediumThreshold
                ),
                metadata: [
                    'order_amount' => $amount,
                    'currency' => $context->getCurrency(),
                    'threshold' => $this->mediumThreshold,
                    'tier' => 'elevated_value',
                ]
            );
        }

        return null;
    }
}
