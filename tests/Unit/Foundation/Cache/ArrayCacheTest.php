<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Cache;

use Coleza\Foundation\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function testSetGetDelete(): void
    {
        $this->assertTrue($this->cache->set('key1', 'value1'));
        $this->assertSame('value1', $this->cache->get('key1'));
        $this->assertTrue($this->cache->has('key1'));

        $this->assertTrue($this->cache->delete('key1'));
        $this->assertNull($this->cache->get('key1'));
        $this->assertFalse($this->cache->has('key1'));
    }

    public function testRememberCachesValue(): void
    {
        $calls = 0;
        $producer = function () use (&$calls): string {
            $calls++;
            return 'computed_val';
        };

        $val1 = $this->cache->remember('rem_key', 60, $producer);
        $val2 = $this->cache->remember('rem_key', 60, $producer);

        $this->assertSame('computed_val', $val1);
        $this->assertSame('computed_val', $val2);
        $this->assertSame(1, $calls, 'Producer should only be called once when cached');
    }
}
