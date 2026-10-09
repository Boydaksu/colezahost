<?php

declare(strict_types=1);

namespace Tests\Unit\Fraud;

use Coleza\Domain\Fraud\Linkage\AccountLinkageService;
use Coleza\Domain\Fraud\Lists\FraudListEntryType;
use Coleza\Domain\Fraud\Lists\FraudListService;
use Coleza\Domain\Fraud\Lists\FraudListType;
use Coleza\Domain\Fraud\Risk\RiskContext;
use Coleza\Domain\Fraud\Risk\RiskDecision;
use Coleza\Domain\Fraud\Risk\RiskScoreService;
use Coleza\Domain\Fraud\Risk\Rules\ListMatchingRule;
use Coleza\Domain\Fraud\Risk\Rules\RelatedAccountsRiskRule;
use Coleza\Domain\Fraud\Risk\Rules\VelocityRiskRule;
use Coleza\Domain\Fraud\Velocity\VelocityTrackerService;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class VelocityAndListsTest extends TestCase
{
    private Connection $db;
    private FraudListService $listService;
    private VelocityTrackerService $velocityService;
    private AccountLinkageService $linkageService;
    private RiskScoreService $riskScoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->listService = new FraudListService($this->db);
        $this->listService->ensureTables();

        $this->velocityService = new VelocityTrackerService($this->db);
        $this->velocityService->ensureTables();

        $this->linkageService = new AccountLinkageService($this->db, $this->listService);

        $this->riskScoreService = new RiskScoreService($this->db);
        $this->riskScoreService->ensureTables();
    }

    public function testFraudListExactAndCidrIpMatching(): void
    {
        // Exact IP deny
        $this->listService->addEntry(
            listType: FraudListType::DENY,
            entryType: FraudListEntryType::IP,
            value: '198.51.100.99',
            reason: 'Known malicious bot scanner'
        );

        // CIDR subnet deny
        $this->listService->addEntry(
            listType: FraudListType::DENY,
            entryType: FraudListEntryType::IP,
            value: '10.200.0.0/16',
            reason: 'High-risk rogue subnet'
        );

        $this->assertSame(FraudListType::DENY, $this->listService->checkValue(FraudListEntryType::IP, '198.51.100.99'));
        $this->assertSame(FraudListType::DENY, $this->listService->checkValue(FraudListEntryType::IP, '10.200.45.12'));
        $this->assertNull($this->listService->checkValue(FraudListEntryType::IP, '10.201.1.1'));
        $this->assertNull($this->listService->checkValue(FraudListEntryType::IP, '198.51.100.1'));
    }

    public function testFraudListWildcardEmailAndDomainMatching(): void
    {
        // Wildcard email pattern
        $this->listService->addEntry(
            listType: FraudListType::DENY,
            entryType: FraudListEntryType::EMAIL,
            value: '*@fraudsyndicate.org',
            reason: 'Organized carding domain'
        );

        // Exact email watch
        $this->listService->addEntry(
            listType: FraudListType::WATCH,
            entryType: FraudListEntryType::EMAIL,
            value: 'suspect@partner.com',
            reason: 'Flagged by manual review'
        );

        $this->assertSame(FraudListType::DENY, $this->listService->checkValue(FraudListEntryType::EMAIL, 'buyer1@fraudsyndicate.org'));
        $this->assertSame(FraudListType::DENY, $this->listService->checkValue(FraudListEntryType::EMAIL, 'admin@fraudsyndicate.org'));
        $this->assertSame(FraudListType::WATCH, $this->listService->checkValue(FraudListEntryType::EMAIL, 'suspect@partner.com'));
        $this->assertNull($this->listService->checkValue(FraudListEntryType::EMAIL, 'innocent@partner.com'));
    }

    public function testExpiredAndDeactivatedListEntriesAreIgnored(): void
    {
        $past = (new DateTimeImmutable())->modify('-1 hour');
        $expired = $this->listService->addEntry(
            listType: FraudListType::DENY,
            entryType: FraudListEntryType::IP,
            value: '203.0.113.50',
            reason: 'Temporary quarantine',
            expiresAt: $past
        );

        $this->assertTrue($expired->isExpired());
        $this->assertNull($this->listService->checkValue(FraudListEntryType::IP, '203.0.113.50'));

        $activeEntry = $this->listService->addEntry(
            listType: FraudListType::DENY,
            entryType: FraudListEntryType::IP,
            value: '203.0.113.60',
            reason: 'To be deactivated'
        );
        $this->assertSame(FraudListType::DENY, $this->listService->checkValue(FraudListEntryType::IP, '203.0.113.60'));

        $this->listService->deactivateEntry($activeEntry->getId());
        $this->assertNull($this->listService->checkValue(FraudListEntryType::IP, '203.0.113.60'));
    }

    public function testListMatchingRuleIntegrationInRiskScoring(): void
    {
        $this->listService->addEntry(
            listType: FraudListType::DENY,
            entryType: FraudListEntryType::IP,
            value: '185.220.101.50',
            reason: 'Confirmed bulletproof host'
        );

        $listRule = new ListMatchingRule($this->listService);
        $this->riskScoreService->registerRule($listRule);

        $context = new RiskContext(
            entityType: 'order',
            entityId: 701,
            userId: 21,
            email: 'user@clean.com',
            clientIp: '185.220.101.50',
            orderAmount: 20.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 5
        );

        $result = $this->riskScoreService->evaluate($context);

        $this->assertSame(100, $result->getTotalScore());
        $this->assertSame(RiskDecision::REJECT, $result->getDecision());
        $this->assertTrue($result->hasSignal(ListMatchingRule::RULE_DENYLIST_MATCH));

        $signal = $result->getSignal(ListMatchingRule::RULE_DENYLIST_MATCH);
        $this->assertNotNull($signal);
        $this->assertSame(100, $signal->getScore());
        $this->assertSame('critical', $signal->getSeverity());
        $this->assertStringContainsString('Confirmed bulletproof host', $signal->getDescription());
    }

    public function testVelocityTrackerCountsEventsWithinWindow(): void
    {
        $ip = '198.51.100.44';

        // 3 recent events
        $this->velocityService->recordEvent('order_attempt', 'ip', $ip);
        $this->velocityService->recordEvent('order_attempt', 'ip', $ip);
        $this->velocityService->recordEvent('order_attempt', 'ip', $ip);

        // 1 event recorded 2 hours ago (outside 3600s window)
        $twoHoursAgo = (new DateTimeImmutable())->modify('-7200 seconds');
        $this->velocityService->recordEvent('order_attempt', 'ip', $ip, [], $twoHoursAgo);

        $count = $this->velocityService->getIpOrderVelocity($ip, 3600);
        $this->assertSame(3, $count);

        $allCount = $this->velocityService->getIpOrderVelocity($ip, 86400);
        $this->assertSame(4, $allCount);
    }

    public function testVelocityRiskRuleFlagsExcessiveOrderAndPaymentBurst(): void
    {
        $ip = '192.0.2.77';
        $userId = 55;

        // Record 5 orders for IP (threshold is 4)
        for ($i = 0; $i < 5; $i++) {
            $this->velocityService->recordEvent('order_attempt', 'ip', $ip);
        }

        // Record 3 orders for user (threshold is 2)
        for ($i = 0; $i < 3; $i++) {
            $this->velocityService->recordEvent('order_attempt', 'user_id', (string) $userId);
        }

        // Record 4 payment failures for IP (threshold is 3)
        for ($i = 0; $i < 4; $i++) {
            $this->velocityService->recordEvent('payment_failure', 'ip', $ip);
        }

        $rule = new VelocityRiskRule($this->velocityService);
        $this->riskScoreService->registerRule($rule);

        $context = new RiskContext(
            entityType: 'order',
            entityId: 801,
            userId: $userId,
            email: 'busy@client.com',
            clientIp: $ip,
            orderAmount: 30.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 2
        );

        $result = $this->riskScoreService->evaluate($context);

        $this->assertTrue($result->hasSignal(VelocityRiskRule::RULE_CODE));
        $signal = $result->getSignal(VelocityRiskRule::RULE_CODE);
        $this->assertNotNull($signal);
        // 35 (ip velocity) + 30 (user velocity) + 45 (payment failures) = 110 clamped to 100
        $this->assertSame(100, $signal->getScore());
        $this->assertSame('high', $signal->getSeverity());
        $this->assertSame(RiskDecision::REJECT, $result->getDecision());
    }

    public function testAccountLinkageDetectsMultiAccountSyndicates(): void
    {
        $sharedIp = '203.0.113.88';

        // Record events for user #101, #102, #103 under same IP
        $this->velocityService->recordEvent('order_attempt', 'ip', $sharedIp, ['user_id' => 101]);
        $this->velocityService->recordEvent('order_attempt', 'ip', $sharedIp, ['user_id' => 102]);
        $this->velocityService->recordEvent('order_attempt', 'ip', $sharedIp, ['user_id' => 103]);

        $linkage = $this->linkageService->findLinkedAccounts(subjectUserId: 101, clientIp: $sharedIp);

        $this->assertSame(2, $linkage->getLinkedCount()); // 102 and 103
        $this->assertContains(102, $linkage->getLinkedUserIds());
        $this->assertContains(103, $linkage->getLinkedUserIds());
        $this->assertFalse($linkage->hasFraudulentHistory());
    }

    public function testAccountLinkageFlagsLinkedPriorFraudulentAccounts(): void
    {
        $sharedIp = '203.0.113.99';
        $fraudulentUser = 202;
        $subjectUser = 201;

        // User #202 placed a rejected order
        $badContext = new RiskContext(
            entityType: 'order',
            entityId: 901,
            userId: $fraudulentUser,
            email: 'carder@throwawaymail.com',
            clientIp: $sharedIp,
            orderAmount: 3500.0,
            isProxyOrVpn: true,
            isTor: true
        );
        $this->riskScoreService->evaluate($badContext);

        // Record velocity event linking user #202 and user #201
        $this->velocityService->recordEvent('order_attempt', 'ip', $sharedIp, ['user_id' => $fraudulentUser]);
        $this->velocityService->recordEvent('order_attempt', 'ip', $sharedIp, ['user_id' => $subjectUser]);

        $linkRule = new RelatedAccountsRiskRule($this->linkageService);
        $this->riskScoreService->registerRule($linkRule);

        $subjectContext = new RiskContext(
            entityType: 'order',
            entityId: 902,
            userId: $subjectUser,
            email: 'newbie@domain.com',
            clientIp: $sharedIp,
            orderAmount: 45.0,
            isNewCustomer: false,
            priorSuccessfulOrdersCount: 1
        );

        $result = $this->riskScoreService->evaluate($subjectContext);

        $this->assertTrue($result->hasSignal(RelatedAccountsRiskRule::RULE_CODE));
        $signal = $result->getSignal(RelatedAccountsRiskRule::RULE_CODE);
        $this->assertNotNull($signal);
        $this->assertSame(70, $signal->getScore());
        $this->assertSame('critical', $signal->getSeverity());
        $this->assertStringContainsString('confirmed fraudulent/blacklisted user account', $signal->getDescription());
        $this->assertSame(RiskDecision::REJECT, $result->getDecision());
    }
}
