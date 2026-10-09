<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class AnonymousProxyRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_ANONYMOUS_PROXY';

    public function __construct(
        private readonly int $vpnScore = 35,
        private readonly int $torScore = 55
    ) {
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Anonymous Proxy / VPN / Tor Network Detection';
    }

    public function getDefaultWeight(): int
    {
        return max($this->vpnScore, $this->torScore);
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        if ($context->isTor()) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->torScore,
                severity: 'critical',
                description: 'Connection originated from a known Tor exit node.',
                metadata: [
                    'client_ip' => $context->getClientIp(),
                    'is_tor' => true,
                    'is_proxy_or_vpn' => $context->isProxyOrVpn(),
                ]
            );
        }

        if ($context->isProxyOrVpn()) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->vpnScore,
                severity: 'high',
                description: 'Connection routed through a commercial VPN, hosting provider, or public proxy.',
                metadata: [
                    'client_ip' => $context->getClientIp(),
                    'is_tor' => false,
                    'is_proxy_or_vpn' => true,
                ]
            );
        }

        return null;
    }
}
