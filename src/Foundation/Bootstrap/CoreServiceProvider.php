<?php

declare(strict_types=1);

namespace Coleza\Foundation\Bootstrap;

use Coleza\Foundation\Config\ConfigRepository;
use Coleza\Foundation\Container\Container;
use Coleza\Foundation\Container\ServiceProviderInterface;
use Coleza\Foundation\Runtime\Environment;

final class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // Bind ConfigRepository as singleton
        $container->singleton(ConfigRepository::class, static function (): ConfigRepository {
            $config = new ConfigRepository();
            $configDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config';
            if (is_dir($configDir)) {
                $config->loadFromDirectory($configDir);
            }
            return $config;
        });

        // Bind Environment as singleton
        $container->singleton(Environment::class, static function (): Environment {
            return Bootstrap::boot();
        });
    }

    public function boot(Container $container): void
    {
        // Core bootstrap operations
    }
}
