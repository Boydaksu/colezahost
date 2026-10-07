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

        // Bind Database Connection as singleton
        $container->singleton(\Coleza\Foundation\Database\Connection::class, static function (Container $c): \Coleza\Foundation\Database\Connection {
            $config = $c->get(ConfigRepository::class);
            $defaultDriver = $config->get('database.default', 'mysql');
            $dbConfig = $config->get("database.connections.{$defaultDriver}", []);
            return \Coleza\Foundation\Database\ConnectionFactory::create($dbConfig);
        });

        // Bind CommandBus, QueryBus and EventBus
        $container->singleton(\Coleza\Foundation\Bus\CommandBus::class, static function (Container $c): \Coleza\Foundation\Bus\CommandBus {
            return new \Coleza\Foundation\Bus\CommandBus($c);
        });

        $container->singleton(\Coleza\Foundation\Bus\QueryBus::class, static function (Container $c): \Coleza\Foundation\Bus\QueryBus {
            return new \Coleza\Foundation\Bus\QueryBus($c);
        });

        $container->singleton(\Coleza\Foundation\Events\EventBus::class, static function (Container $c): \Coleza\Foundation\Events\EventBus {
            return new \Coleza\Foundation\Events\EventBus($c);
        });
    }

    public function boot(Container $container): void
    {
        // Core bootstrap operations
    }
}
