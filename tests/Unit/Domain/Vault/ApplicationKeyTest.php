<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Vault;

use Coleza\Domain\Vault\Encryptor;
use Coleza\Foundation\Bootstrap\CoreServiceProvider;
use Coleza\Foundation\Config\ConfigRepository;
use Coleza\Foundation\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationKeyTest extends TestCase
{
    private function container(mixed $key): Container
    {
        $container = new Container();
        (new CoreServiceProvider())->register($container);
        $container->instance(ConfigRepository::class, new ConfigRepository(['app' => ['key' => $key]]));
        return $container;
    }

    public static function invalidKeys(): array
    {
        return [[null], [''], ['short'], [42], [str_repeat('c', 32)],
            ['base64:' . base64_encode(str_repeat('c', 32))], [str_repeat('a', 32)],
            ['base64:not-a-valid-key'], ['base64:' . base64_encode(random_bytes(31))]];
    }

    #[DataProvider('invalidKeys')]
    public function testRuntimeRejectsMissingMalformedAndPredictableKeys(mixed $key): void
    {
        $this->expectException(\Coleza\Foundation\Container\ContainerException::class);
        $this->container($key)->get(Encryptor::class);
    }

    public function testAbsentConfigCannotUseOldDefaultKey(): void
    {
        $container = new Container(); (new CoreServiceProvider())->register($container);
        $container->instance(ConfigRepository::class, new ConfigRepository());
        $this->expectException(\Coleza\Foundation\Container\ContainerException::class);
        $container->get(Encryptor::class);
    }

    public function testMissingKeyRefusesVaultResolutionBeforeDatabaseEffects(): void
    {
        $container = $this->container(null);
        $db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'));
        $db->enableQueryLog();
        $container->instance(\Coleza\Foundation\Database\Connection::class, $db);
        try { $container->get(\Coleza\Domain\Vault\VaultService::class); self::fail('Vault resolved without key'); }
        catch (\Coleza\Foundation\Container\ContainerException) { self::assertSame([], $db->getQueryLog()); }
        self::assertSame([], $db->select("SELECT name FROM sqlite_master WHERE type = 'table'"));
    }

    public function testInvalidKeyIsNotDisclosedInFailureMessage(): void
    {
        $key = 'synthetic-private-key';
        try { $this->container($key)->get(Encryptor::class); self::fail('Malformed key accepted'); }
        catch (\Coleza\Foundation\Container\ContainerException $error) {
            self::assertStringNotContainsString($key, $error->getMessage());
            self::assertStringContainsString('APP_KEY', $error->getMessage());
        }
    }

    public function testExplicitKeySurvivesNewContainerAndWrongKeyCannotRead(): void
    {
        $key = \Coleza\Domain\Vault\ApplicationKey::generate();
        $cipher = $this->container($key)->get(Encryptor::class)->encrypt('synthetic-key-restart-secret');
        self::assertSame('synthetic-key-restart-secret', $this->container($key)->get(Encryptor::class)->decrypt($cipher));
        $this->expectException(\RuntimeException::class);
        $this->container('base64:' . base64_encode(random_bytes(32)))->get(Encryptor::class)->decrypt($cipher);
    }

    public function testAppConfigurationReadsEnvironmentKeyWithoutDefault(): void
    {
        $previous = getenv('APP_KEY');
        try {
            putenv('APP_KEY');
            self::assertNull((require dirname(__DIR__, 4) . '/config/app.php')['key'] ?? null);
            putenv('APP_KEY=base64:synthetic-environment-value');
            self::assertSame('base64:synthetic-environment-value', (require dirname(__DIR__, 4) . '/config/app.php')['key'] ?? null);
        } finally { $previous === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $previous); }
    }
}
