<?php
declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;
use Coleza\Domain\Vault\{ApplicationKey, PrivateKeyFile};
/** Purpose keys survive database restore and application-key rotation independently. */
final class PrivacySigningKey
{
    public const FILES = ['tombstone' => 'PRIVACY_TOMBSTONE_KEY_FILE', 'redaction' => 'PRIVACY_REDACTION_KEY_FILE', 'email' => 'PRIVACY_EMAIL_HASH_KEY_FILE'];
    public static function environment(string $name): string
    {
        return (string) ($_ENV[$name] ?? $_SERVER[$name] ?? (getenv($name) ?: ''));
    }
    public static function raw(#[\SensitiveParameter] string $value): string
    {
        ApplicationKey::encryptor($value); // Require a nontrivial 32-byte key, including canonical base64 form.
        return str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
    }
    public static function resolve(string $purpose, #[\SensitiveParameter] ?string $explicit = null): string
    {
        if (!isset(self::FILES[$purpose])) { throw new \InvalidArgumentException('Unknown privacy key purpose.'); }
        if ($explicit !== null) { $key = self::raw($explicit); }
        else {
            $path = self::environment(self::FILES[$purpose]);
            if ($path === '') { throw new \RuntimeException('Configure the protected independent privacy key file before using signed records.'); }
            $key = self::raw(PrivateKeyFile::load($path, dirname(__DIR__, 4)));
            foreach (self::FILES as $other => $variable) {
                $otherPath = self::environment($variable);
                if ($other === $purpose || $otherPath === '') { continue; }
                if (hash_equals($key, self::raw(PrivateKeyFile::load($otherPath, dirname(__DIR__, 4))))) { throw new \RuntimeException('Privacy purposes require different persistent keys.'); }
            }
            $appPath = self::environment('APP_KEY_FILE');
            if ($appPath !== '' && hash_equals($key, self::raw(PrivateKeyFile::load($appPath, dirname(__DIR__, 4))))) { throw new \RuntimeException('Privacy and application keys must be independent.'); }
        }
        $application = self::environment('APP_KEY');
        if ($application !== '') {
            try { $appKey = self::raw($application); } catch (\Throwable) { $appKey = null; }
            if ($appKey !== null && hash_equals($appKey, $key)) { throw new \RuntimeException('Privacy and application keys must be independent.'); }
        }
        return $key;
    }
    public static function id(#[\SensitiveParameter] string $key): string { return hash('sha256', 'privacy-key-id:v1:' . self::raw($key)); }
}
