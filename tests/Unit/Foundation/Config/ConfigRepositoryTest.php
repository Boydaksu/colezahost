<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Config;

use Coleza\Foundation\Config\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class ConfigRepositoryTest extends TestCase
{
    public function testGetAndSetWithDotNotation(): void
    {
        $config = new ConfigRepository();
        $config->set('app.name', 'Coleza Host');
        $config->set('database.connections.mysql.host', '127.0.0.1');

        $this->assertSame('Coleza Host', $config->get('app.name'));
        $this->assertSame('127.0.0.1', $config->get('database.connections.mysql.host'));
        $this->assertNull($config->get('database.connections.postgres.host'));
        $this->assertSame('fallback_val', $config->get('non.existent', 'fallback_val'));
    }

    public function testHasAndAllMethods(): void
    {
        $config = new ConfigRepository(['theme' => ['color' => 'blue']]);

        $this->assertTrue($config->has('theme.color'));
        $this->assertFalse($config->has('theme.font'));
        $this->assertSame(['theme' => ['color' => 'blue']], $config->all());
    }

    public function testLoadFromDirectoryLoadsPhpConfigs(): void
    {
        $config = new ConfigRepository();
        $configDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config';

        $config->loadFromDirectory($configDir);

        $this->assertSame('Coleza Host', $config->get('app.name'));
        $this->assertSame('UTC', $config->get('app.timezone'));
        $this->assertSame('mysql', $config->get('database.default'));
    }
}
