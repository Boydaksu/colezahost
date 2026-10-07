<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Cache;

use Coleza\Foundation\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $tempCacheDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->tempCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_cache_' . uniqid();
        mkdir($this->tempCacheDir, 0777, true);
        $this->cache = new FileCache($this->tempCacheDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempCacheDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempCacheDir);
        parent::tearDown();
    }

    public function testFileCachePersistsAndExpires(): void
    {
        $this->cache->set('setting_theme', 'dark', 3600);
        $this->assertSame('dark', $this->cache->get('setting_theme'));

        // Test expired item (TTL = -1 second)
        $this->cache->set('expired_key', 'old_val', -1);
        $this->assertNull($this->cache->get('expired_key'));
    }

    public function testClearWipesFiles(): void
    {
        $this->cache->set('k1', 'v1');
        $this->cache->set('k2', 'v2');

        $this->assertTrue($this->cache->has('k1'));
        $this->assertTrue($this->cache->has('k2'));

        $this->cache->clear();

        $this->assertFalse($this->cache->has('k1'));
        $this->assertFalse($this->cache->has('k2'));
    }
}
