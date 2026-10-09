<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class PaymentCountryMismatchRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_PAYMENT_COUNTRY_MISMATCH';

    public function __construct(
        private readonly int $mismatchScore = 25,
        private readonly int $prepaidExtraScore = 15
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Card Issuer Country Mismatch / High-Risk Card Instrument';
    }

    public function getDefaultWeight(): int
    {
        return $this->mismatchScore + $this->prepaidExtraScore;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $cardCountry = $context->getCardIssuingCountry();
        $billingCountry = $context->getBillingCountry();
        $geoIpCountry = $context->getGeoIpCountry();
        $isPrepaid = $context->isCardPrepaid();

        if ($cardCountry === null && !$isPrepaid) {
            return null;
        }

        $cardUpper = $cardCountry !== null ? strtoupper(trim($cardCountry)) : null;
        $billingUpper = $billingCountry !== null ? strtoupper(trim($billingCountry)) : null;
        $geoUpper = $geoIpCountry !== null ? strtoupper(trim($geoIpCountry)) : null;

        $mismatch = false;
        if ($cardUpper !== null) {
            if ($billingUpper !== null && $cardUpper !== $billingUpper) {
                $mismatch = true;
            } elseif ($geoUpper !== null && $cardUpper !== $geoUpper) {
                $mismatch = true;
            }
        }

        if (!$mismatch && !$isPrepaid) {
            return null;
        }

        $score = 0;
        $reasons = [];

        if ($mismatch) {
            $score += $this->mismatchScore;
            $reasons[] = sprintf(
                'Credit card issuing country "%s" mismatches customer billing/geo profile (billing: "%s", geo: "%s").',
                $cardUpper ?? 'UNKNOWN',
                $billingUpper ?? 'N/A',
                $geoUpper ?? 'N/A'
            );
        }

        if ($isPrepaid) {
            $score += $this->prepaidExtraScore;
            $reasons[] = 'Payment instrument is a prepaid or virtual debit card with elevated chargeback risk.';
        }

        return new RiskSignal(
            ruleCode: self::RULE_CODE,
            score: $score,
            severity: $score >= 35 ? 'high' : 'medium',
            description: implode(' ', $reasons),
            metadata: [
                'card_issuing_country' => $cardUpper,
                'billing_country' => $billingUpper,
                'geo_ip_country' => $geoUpper,
                'is_prepaid' => $isPrepaid,
            ]
        );
    }
}
