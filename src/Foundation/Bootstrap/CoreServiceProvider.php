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

        // Bind Scheduler
        $container->singleton(\Coleza\Foundation\Scheduler\Scheduler::class, static function (Container $c): \Coleza\Foundation\Scheduler\Scheduler {
            return new \Coleza\Foundation\Scheduler\Scheduler(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Foundation\Lock\LockInterface::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // Bind HealthManager
        $container->singleton(\Coleza\Foundation\Health\HealthManager::class, static function (Container $c): \Coleza\Foundation\Health\HealthManager {
            $manager = new \Coleza\Foundation\Health\HealthManager();
            $manager->register(new \Coleza\Foundation\Health\DatabaseHealthCheck($c->get(\Coleza\Foundation\Database\Connection::class)));
            $manager->register(new \Coleza\Foundation\Health\StorageHealthCheck($c->get(\Coleza\Foundation\Storage\StorageInterface::class)));
            return $manager;
        });

        // Bind InstallerSkeleton
        $container->singleton(\Coleza\Foundation\Installer\InstallerSkeleton::class, static function (Container $c): \Coleza\Foundation\Installer\InstallerSkeleton {
            return new \Coleza\Foundation\Installer\InstallerSkeleton($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        // Bind BackupSkeleton
        $container->singleton(\Coleza\Foundation\Backup\BackupSkeleton::class, static function (Container $c): \Coleza\Foundation\Backup\BackupSkeleton {
            return new \Coleza\Foundation\Backup\BackupSkeleton(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Foundation\Storage\StorageInterface::class)
            );
        });

        // Bind WorkerSupervisor
        $container->singleton(\Coleza\Foundation\Worker\WorkerSupervisor::class, static function (Container $c): \Coleza\Foundation\Worker\WorkerSupervisor {
            return new \Coleza\Foundation\Worker\WorkerSupervisor(
                $c->get(\Coleza\Foundation\Queue\QueueInterface::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // Bind PasswordHasher
        $container->singleton(\Coleza\Domain\Identity\Security\PasswordHasher::class, static function (): \Coleza\Domain\Identity\Security\PasswordHasher {
            return new \Coleza\Domain\Identity\Security\PasswordHasher();
        });

        // Bind DatabaseSessionHandler
        $container->singleton(\Coleza\Domain\Identity\Session\DatabaseSessionHandler::class, static function (Container $c): \Coleza\Domain\Identity\Session\DatabaseSessionHandler {
            return new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        // Bind AuthService
        $container->singleton(\Coleza\Domain\Identity\Auth\AuthService::class, static function (Container $c): \Coleza\Domain\Identity\Auth\AuthService {
            return new \Coleza\Domain\Identity\Auth\AuthService(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Domain\Identity\Security\PasswordHasher::class),
                $c->get(\Coleza\Domain\Identity\Session\DatabaseSessionHandler::class)
            );
        });

        // Bind TotpEngine
        $container->singleton(\Coleza\Domain\Identity\TwoFactor\TotpEngine::class, static function (): \Coleza\Domain\Identity\TwoFactor\TotpEngine {
            return new \Coleza\Domain\Identity\TwoFactor\TotpEngine();
        });

        // Bind TwoFactorService
        $container->singleton(\Coleza\Domain\Identity\TwoFactor\TwoFactorService::class, static function (Container $c): \Coleza\Domain\Identity\TwoFactor\TwoFactorService {
            return new \Coleza\Domain\Identity\TwoFactor\TwoFactorService(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Domain\Identity\TwoFactor\TotpEngine::class),
                $c->get(\Coleza\Domain\Identity\Security\PasswordHasher::class)
            );
        });

        // Bind OrganizationService
        $container->singleton(\Coleza\Domain\Identity\Organization\OrganizationService::class, static function (Container $c): \Coleza\Domain\Identity\Organization\OrganizationService {
            return new \Coleza\Domain\Identity\Organization\OrganizationService(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Domain\Identity\Session\DatabaseSessionHandler::class)
            );
        });

        // Bind RbacService
        $container->singleton(\Coleza\Domain\Identity\Rbac\RbacService::class, static function (Container $c): \Coleza\Domain\Identity\Rbac\RbacService {
            return new \Coleza\Domain\Identity\Rbac\RbacService($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        // Bind AuditLogger
        $container->singleton(\Coleza\Domain\Identity\Audit\AuditLogger::class, static function (Container $c): \Coleza\Domain\Identity\Audit\AuditLogger {
            return new \Coleza\Domain\Identity\Audit\AuditLogger($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        // Bind ImpersonationService
        $container->singleton(\Coleza\Domain\Identity\Impersonation\ImpersonationService::class, static function (Container $c): \Coleza\Domain\Identity\Impersonation\ImpersonationService {
            return new \Coleza\Domain\Identity\Impersonation\ImpersonationService(
                $c->get(\Coleza\Domain\Identity\Session\DatabaseSessionHandler::class),
                $c->get(\Coleza\Domain\Identity\Rbac\RbacService::class),
                $c->get(\Coleza\Domain\Identity\Audit\AuditLogger::class)
            );
        });

        // Bind Translator
        $container->singleton(\Coleza\Foundation\Localization\Translator::class, static function (): \Coleza\Foundation\Localization\Translator {
            return new \Coleza\Foundation\Localization\Translator(__DIR__ . '/../../../config/lang', 'tr_TR');
        });

        // Bind BrandService
        $container->singleton(\Coleza\Domain\Brand\BrandService::class, static function (Container $c): \Coleza\Domain\Brand\BrandService {
            return new \Coleza\Domain\Brand\BrandService($c->get(\Coleza\Foundation\Database\Connection::class));
        });

        // Bind Encryptor
        $container->singleton(\Coleza\Domain\Vault\Encryptor::class, static function (Container $c): \Coleza\Domain\Vault\Encryptor {
            $key = (string) $c->get(\Coleza\Foundation\Config\ConfigRepository::class)->get('app.key', 'base64:' . base64_encode(str_repeat('c', 32)));
            return new \Coleza\Domain\Vault\Encryptor($key);
        });

        // Bind VaultService
        $container->singleton(\Coleza\Domain\Vault\VaultService::class, static function (Container $c): \Coleza\Domain\Vault\VaultService {
            return new \Coleza\Domain\Vault\VaultService(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Domain\Vault\Encryptor::class),
                $c->get(\Coleza\Domain\Identity\Audit\AuditLogger::class)
            );
        });

        // Bind PrivacyConsentService
        $container->singleton(\Coleza\Domain\Privacy\PrivacyConsentService::class, static function (Container $c): \Coleza\Domain\Privacy\PrivacyConsentService {
            return new \Coleza\Domain\Privacy\PrivacyConsentService(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                $c->get(\Coleza\Domain\Identity\Audit\AuditLogger::class)
            );
        });

        // Bind ModuleLifecycleService
        $container->singleton(\Coleza\Domain\Module\ModuleLifecycleService::class, static function (Container $c): \Coleza\Domain\Module\ModuleLifecycleService {
            return new \Coleza\Domain\Module\ModuleLifecycleService(
                $c->get(\Coleza\Foundation\Database\Connection::class),
                coreVersion: '1.0.0',
                auditLogger: $c->get(\Coleza\Domain\Identity\Audit\AuditLogger::class)
            );
        });
    }

    public function boot(Container $container): void
    {
        // Core bootstrap operations
    }
}
