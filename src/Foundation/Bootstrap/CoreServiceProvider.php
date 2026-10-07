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

        // Bind LogManager and default LoggerInterface
        $container->singleton(\Coleza\Foundation\Logging\LogManager::class, static function (): \Coleza\Foundation\Logging\LogManager {
            $logsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            return new \Coleza\Foundation\Logging\LogManager($logsDir);
        });

        $container->singleton(\Psr\Log\LoggerInterface::class, static function (Container $c): \Psr\Log\LoggerInterface {
            return $c->get(\Coleza\Foundation\Logging\LogManager::class)->app();
        });

        // Bind StorageManager and default StorageInterface
        $container->singleton(\Coleza\Foundation\Storage\StorageManager::class, static function (): \Coleza\Foundation\Storage\StorageManager {
            $storageDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app';
            return new \Coleza\Foundation\Storage\StorageManager($storageDir);
        });

        $container->singleton(\Coleza\Foundation\Storage\StorageInterface::class, static function (Container $c): \Coleza\Foundation\Storage\StorageInterface {
            return $c->get(\Coleza\Foundation\Storage\StorageManager::class)->private();
        });

        // Bind CacheManager and default CacheInterface
        $container->singleton(\Coleza\Foundation\Cache\CacheManager::class, static function (Container $c): \Coleza\Foundation\Cache\CacheManager {
            $cacheDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
            return new \Coleza\Foundation\Cache\CacheManager('file', $cacheDir, $c);
        });

        $container->singleton(\Coleza\Foundation\Cache\CacheInterface::class, static function (Container $c): \Coleza\Foundation\Cache\CacheInterface {
            return $c->get(\Coleza\Foundation\Cache\CacheManager::class)->store();
        });

        // Bind DatabaseLock and LockInterface
        $container->singleton(\Coleza\Foundation\Lock\DatabaseLock::class, static function (Container $c): \Coleza\Foundation\Lock\DatabaseLock {
            return new \Coleza\Foundation\Lock\DatabaseLock($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        $container->singleton(\Coleza\Foundation\Lock\LockInterface::class, static function (Container $c): \Coleza\Foundation\Lock\LockInterface {
            return $c->get(\Coleza\Foundation\Lock\DatabaseLock::class);
        });

        // Bind IdempotencyManager
        $container->singleton(\Coleza\Foundation\Idempotency\IdempotencyManager::class, static function (Container $c): \Coleza\Foundation\Idempotency\IdempotencyManager {
            return new \Coleza\Foundation\Idempotency\IdempotencyManager($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        // Bind DatabaseQueue and QueueInterface
        $container->singleton(\Coleza\Foundation\Queue\DatabaseQueue::class, static function (Container $c): \Coleza\Foundation\Queue\DatabaseQueue {
            return new \Coleza\Foundation\Queue\DatabaseQueue($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        $container->singleton(\Coleza\Foundation\Queue\QueueInterface::class, static function (Container $c): \Coleza\Foundation\Queue\QueueInterface {
            return $c->get(\Coleza\Foundation\Queue\DatabaseQueue::class);
        });

        // Bind QueueWorker
        $container->singleton(\Coleza\Foundation\Queue\QueueWorker::class, static function (Container $c): \Coleza\Foundation\Queue\QueueWorker {
            return new \Coleza\Foundation\Queue\QueueWorker(
                $c->get(\Coleza\Foundation\Queue\QueueInterface::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
    }

    public function boot(Container $container): void
    {
        // Core bootstrap operations
    }
}
