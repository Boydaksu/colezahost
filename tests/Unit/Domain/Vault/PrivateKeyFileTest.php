<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Vault;

use Coleza\Domain\Vault\ApplicationKey;
use Coleza\Domain\Vault\PrivateKeyFile;
use Coleza\Domain\Vault\SecretCipher;
use PHPUnit\Framework\TestCase;

final class PrivateKeyFileTest extends TestCase
{
    private string $directory;
    private string $root;
    private string|false $oldKey;
    private string|false $oldFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/coleza-key-test-' . bin2hex(random_bytes(12));
        $this->root = dirname(__DIR__, 4);
        $this->oldKey = getenv('APP_KEY'); $this->oldFile = getenv('APP_KEY_FILE');
        putenv('APP_KEY'); putenv('APP_KEY_FILE');
    }

    protected function tearDown(): void
    {
        $this->oldKey === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $this->oldKey);
        $this->oldFile === false ? putenv('APP_KEY_FILE') : putenv('APP_KEY_FILE=' . $this->oldFile);
        foreach (['application.key', 'second.key'] as $name) {
            $path = $this->directory . '/' . $name;
            if (is_file($path) || is_link($path)) { unlink($path); }
        }
        if (is_dir($this->directory)) { rmdir($this->directory); }
    }

    private function provision(): string
    {
        $file = $this->directory . '/application.key';
        $metadata = PrivateKeyFile::provision($file, $this->root);
        self::assertSame(['key_id'], array_keys($metadata));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $metadata['key_id']);
        return $file;
    }

    public function testFileConfigurationSurvivesRestartAndUsesTheSameKeyForDefaultSecretCipher(): void
    {
        $file = $this->provision(); putenv('APP_KEY_FILE=' . $file);
        $key = ApplicationKey::configured();
        self::assertTrue($key === (require $this->root . '/config/app.php')['key'], 'File configuration must resolve the persisted key.');
        $sealed = (new SecretCipher())->seal('synthetic-file-key-secret', 'test');
        self::assertSame('synthetic-file-key-secret', (new SecretCipher())->open($sealed, 'test'));
        self::assertSame($key, PrivateKeyFile::load($file, $this->root));
        $other = PrivateKeyFile::provision($this->directory . '/second.key', $this->root);
        self::assertNotSame(ApplicationKey::encryptor($key)->fingerprint(), $other['key_id']);
        self::assertStringNotContainsString($key, json_encode($other));
    }

    public function testExistingKeyIsNeverOverwritten(): void
    {
        $file = $this->provision(); $before = file_get_contents($file);
        try { PrivateKeyFile::provision($file, $this->root); self::fail('Key overwritten'); }
        catch (\RuntimeException) { self::assertSame($before, file_get_contents($file)); }
    }

    /** @return array{int, string, string} */
    private function runCli(array $arguments): array
    {
        $command = [PHP_BINARY];
        if (($ini = php_ini_loaded_file()) !== false) { $command = [...$command, '-c', $ini]; }
        $process = proc_open([...$command, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process); fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    public function testCliCreatesProtectedKeyWithoutPrintingItAndRefusesOverwrite(): void
    {
        $file = $this->directory . '/application.key';
        [$code, $output, $errors] = $this->runCli(['tools/provision-application-key.php', $file]);
        self::assertSame(0, $code); self::assertSame('', $errors);
        $metadata = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['key_id'], array_keys($metadata));
        $key = PrivateKeyFile::load($file, $this->root);
        self::assertSame(ApplicationKey::encryptor($key)->fingerprint(), $metadata['key_id']);
        self::assertStringNotContainsString($key, $output);
        [$code, $output, $errors] = $this->runCli(['tools/provision-application-key.php', $file]);
        self::assertSame(1, $code); self::assertSame('', $output);
        self::assertStringNotContainsString($key, $errors);
        self::assertTrue($key === PrivateKeyFile::load($file, $this->root));
    }

    public function testSeparateProcessLoadsThePersistedKeyAfterRestart(): void
    {
        $file = $this->provision(); putenv('APP_KEY_FILE=' . $file);
        [$code, $output, $errors] = $this->runCli(['-r', 'require "vendor/autoload.php"; $key = \\Coleza\\Domain\\Vault\\ApplicationKey::encryptor(\\Coleza\\Domain\\Vault\\ApplicationKey::configured()); echo $key->fingerprint();']);
        self::assertSame(0, $code); self::assertSame('', $errors);
        self::assertSame(ApplicationKey::encryptor(ApplicationKey::configured())->fingerprint(), $output);
    }

    public function testCliRejectsRelativePathWithoutCreatingAnything(): void
    {
        [$code, $output] = $this->runCli(['tools/provision-application-key.php', 'application.key']);
        self::assertSame(1, $code); self::assertSame('', $output);
        self::assertFileDoesNotExist($this->root . '/application.key');
    }

    public function testAmbiguousEnvironmentSourcesFailClosed(): void
    {
        putenv('APP_KEY=' . ApplicationKey::generate()); putenv('APP_KEY_FILE=/does-not-exist');
        $this->expectException(\RuntimeException::class);
        ApplicationKey::configured();
    }

    public function testMissingKeyFileCannotGenerateAReplacement(): void
    {
        putenv('APP_KEY_FILE=' . $this->directory . '/application.key');
        try { ApplicationKey::configured(); self::fail('Missing file accepted'); }
        catch (\RuntimeException) { self::assertDirectoryDoesNotExist($this->directory); }
    }

    public function testApplicationDirectoryCannotContainKeyStorage(): void
    {
        $directory = $this->root . '/remediation/runtime/refused-' . bin2hex(random_bytes(6));
        try { PrivateKeyFile::provision($directory . '/application.key', $this->root); self::fail('Public storage accepted'); }
        catch (\RuntimeException) { self::assertDirectoryDoesNotExist($directory); }
    }

    public function testOversizedAndMalformedFileIsRejectedWithoutDisclosingContent(): void
    {
        $file = $this->provision(); file_put_contents($file, 'synthetic-private-invalid-key' . str_repeat('x', 10000));
        try { PrivateKeyFile::load($file, $this->root); self::fail('Invalid file accepted'); }
        catch (\RuntimeException $error) { self::assertStringNotContainsString('synthetic-private-invalid-key', $error->getMessage()); }
    }

    public function testWeakKeyFileIsRefused(): void
    {
        $file = $this->provision(); file_put_contents($file, 'base64:' . base64_encode(str_repeat('c', 32)) . "\n");
        $this->expectException(\RuntimeException::class);
        PrivateKeyFile::load($file, $this->root);
    }

    public function testBroadPosixPermissionsAreRefused(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows privacy is verified by the actual ACL test below.
            self::assertSame('Windows', PHP_OS_FAMILY); return;
        }
        $file = $this->provision(); chmod($file, 0644);
        $this->expectException(\RuntimeException::class);
        PrivateKeyFile::load($file, $this->root);
    }

    public function testBroadWindowsAclIsRefused(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { self::assertNotSame('Windows', PHP_OS_FAMILY); return; }
        $file = $this->provision();
        $process = proc_open(['icacls.exe', $file, '/grant', '*S-1-1-0:R'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process); fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        $this->expectException(\RuntimeException::class);
        PrivateKeyFile::load($file, $this->root);
    }
}
