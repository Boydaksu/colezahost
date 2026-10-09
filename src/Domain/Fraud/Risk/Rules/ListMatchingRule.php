<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Lists\FraudListService;
use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class ListMatchingRule implements RiskRuleInterface
{
    public const RULE_DENYLIST_MATCH = 'RULE_DENYLIST_MATCH';
    public const RULE_WATCHLIST_MATCH = 'RULE_WATCHLIST_MATCH';

    public function __construct(
        private readonly FraudListService $listService,
        private readonly int $denyScore = 100,
        private readonly int $watchScore = 40
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_DENYLIST_MATCH;
    }

    public function getRuleName(): string
    {
        return 'Allow / Watch / Deny List Verification';
    }

    public function getDefaultWeight(): int
    {
        return $this->denyScore;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $matches = $this->listService->checkContext($context);

        if (!empty($matches['deny'])) {
            $first = $matches['deny'][0];
            return new RiskSignal(
                ruleCode: self::RULE_DENYLIST_MATCH,
                score: $this->denyScore,
                severity: 'critical',
                description: sprintf(
                    'Matched explicit DENY list entry (%s: "%s"). Reason: %s',
                    $first->getEntryType()->value,
                    $first->getValue(),
                    $first->getReason()
                ),
                metadata: [
                    'matched_type' => $first->getEntryType()->value,
                    'matched_value' => $first->getValue(),
                    'reason' => $first->getReason(),
                ]
            );
        }

        if (!empty($matches['watch'])) {
            $first = $matches['watch'][0];
            return new RiskSignal(
                ruleCode: self::RULE_WATCHLIST_MATCH,
                score: $this->watchScore,
                severity: 'high',
                description: sprintf(
                    'Matched heightened WATCH list entry (%s: "%s"). Reason: %s',
                    $first->getEntryType()->value,
                    $first->getValue(),
                    $first->getReason()
                ),
                metadata: [
                    'matched_type' => $first->getEntryType()->value,
                    'matched_value' => $first->getValue(),
                    'reason' => $first->getReason(),
                ]
            );
        }

        return null;
    }
}
