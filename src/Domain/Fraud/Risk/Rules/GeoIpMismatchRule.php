<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class GeoIpMismatchRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_GEO_IP_MISMATCH';

    public function __construct(
        private readonly int $score = 25
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Billing Country vs Connection IP Geolocation Mismatch';
    }

    public function getDefaultWeight(): int
    {
        return $this->score;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $billingCountry = $context->getBillingCountry();
        $geoIpCountry = $context->getGeoIpCountry();

        if ($billingCountry === null || $geoIpCountry === null) {
            return null;
        }

        $billingUpper = strtoupper(trim($billingCountry));
        $geoUpper = strtoupper(trim($geoIpCountry));

        if ($billingUpper !== '' && $geoUpper !== '' && $billingUpper !== $geoUpper) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->score,
                severity: 'medium',
                description: sprintf(
                    'Declared billing country "%s" does not match detected IP geolocation country "%s".',
                    $billingUpper,
                    $geoUpper
                ),
                metadata: [
                    'billing_country' => $billingUpper,
                    'geo_ip_country' => $geoUpper,
                    'client_ip' => $context->getClientIp(),
                ]
            );
        }

        return null;
    }
}
