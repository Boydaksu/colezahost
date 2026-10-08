<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\Catalog\TldPricing;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Lifecycle\DomainExpiryService;
use Coleza\Domain\Domains\Lifecycle\DomainLifecyclePolicy;
use Coleza\Domain\Domains\Lifecycle\DomainRenewalReminder;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainExpiryAndReminderTest extends TestCase
{
    private Connection $db;
    private DomainCatalogService $catalog;
    private DomainService $domainService;
    private DomainExpiryService $expiryService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->catalog = new DomainCatalogService($this->db);
        $this->catalog->ensureTables();

        $this->domainService = new DomainService($this->db, $this->catalog);
        $this->domainService->ensureTables();

        // Register .com with standard 30d grace, 30d redemption
        $tld = $this->catalog->registerTld([
            'extension' => '.com',
            'is_active' => 1,
            'grace_period_days' => 30,
            'redemption_period_days' => 30,
        ]);

        $this->catalog->setTldPricing(
            tldId: $tld->getId(),
            operation: TldPricing::OPERATION_RENEW,
            years: 1,
            priceMinor: 1499,
            currencyCode: 'USD'
        );

        $this->catalog->setTldPricing(
            tldId: $tld->getId(),
            operation: TldPricing::OPERATION_RESTORE,
            years: 1,
            priceMinor: 8999, // $89.99 redemption restore fee
            currencyCode: 'USD'
        );

        $this->expiryService = new DomainExpiryService(
            $this->db,
            $this->domainService,
            $this->catalog,
            new DomainLifecyclePolicy(30, 30)
        );
        $this->expiryService->ensureTables();
    }

    public function testDomainLifecycleMetadataComputation(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'metadata-test.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
        ]);
        $this->domainService->activateDomain($domain->getId(), expiryDate: '2026-12-31');

        // 1. Reference date well before expiry: 2026-11-16 (45 days before)
        $refBefore = new DateTimeImmutable('2026-11-16');
        $meta = $this->expiryService->getDomainLifecycleMetadata($domain->getId(), $refBefore);

        $this->assertSame(45, $meta['days_until_expiry']);
        $this->assertSame('active', $meta['lifecycle_stage']);
        $this->assertTrue($meta['is_renewable']);
        $this->assertFalse($meta['is_restorable']);
        $this->assertSame('2027-01-30', $meta['grace_ends_at']); // 30 days after 2026-12-31
        $this->assertSame('2027-03-01', $meta['redemption_ends_at']); // 60 days after 2026-12-31
        $this->assertSame(1499, $meta['renewal_price_minor']);
        $this->assertNull($meta['restore_price_minor']);

        // 2. Reference date during grace: 2027-01-10 (10 days past expiry)
        $refGrace = new DateTimeImmutable('2027-01-10');
        $metaGrace = $this->expiryService->getDomainLifecycleMetadata($domain->getId(), $refGrace);
        $this->assertSame(-10, $metaGrace['days_until_expiry']);
        $this->assertSame('grace', $metaGrace['lifecycle_stage']);
        $this->assertTrue($metaGrace['is_renewable']);
        $this->assertFalse($metaGrace['is_restorable']);

        // 3. Reference date during redemption: 2027-02-10 (41 days past expiry)
        $refRedemption = new DateTimeImmutable('2027-02-10');
        $metaRedemption = $this->expiryService->getDomainLifecycleMetadata($domain->getId(), $refRedemption);
        $this->assertSame('redemption', $metaRedemption['lifecycle_stage']);
        $this->assertFalse($metaRedemption['is_renewable']);
        $this->assertTrue($metaRedemption['is_restorable']);
        $this->assertSame(8999, $metaRedemption['restore_price_minor']);

        // 4. Reference date after redemption: 2027-03-15 (74 days past expiry)
        $refCancelled = new DateTimeImmutable('2027-03-15');
        $metaCancelled = $this->expiryService->getDomainLifecycleMetadata($domain->getId(), $refCancelled);
        $this->assertSame('cancelled', $metaCancelled['lifecycle_stage']);
        $this->assertFalse($metaCancelled['is_renewable']);
        $this->assertFalse($metaCancelled['is_restorable']);
    }

    public function testEvaluateAndAdvanceDomainLifecyclesThroughAllStages(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'lifecycle-progression.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
        ]);
        $this->domainService->activateDomain($domain->getId(), expiryDate: '2026-06-01');

        // Stage 1: Advance past expiry (2026-06-05) -> must enter GRACE
        $res1 = $this->expiryService->evaluateAndAdvanceDomainLifecycles(new DateTimeImmutable('2026-06-05'));
        $this->assertSame(1, $res1['transitioned_to_grace']);
        $this->assertSame(0, $res1['transitioned_to_redemption']);
        $this->assertSame(0, $res1['cancelled']);

        $domainFresh1 = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_GRACE, $domainFresh1->getStatus());

        // Stage 2: Advance past grace period (2026-07-10, >30 days past 2026-06-01) -> must enter REDEMPTION
        $res2 = $this->expiryService->evaluateAndAdvanceDomainLifecycles(new DateTimeImmutable('2026-07-10'));
        $this->assertSame(0, $res2['transitioned_to_grace']);
        $this->assertSame(1, $res2['transitioned_to_redemption']);
        $this->assertSame(0, $res2['cancelled']);

        $domainFresh2 = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_REDEMPTION, $domainFresh2->getStatus());

        // Stage 3: Advance past redemption period (2026-08-15, >60 days past 2026-06-01) -> must be CANCELLED
        $res3 = $this->expiryService->evaluateAndAdvanceDomainLifecycles(new DateTimeImmutable('2026-08-15'));
        $this->assertSame(0, $res3['transitioned_to_grace']);
        $this->assertSame(0, $res3['transitioned_to_redemption']);
        $this->assertSame(1, $res3['cancelled']);

        $domainFresh3 = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_CANCELLED, $domainFresh3->getStatus());

        // Verify timeline contains full audit trail
        $timeline = $this->domainService->getTimeline($domain->getId());
        $eventTypes = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('domain_entered_grace', $eventTypes);
        $this->assertContains('domain_entered_redemption', $eventTypes);
        $this->assertContains('domain_lifecycle_cancelled', $eventTypes);
    }

    public function testDispatchDueRemindersAtIcannErrpWindows(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'errp-reminders.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
        ]);
        $this->domainService->activateDomain($domain->getId(), expiryDate: '2026-12-31');

        $this->domainService->setContact(
            domainId: $domain->getId(),
            contactType: DomainContact::TYPE_REGISTRANT,
            data: [
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'email' => 'alice@errp-test.com',
                'phone' => '+1.5551234567',
                'address_line_1' => '100 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ]
        );

        $sentNotifications = [];
        $notifier = function (string $domain, string $type, string $email, int $days) use (&$sentNotifications) {
            $sentNotifications[] = [
                'domain' => $domain,
                'type' => $type,
                'email' => $email,
                'days' => $days,
            ];
        };

        // Window 1: 30 days before (2026-12-01)
        $res30d = $this->expiryService->dispatchDueReminders(new DateTimeImmutable('2026-12-01'), $notifier);
        $this->assertSame(1, $res30d['reminders_sent']);
        $this->assertSame(DomainRenewalReminder::TYPE_BEFORE_30D, $sentNotifications[0]['type']);
        $this->assertSame('alice@errp-test.com', $sentNotifications[0]['email']);

        // Window 2: 7 days before (2026-12-24)
        $res7d = $this->expiryService->dispatchDueReminders(new DateTimeImmutable('2026-12-24'), $notifier);
        $this->assertSame(1, $res7d['reminders_sent']);
        $this->assertSame(DomainRenewalReminder::TYPE_BEFORE_7D, $sentNotifications[1]['type']);

        // Window 3: 1 day before (2026-12-30)
        $res1d = $this->expiryService->dispatchDueReminders(new DateTimeImmutable('2026-12-30'), $notifier);
        $this->assertSame(1, $res1d['reminders_sent']);
        $this->assertSame(DomainRenewalReminder::TYPE_BEFORE_1D, $sentNotifications[2]['type']);

        // Window 4: 1 day after expiry (2027-01-01)
        $resPost1d = $this->expiryService->dispatchDueReminders(new DateTimeImmutable('2027-01-01'), $notifier);
        $this->assertSame(1, $resPost1d['reminders_sent']);
        $this->assertSame(DomainRenewalReminder::TYPE_AFTER_1D, $sentNotifications[3]['type']);

        // Window 5: 5 days after expiry (2027-01-05)
        $resPost5d = $this->expiryService->dispatchDueReminders(new DateTimeImmutable('2027-01-05'), $notifier);
        $this->assertSame(1, $resPost5d['reminders_sent']);
        $this->assertSame(DomainRenewalReminder::TYPE_AFTER_5D, $sentNotifications[4]['type']);

        // Verify reminders logged
        $remindersList = $this->expiryService->listRemindersForDomain($domain->getId());
        $this->assertCount(5, $remindersList);
    }

    public function testRemindersStrictDeduplication(): void
    {
        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'dedup-test.com',
            'status' => DomainStateMachine::STATUS_ACTIVE,
        ]);
        $this->domainService->activateDomain($domain->getId(), expiryDate: '2026-12-31');

        $refDate = new DateTimeImmutable('2026-12-01'); // 30 days before

        // First run: dispatches
        $run1 = $this->expiryService->dispatchDueReminders($refDate);
        $this->assertSame(1, $run1['reminders_sent']);
        $this->assertSame(0, $run1['skipped_already_sent']);

        // Second run on same day: must be skipped
        $run2 = $this->expiryService->dispatchDueReminders($refDate);
        $this->assertSame(0, $run2['reminders_sent']);
        $this->assertSame(1, $run2['skipped_already_sent']);

        // Verify hasReminderBeenSent
        $this->assertTrue($this->expiryService->hasReminderBeenSent(
            domainId: $domain->getId(),
            reminderType: DomainRenewalReminder::TYPE_BEFORE_30D,
            expiryDate: '2026-12-31'
        ));

        // Total reminders logged must still be 1
        $this->assertCount(1, $this->expiryService->listRemindersForDomain($domain->getId()));
    }
}
