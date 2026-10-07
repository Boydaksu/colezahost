<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Events;

use DateTimeImmutable;
use Coleza\Foundation\Container\Container;
use Coleza\Foundation\Events\EventBus;
use Coleza\Foundation\Events\EventInterface;
use Coleza\Foundation\Events\ListenerInterface;
use PHPUnit\Framework\TestCase;

class OrderPaidEvent implements EventInterface
{
    private DateTimeImmutable $occurredAt;

    public function __construct(public int $orderId, public float $amount)
    {
        $this->occurredAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventName(): string
    {
        return 'order.paid';
    }
}

class ProvisionServiceListener implements ListenerInterface
{
    public static array $dispatchedEvents = [];

    public function handle(EventInterface $event): void
    {
        self::$dispatchedEvents[] = $event;
    }
}

final class EventBusTest extends TestCase
{
    protected function setUp(): void
    {
        ProvisionServiceListener::$dispatchedEvents = [];
    }

    public function testPublishesEventToMultipleListeners(): void
    {
        $container = new Container();
        $bus = new EventBus($container);

        $callableLog = [];

        $bus->listen(OrderPaidEvent::class, ProvisionServiceListener::class);
        $bus->listen(OrderPaidEvent::class, function (OrderPaidEvent $e) use (&$callableLog): void {
            $callableLog[] = 'callable_received:' . $e->orderId;
        });

        $event = new OrderPaidEvent(101, 49.99);
        $bus->dispatch($event);

        $this->assertCount(1, ProvisionServiceListener::$dispatchedEvents);
        $this->assertSame($event, ProvisionServiceListener::$dispatchedEvents[0]);
        $this->assertSame(['callable_received:101'], $callableLog);
    }
}
