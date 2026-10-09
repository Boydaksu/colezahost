<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;
use Coleza\Domain\Fraud\Velocity\VelocityTrackerService;

final class VelocityRiskRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_VELOCITY_EXCEEDED';

    public function __construct(
        private readonly VelocityTrackerService $tracker,
        private readonly int $ipOrderHourlyLimit = 4,
        private readonly int $userOrderTenMinLimit = 2,
        private readonly int $paymentFailureFifteenMinLimit = 3,
        private readonly int $ipVelocityScore = 35,
        private readonly int $userVelocityScore = 30,
        private readonly int $paymentFailureScore = 45
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Rate Limiting & Velocity Bursts Check';
    }

    public function getDefaultWeight(): int
    {
        return max($this->ipVelocityScore, $this->userVelocityScore, $this->paymentFailureScore);
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $ip = $context->getClientIp();
        $userId = $context->getUserId();

        $totalScore = 0;
        $reasons = [];
        $metadata = [];

        // 1. IP Order Velocity
        if ($ip !== null && trim($ip) !== '') {
            $ipOrderCount = $this->tracker->getIpOrderVelocity($ip, 3600);
            if ($ipOrderCount >= $this->ipOrderHourlyLimit) {
                $totalScore += $this->ipVelocityScore;
                $reasons[] = sprintf('IP address "%s" exceeded order velocity threshold (%d attempts in past hour).', $ip, $ipOrderCount);
                $metadata['ip_order_count'] = $ipOrderCount;
            }

            // Payment failures for IP
            $ipFailures = $this->tracker->getPaymentFailureVelocity('ip', $ip, 900);
            if ($ipFailures >= $this->paymentFailureFifteenMinLimit) {
                $totalScore += $this->paymentFailureScore;
                $reasons[] = sprintf('IP address "%s" triggered excessive payment failures (%d failures in 15 mins).', $ip, $ipFailures);
                $metadata['ip_payment_failures'] = $ipFailures;
            }
        }

        // 2. User Order Velocity
        if ($userId !== null) {
            $userOrderCount = $this->tracker->getUserOrderVelocity($userId, 600);
            if ($userOrderCount >= $this->userOrderTenMinLimit) {
                $totalScore += $this->userVelocityScore;
                $reasons[] = sprintf('User #%d placed rapid successive orders (%d attempts in 10 mins).', $userId, $userOrderCount);
                $metadata['user_order_count'] = $userOrderCount;
            }
        }

        if ($totalScore > 0) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: min(100, $totalScore),
                severity: $totalScore >= 45 ? 'high' : 'medium',
                description: implode(' ', $reasons),
                metadata: $metadata
            );
        }

        return null;
    }
}
