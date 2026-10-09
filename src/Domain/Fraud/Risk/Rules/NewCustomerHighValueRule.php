<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class NewCustomerHighValueRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_NEW_CUSTOMER_HIGH_VALUE';

    public function __construct(
        private readonly float $threshold = 150.0,
        private readonly int $score = 30
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'First-Time Customer Large Transaction Exposure';
    }

    public function getDefaultWeight(): int
    {
        return $this->score;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $isNew = $context->isNewCustomer() || $context->getPriorSuccessfulOrdersCount() === 0;
        $amount = $context->getOrderAmount();

        if ($isNew && $amount >= $this->threshold) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->score,
                severity: 'medium',
                description: sprintf(
                    'First-time customer with zero prior order history attempted purchase exceeding initial risk tolerance (%.2f %s).',
                    $amount,
                    $context->getCurrency()
                ),
                metadata: [
                    'order_amount' => $amount,
                    'currency' => $context->getCurrency(),
                    'prior_successful_orders' => $context->getPriorSuccessfulOrdersCount(),
                    'threshold' => $this->threshold,
                ]
            );
        }

        return null;
    }
}
