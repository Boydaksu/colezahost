<?php

declare(strict_types=1);

namespace Tests\Unit\Fraud;

use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskDecision;
use Coleza\Domain\Fraud\Risk\RiskScoreService;
use Coleza\Domain\Fraud\Risk\RiskSignal;
use Coleza\Domain\Fraud\Risk\Rules\AnonymousProxyRule;
use Coleza\Domain\Fraud\Risk\Rules\DisposableEmailRule;
use Coleza\Domain\Fraud\Risk\Rules\GeoIpMismatchRule;
use Coleza\Domain\Fraud\Risk\Rules\HighOrderValueRule;
use Coleza\Domain\Fraud\Risk\Rules\NewCustomerHighValueRule;
use Coleza\Domain\Fraud\Risk\Rules\PaymentCountryMismatchRule;
use Coleza\Domain\Fraud\Risk\Rules\RiskRuleInterface;
use Coleza\Foundation\Database\Connection;
use PHPUnit\Framework\TestCase;

final class RuleBasedRiskScoringTest extends TestCase
{
    private Connection $db;
    private RiskScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new \PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->service = new RiskScoreService($this->db);
        $this->service->ensureTables();
    }

    public function testCleanOrderResultsInAcceptanceWithZeroScore(): void
    {
        $context = new RiskContext(
            entityType: 'order',
            entityId: 101,
            userId: 5,
            organizationId: 2,
            email: 'legit.customer@gmail.com',
            clientIp: '198.51.100.12',
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            billingCountry: 'US',
            geoIpCountry: 'US',
            isProxyOrVpn: false,
            isTor: false,
            paymentMethod: 'credit_card',
            cardIssuingCountry: 'US',
            cardIsPrepaid: false,
            orderAmount: 24.99,
            currency: 'USD',
            orderItemCount: 1,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 4
        );

        $result = $this->service->evaluate($context);

        $this->assertSame(0, $result->getTotalScore());
        $this->assertSame(RiskDecision::ACCEPT, $result->getDecision());
        $this->assertTrue($result->isAccept());
        $this->assertFalse($result->isReview());
        $this->assertFalse($result->isReject());
        $this->assertSame(0, $result->getSignalsCount());
        $this->assertEmpty($result->getSignals());
    }

    public function testDisposableEmailTriggersReviewScore(): void
    {
        $context = new RiskContext(
            entityType: 'order',
            entityId: 102,
            userId: 6,
            email: 'burner_user@mailinator.com',
            billingCountry: 'CA',
            geoIpCountry: 'CA',
            orderAmount: 50.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 1
        );

        $result = $this->service->evaluate($context);

        $this->assertSame(45, $result->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $result->getDecision());
        $this->assertTrue($result->isReview());
        $this->assertSame(1, $result->getSignalsCount());
        $this->assertTrue($result->hasSignal(DisposableEmailRule::RULE_CODE));

        $signal = $result->getSignal(DisposableEmailRule::RULE_CODE);
        $this->assertNotNull($signal);
        $this->assertSame(45, $signal->getScore());
        $this->assertSame('high', $signal->getSeverity());
        $this->assertStringContainsString('mailinator.com', $signal->getDescription());
    }

    public function testAnonymousProxyAndTorDetection(): void
    {
        // VPN test
        $vpnContext = new RiskContext(
            entityType: 'order',
            entityId: 103,
            userId: 7,
            email: 'user@example.com',
            clientIp: '185.220.101.5',
            billingCountry: 'US',
            geoIpCountry: 'US',
            isProxyOrVpn: true,
            isTor: false,
            orderAmount: 40.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 2
        );

        $vpnResult = $this->service->evaluate($vpnContext);
        $this->assertSame(35, $vpnResult->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $vpnResult->getDecision());
        $this->assertTrue($vpnResult->hasSignal(AnonymousProxyRule::RULE_CODE));
        $this->assertSame('high', $vpnResult->getSignal(AnonymousProxyRule::RULE_CODE)?->getSeverity());

        // Tor test
        $torContext = new RiskContext(
            entityType: 'order',
            entityId: 104,
            userId: 8,
            email: 'anon@example.com',
            clientIp: '185.220.101.7',
            billingCountry: 'US',
            geoIpCountry: 'US',
            isProxyOrVpn: false,
            isTor: true,
            orderAmount: 40.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 2
        );

        $torResult = $this->service->evaluate($torContext);
        $this->assertSame(55, $torResult->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $torResult->getDecision());
        $this->assertTrue($torResult->hasSignal(AnonymousProxyRule::RULE_CODE));
        $this->assertSame('critical', $torResult->getSignal(AnonymousProxyRule::RULE_CODE)?->getSeverity());
    }

    public function testGeoIpAndPaymentCountryMismatch(): void
    {
        $context = new RiskContext(
            entityType: 'payment',
            entityId: 201,
            userId: 9,
            email: 'shopper@company.co.uk',
            clientIp: '91.198.174.192',
            billingCountry: 'GB',
            geoIpCountry: 'NL',
            paymentMethod: 'card',
            cardIssuingCountry: 'US',
            cardIsPrepaid: true,
            orderAmount: 80.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 3
        );

        $result = $this->service->evaluate($context);

        $this->assertTrue($result->hasSignal(GeoIpMismatchRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(PaymentCountryMismatchRule::RULE_CODE));

        $geoSignal = $result->getSignal(GeoIpMismatchRule::RULE_CODE);
        $this->assertSame(25, $geoSignal?->getScore());

        $paymentSignal = $result->getSignal(PaymentCountryMismatchRule::RULE_CODE);
        // 25 (mismatch) + 15 (prepaid) = 40
        $this->assertSame(40, $paymentSignal?->getScore());
        $this->assertSame('high', $paymentSignal?->getSeverity());

        // Total score = 25 + 40 = 65 -> Review
        $this->assertSame(65, $result->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $result->getDecision());
    }

    public function testHighOrderValueAndNewCustomerRules(): void
    {
        // New customer ordering $600
        $context = new RiskContext(
            entityType: 'order',
            entityId: 301,
            userId: 10,
            email: 'newuser@domain.com',
            billingCountry: 'DE',
            geoIpCountry: 'DE',
            orderAmount: 600.0,
            isNewCustomer: true,
            priorSuccessfulOrdersCount: 0
        );

        $result = $this->service->evaluate($context);

        // HighOrderValueRule medium tier = 20, NewCustomerHighValueRule = 30 -> 50 total
        $this->assertSame(50, $result->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $result->getDecision());
        $this->assertTrue($result->hasSignal(HighOrderValueRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(NewCustomerHighValueRule::RULE_CODE));

        // Repeat customer ordering $2,500
        $repeatContext = new RiskContext(
            entityType: 'order',
            entityId: 302,
            userId: 11,
            email: 'vip@domain.com',
            billingCountry: 'DE',
            geoIpCountry: 'DE',
            orderAmount: 2500.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 12
        );

        $repeatResult = $this->service->evaluate($repeatContext);
        // Only HighOrderValueRule high tier = 40
        $this->assertSame(40, $repeatResult->getTotalScore());
        $this->assertTrue($repeatResult->hasSignal(HighOrderValueRule::RULE_CODE));
        $this->assertFalse($repeatResult->hasSignal(NewCustomerHighValueRule::RULE_CODE));
    }

    public function testCumulativeMultiVectorFraudResultsInHardReject(): void
    {
        // Attacker uses disposable inbox + Tor exit node + Geo IP mismatch + prepaid card + high order
        $context = new RiskContext(
            entityType: 'order',
            entityId: 401,
            userId: 99,
            organizationId: 10,
            email: 'fraudster@guerrillamail.com',
            clientIp: '185.220.101.99',
            billingCountry: 'FR',
            geoIpCountry: 'RU',
            isProxyOrVpn: true,
            isTor: true,
            paymentMethod: 'stripe',
            cardIssuingCountry: 'BR',
            cardIsPrepaid: true,
            orderAmount: 2400.0,
            isNewCustomer: true,
            priorSuccessfulOrdersCount: 0
        );

        $result = $this->service->evaluate($context);

        // Clamped at 100
        $this->assertSame(100, $result->getTotalScore());
        $this->assertSame(RiskDecision::REJECT, $result->getDecision());
        $this->assertTrue($result->isReject());
        $this->assertFalse($result->isAccept());
        $this->assertFalse($result->isReview());

        // 6 signals triggered
        $this->assertGreaterThanOrEqual(5, $result->getSignalsCount());
        $this->assertTrue($result->hasSignal(DisposableEmailRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(AnonymousProxyRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(GeoIpMismatchRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(PaymentCountryMismatchRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(HighOrderValueRule::RULE_CODE));
        $this->assertTrue($result->hasSignal(NewCustomerHighValueRule::RULE_CODE));
    }

    public function testEvaluationPersistenceAndRetrieval(): void
    {
        $context = new RiskContext(
            entityType: 'order',
            entityId: 501,
            userId: 42,
            organizationId: 7,
            email: 'hacker@yopmail.com',
            clientIp: '192.0.2.1',
            billingCountry: 'US',
            geoIpCountry: 'US',
            orderAmount: 120.0
        );

        $result = $this->service->evaluate($context);
        $this->assertNotNull($result->getId());

        $fetched = $this->service->getEvaluation($result->getId());
        $this->assertNotNull($fetched);
        $this->assertSame($result->getId(), $fetched->getId());
        $this->assertSame('order', $fetched->getEntityType());
        $this->assertSame(501, $fetched->getEntityId());
        $this->assertSame(42, $fetched->getUserId());
        $this->assertSame(7, $fetched->getOrganizationId());
        $this->assertSame($result->getTotalScore(), $fetched->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $fetched->getDecision());
        $this->assertSame(1, $fetched->getSignalsCount());

        $snapshot = $fetched->getContextSnapshot();
        $this->assertSame('hacker@yopmail.com', $snapshot['email']);
        $this->assertEquals(120.0, $snapshot['order_amount']);

        // Entity retrieval
        $byEntity = $this->service->getEvaluationsForEntity('order', 501);
        $this->assertCount(1, $byEntity);
        $this->assertSame($result->getId(), $byEntity[0]->getId());

        $latest = $this->service->getLatestEvaluationForEntity('order', 501);
        $this->assertNotNull($latest);
        $this->assertSame($result->getId(), $latest->getId());

        // User retrieval
        $byUser = $this->service->getEvaluationsForUser(42);
        $this->assertCount(1, $byUser);
        $this->assertSame($result->getId(), $byUser[0]->getId());
    }

    public function testCustomRuleCanBeRegisteredAndEvaluated(): void
    {
        $customRule = new class implements RiskRuleInterface {
            public function getRuleCode(): string
            {
                return 'RULE_SUSPICIOUS_DOMAIN_TLD';
            }

            public function getRuleName(): string
            {
                return 'Suspicious Top-Level Domain in Email';
            }

            public function getDefaultWeight(): int
            {
                return 30;
            }

            public function evaluate(RiskContext $context): ?RiskSignal
            {
                $email = $context->getEmail();
                if ($email !== null && str_ends_with(strtolower($email), '.top')) {
                    return new RiskSignal(
                        ruleCode: 'RULE_SUSPICIOUS_DOMAIN_TLD',
                        score: 30,
                        severity: 'medium',
                        description: 'Email top-level domain .top frequently associated with malicious abuse.'
                    );
                }
                return null;
            }
        };

        $this->service->registerRule($customRule);

        $context = new RiskContext(
            entityType: 'identity',
            userId: 88,
            email: 'spammer@randomhost.top'
        );

        $result = $this->service->evaluate($context);
        $this->assertTrue($result->hasSignal('RULE_SUSPICIOUS_DOMAIN_TLD'));
        $this->assertSame(30, $result->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $result->getDecision());
    }

    public function testCustomThresholdsAdjustDecision(): void
    {
        $context = new RiskContext(
            entityType: 'order',
            entityId: 601,
            userId: 12,
            email: 'user@example.com',
            orderAmount: 600.0, // High order value = 20 score
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 5
        );

        // Standard thresholds: 30 review, 70 reject -> score 20 is ACCEPT
        $defaultResult = $this->service->evaluate($context);
        $this->assertSame(20, $defaultResult->getTotalScore());
        $this->assertSame(RiskDecision::ACCEPT, $defaultResult->getDecision());

        // Stricter thresholds: 15 review, 50 reject -> score 20 is REVIEW
        $strictResult = $this->service->evaluate($context, reviewThreshold: 15, rejectThreshold: 50, persist: false);
        $this->assertSame(20, $strictResult->getTotalScore());
        $this->assertSame(RiskDecision::REVIEW, $strictResult->getDecision());

        // Extreme thresholds: 10 review, 15 reject -> score 20 is REJECT
        $ultraStrict = $this->service->evaluate($context, reviewThreshold: 10, rejectThreshold: 15, persist: false);
        $this->assertSame(20, $ultraStrict->getTotalScore());
        $this->assertSame(RiskDecision::REJECT, $ultraStrict->getDecision());
    }
}
