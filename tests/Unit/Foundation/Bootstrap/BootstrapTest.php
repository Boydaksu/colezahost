<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Bootstrap;

use Coleza\Foundation\Bootstrap\Bootstrap;
use Coleza\Foundation\Runtime\Environment;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    protected function tearDown(): void
    {
        Bootstrap::reset();
        parent::tearDown();
    }

    public function testBootSetsUtcTimezoneAndUtf8(): void
    {
        $env = new Environment(['APP_ENV' => 'testing']);
        $bootedEnv = Bootstrap::boot($env);

        $this->assertTrue(Bootstrap::isBooted());
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('testing', $bootedEnv->get('APP_ENV'));
    }

    public function testIdempotentBoot(): void
    {
        $env = new Environment(['APP_ENV' => 'testing']);
        $boot1 = Bootstrap::boot($env);
        $boot2 = Bootstrap::boot();

        $this->assertSame($boot1, $boot2);
        $this->assertTrue(Bootstrap::isBooted());
    }
}
