<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Cache;

use Coleza\Foundation\Cache\DatabaseCache;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;

final class DatabaseCacheTest extends TestCase
{
    private Connection $db;
    private DatabaseCache $cache;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->cache = new DatabaseCache($this->db);
    }

    public function testDatabaseCacheOperations(): void
    {
        $this->cache->set('active_rates', ['USD' => 38.5, 'EUR' => 41.2], 3600);

        $rates = $this->cache->get('active_rates');
        $this->assertSame(['USD' => 38.5, 'EUR' => 41.2], $rates);

        // Expired item
        $this->cache->set('stale_item', 'stale', -10);
        $this->assertNull($this->cache->get('stale_item'));

        // Delete
        $this->cache->delete('active_rates');
        $this->assertNull($this->cache->get('active_rates'));
    }
}
