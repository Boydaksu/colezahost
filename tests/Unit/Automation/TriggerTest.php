<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Triggers\ManualTrigger;
use Coleza\Domain\Automation\Triggers\ScheduleTrigger;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TriggerTest extends TestCase
{
    public function testTriggerContextDotNotation(): void
    {
        $context = new TriggerContext(
            eventName: 'invoice.overdue',
            payload: [
                'invoice' => [
                    'id' => 'INV-1001',
                    'amount' => 150.00,
                    'customer' => [
                        'email' => 'client@example.com',
                        'tier' => 'vip',
                    ],
                ],
                'days_past_due' => 5,
            ]
        );

        $this->assertSame('invoice.overdue', $context->getEventName());
        $this->assertSame('INV-1001', $context->get('invoice.id'));
        $this->assertSame(150.00, $context->get('invoice.amount'));
        $this->assertSame('client@example.com', $context->get('invoice.customer.email'));
        $this->assertSame('vip', $context->get('invoice.customer.tier'));
        $this->assertSame(5, $context->get('days_past_due'));
        $this->assertNull($context->get('non_existent.path'));
        $this->assertSame('fallback', $context->get('non_existent.path', 'fallback'));
        $this->assertTrue($context->has('invoice.id'));
        $this->assertFalse($context->has('invalid.key'));
    }

    public function testTriggerContextMutations(): void
    {
        $context = new TriggerContext('test.event', ['initial' => 'val']);
        $context->set('extra.data', 'updated');

        $this->assertSame('updated', $context->get('extra.data'));

        $immutableClone = $context->with('brand_id', 'BR-123');
        $this->assertSame('BR-123', $immutableClone->get('brand_id'));
        $this->assertNull($context->get('brand_id'));
    }

    public function testEventTriggerExactAndWildcardMatching(): void
    {
        $exactTrigger = new EventTrigger('service.created');
        $this->assertSame('event', $exactTrigger->getType());
        $this->assertSame('service.created', $exactTrigger->getName());

        $this->assertTrue($exactTrigger->matches(new TriggerContext('service.created')));
        $this->assertFalse($exactTrigger->matches(new TriggerContext('service.suspended')));

        $wildcardTrigger = new EventTrigger('service.*');
        $this->assertTrue($wildcardTrigger->matches(new TriggerContext('service.created')));
        $this->assertTrue($wildcardTrigger->matches(new TriggerContext('service.suspended')));
        $this->assertFalse($wildcardTrigger->matches(new TriggerContext('invoice.paid')));

        $catchAll = new EventTrigger('*');
        $this->assertTrue($catchAll->matches(new TriggerContext('anything.goes')));
    }

    public function testEventTriggerWithPayloadFilters(): void
    {
        $trigger = new EventTrigger('invoice.status_changed', [
            'status' => 'overdue',
            'severity' => 'critical',
        ]);

        $matchingContext = new TriggerContext('invoice.status_changed', [
            'status' => 'overdue',
            'severity' => 'critical',
            'amount' => 500,
        ]);
        $this->assertTrue($trigger->matches($matchingContext));

        $nonMatchingContext = new TriggerContext('invoice.status_changed', [
            'status' => 'paid',
            'severity' => 'critical',
        ]);
        $this->assertFalse($trigger->matches($nonMatchingContext));
    }

    public function testScheduleTriggerMatching(): void
    {
        $daily = new ScheduleTrigger('daily');
        $this->assertSame('schedule', $daily->getType());
        $this->assertSame('daily', $daily->getScheduleInterval());

        $this->assertTrue($daily->matches(new TriggerContext('schedule', ['schedule' => 'daily'])));
        $this->assertTrue($daily->matches(new TriggerContext('cron', ['interval' => 'daily'])));
        $this->assertTrue($daily->matches(new TriggerContext('schedule.daily')));

        $this->assertFalse($daily->matches(new TriggerContext('schedule', ['schedule' => 'hourly'])));
        $this->assertFalse($daily->matches(new TriggerContext('other.event')));
    }

    public function testManualTriggerMatching(): void
    {
        $genericManual = new ManualTrigger();
        $this->assertSame('manual', $genericManual->getType());
        $this->assertTrue($genericManual->matches(new TriggerContext('manual')));
        $this->assertTrue($genericManual->matches(new TriggerContext('manual.dispatch')));
        $this->assertFalse($genericManual->matches(new TriggerContext('auto.dispatch')));

        $scopedManual = new ManualTrigger('hosting_suspensions');
        $this->assertTrue($scopedManual->matches(new TriggerContext('manual', ['scope' => 'hosting_suspensions'])));
        $this->assertFalse($scopedManual->matches(new TriggerContext('manual', ['scope' => 'domain_renewals'])));
    }
}
