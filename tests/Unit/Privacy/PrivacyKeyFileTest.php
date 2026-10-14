<?php

declare(strict_types=1);
namespace Coleza\Tests\Unit\Privacy;

use Coleza\Domain\Privacy\Integrity\PrivacySigningKey;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstone;
use Coleza\Domain\Vault\{ApplicationKey, PrivateKeyFile};

final class PrivacyKeyFileTest extends \PHPUnit\Framework\TestCase
{
    private function environment(array $values, callable $action): void
    {
        $before = [];
        foreach ($values as $name => $value) {
            $before[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($value === null ? $name : $name . '=' . $value);
        }
        try { $action(); } finally {
            foreach ($before as $name => [$env, $server, $process]) {
                if ($env === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $env; }
                if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
                putenv($process === false ? $name : $name . '=' . $process);
            }
        }
    }
    public function testMissingKeysNeverGenerateDefaults(): void
    {
        $values = array_fill_keys(array_values(PrivacySigningKey::FILES), null);
        $this->environment($values, function (): void {
            $db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'), 'sqlite');
            $actions = [
                fn () => (new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($db))->recordTombstone(7, 'subject@example.test'),
                fn () => (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($db))->recordAudit(7, 'users', 'DELETE', 1, ['email'], 'before', 'after'),
                fn () => (new \Coleza\Domain\Privacy\Erasure\PrivacyErasureService($db, tombstoneService: new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($db)))->executeErasure(7, 1, 'test'),
            ];
            foreach ($actions as $action) {
                try { $action(); self::fail('Missing key allowed database side effects.'); }
                catch (\RuntimeException $error) { self::assertStringContainsString('Configure', $error->getMessage()); }
            }
            self::assertSame([], $db->select("SELECT name FROM sqlite_master WHERE type='table'"));
            foreach (array_keys(PrivacySigningKey::FILES) as $purpose) {
                try { PrivacySigningKey::resolve($purpose); self::fail('Missing privacy key accepted.'); }
                catch (\RuntimeException $error) { self::assertStringContainsString('Configure', $error->getMessage()); }
            }
        });
    }
    public function testProtectedKeysSurviveApplicationKeyChangeAndRejectCopiedApplicationKey(): void
    {
        $directory = sys_get_temp_dir() . '/coleza-privacy-keys-' . bin2hex(random_bytes(10));
        $path = $directory . '/tombstone.key'; $root = dirname(__DIR__, 3);
        PrivateKeyFile::provision($path, $root);
        $key = PrivateKeyFile::load($path, $root);
        $copy = $directory . '/application-copy.key';
        // Provision grants the same protected directory/file policy, then replace only test content.
        PrivateKeyFile::provision($copy, $root); file_put_contents($copy, $key . "\n");
        try {
            $values = array_fill_keys(array_values(PrivacySigningKey::FILES), null);
            $values['PRIVACY_TOMBSTONE_KEY_FILE'] = $path;
            $values['APP_KEY_FILE'] = null; $values['APP_KEY'] = ApplicationKey::generate();
            $this->environment($values, function () use ($key, $copy): void {
                self::assertSame(PrivacySigningKey::raw($key), PrivacySigningKey::resolve('tombstone'));
                $record = PrivacyTombstone::create(7, 'subject@example.test', 'ANONYMIZE', 'Original', 'checksum',
                    secretKey: $key, salt: \Coleza\Tests\Support\PrivacyFixtureKeys::email());
                putenv('APP_KEY=' . ApplicationKey::generate());
                self::assertTrue($record->verifyIntegrity());
                putenv('APP_KEY'); putenv('APP_KEY_FILE=' . $copy);
                try { PrivacySigningKey::resolve('tombstone'); self::fail('Copied application key accepted.'); }
                catch (\RuntimeException $error) { self::assertStringContainsString('independent', $error->getMessage()); }
                putenv('APP_KEY_FILE'); putenv('PRIVACY_REDACTION_KEY_FILE=' . $copy);
                try { PrivacySigningKey::resolve('tombstone'); self::fail('Copied privacy purpose key accepted.'); }
                catch (\RuntimeException $error) { self::assertStringContainsString('different', $error->getMessage()); }
            });
        } finally { if (is_file($copy)) { unlink($copy); } if (is_file($path)) { unlink($path); } if (is_dir($directory)) { rmdir($directory); } }
    }
}
