<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Linkage\AccountLinkageService;
use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class RelatedAccountsRiskRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_RELATED_ACCOUNTS';

    public function __construct(
        private readonly AccountLinkageService $linkageService,
        private readonly int $fraudulentAccountScore = 70,
        private readonly int $multiAccountScore = 25,
        private readonly int $multiAccountThreshold = 3
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Multi-Account Network & Fraud History Linkage';
    }

    public function getDefaultWeight(): int
    {
        return $this->fraudulentAccountScore;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $userId = $context->getUserId();
        if ($userId === null) {
            return null;
        }

        $result = $this->linkageService->findLinkedAccounts(
            subjectUserId: $userId,
            clientIp: $context->getClientIp(),
            email: $context->getEmail()
        );

        if ($result->hasFraudulentHistory()) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->fraudulentAccountScore,
                severity: 'critical',
                description: sprintf(
                    'Subject user #%d is directly linked via IP/identity footprint to %d confirmed fraudulent/blacklisted user account(s): [%s].',
                    $userId,
                    count($result->getFraudulentUserIds()),
                    implode(', ', $result->getFraudulentUserIds())
                ),
                metadata: [
                    'fraudulent_user_ids' => $result->getFraudulentUserIds(),
                    'linked_count' => $result->getLinkedCount(),
                    'details' => $result->getDetails(),
                ]
            );
        }

        if ($result->getLinkedCount() >= $this->multiAccountThreshold) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->multiAccountScore,
                severity: 'medium',
                description: sprintf(
                    'Subject user #%d is clustered with %d distinct registered user accounts from identical network/IP footprint.',
                    $userId,
                    $result->getLinkedCount()
                ),
                metadata: [
                    'linked_user_ids' => $result->getLinkedUserIds(),
                    'linked_count' => $result->getLinkedCount(),
                ]
            );
        }

        return null;
    }
}
