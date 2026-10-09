<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk\Rules;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskSignal;

final class DisposableEmailRule implements RiskRuleInterface
{
    public const RULE_CODE = 'RULE_DISPOSABLE_EMAIL';

    /**
     * @var array<string, bool>
     */
    private array $disposableDomains;

    /**
     * @param list<string> $customDisposableDomains
     */
    public function __construct(
        private readonly int $score = 45,
        array $customDisposableDomains = []
    ) {
        $defaultDomains = [
            'mailinator.com',
            'guerrillamail.com',
            'tempmail.com',
            '10minutemail.com',
            'throwawaymail.com',
            'yopmail.com',
            'sharklasers.com',
            'trashmail.com',
            'fakeinbox.com',
            'dispostable.com',
            'tempinbox.com',
            'tempmailaddress.com',
        ];

        $merged = array_unique(array_merge($defaultDomains, $customDisposableDomains));
        $this->disposableDomains = array_fill_keys(array_map('strtolower', $merged), true);
    }

    public function getRuleCode(): string
    {
        return self::RULE_CODE;
    }

    public function getRuleName(): string
    {
        return 'Disposable / Temporary Email Detection';
    }

    public function getDefaultWeight(): int
    {
        return $this->score;
    }

    public function evaluate(RiskContext $context): ?RiskSignal
    {
        $email = $context->getEmail();
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }

        $parts = explode('@', strtolower(trim($email)));
        $domain = end($parts);

        if (isset($this->disposableDomains[$domain])) {
            return new RiskSignal(
                ruleCode: self::RULE_CODE,
                score: $this->score,
                severity: 'high',
                description: sprintf('Customer email domain "%s" is identified as a disposable temporary inbox provider.', $domain),
                metadata: [
                    'email' => $email,
                    'domain' => $domain,
                ]
            );
        }

        return null;
    }
}
