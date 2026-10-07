<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Container;

use Coleza\Foundation\Container\Container;
use Coleza\Foundation\Container\ContainerException;
use Coleza\Foundation\Container\NotFoundException;
use Coleza\Foundation\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;

// Dummy classes for testing container dependency resolution
class DummyServiceA {}

class DummyServiceB {
    public function __construct(public DummyServiceA $serviceA) {}
}

class DummyCircularX {
    public function __construct(public DummyCircularY $y) {}
}

class DummyCircularY {
    public function __construct(public DummyCircularX $x) {}
}

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::setInstance($this->container);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton(DummyServiceA::class);

        $instance1 = $this->container->get(DummyServiceA::class);
        $instance2 = $this->container->get(DummyServiceA::class);

        $this->assertInstanceOf(DummyServiceA::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testBindReturnsNewInstanceEachTime(): void
    {
        $this->container->bind(DummyServiceA::class);

        $instance1 = $this->container->get(DummyServiceA::class);
        $instance2 = $this->container->get(DummyServiceA::class);

        $this->assertInstanceOf(DummyServiceA::class, $instance1);
        $this->assertNotSame($instance1, $instance2);
    }

    public function testAutowiresConstructorDependencies(): void
    {
        $instanceB = $this->container->get(DummyServiceB::class);

        $this->assertInstanceOf(DummyServiceB::class, $instanceB);
        $this->assertInstanceOf(DummyServiceA::class, $instanceB->serviceA);
    }

    public function testDetectsCircularDependency(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->container->get(DummyCircularX::class);
    }

    public function testThrowsNotFoundExceptionForNonExistentClass(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->get('NonExistent\\Class\\Path');
    }

    public function testServiceProviderRegistrationAndBoot(): void
    {
        $booted = false;

        $provider = new class($booted) implements ServiceProviderInterface {
            private bool $bRef;
            public function __construct(bool &$bRef) {
                $this->bRef = &$bRef;
            }
            public function register(Container $container): void {
                $container->instance('custom_key', 'registered_value');
            }
            public function boot(Container $container): void {
                $this->bRef = true;
            }
        };

        $this->container->register($provider);
        $this->assertSame('registered_value', $this->container->get('custom_key'));
        $this->assertFalse($booted);

        $this->container->bootProviders();
        $this->assertTrue($booted);
    }
}
