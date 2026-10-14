<?php
declare(strict_types=1);
namespace Coleza\Domain\Vault;

use Coleza\Foundation\Database\Connection;

/** The probe is ciphertext, never key material. Writers lock it until their transaction commits. */
final class KeyBinding
{
    public const SETTING = 'security.application_key_probe';
    public const SCOPE = 'application-key-binding:v1';
    public const PROBE = 'Coleza application key binding v1';

    public static function tableExists(Connection $db, string $table): bool
    {
        return $db->getDriverName() === 'mysql'
            ? $db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== null
            : $db->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
    }

    public static function row(Connection $db, string $prefix = '', bool $lock = true): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
        $table = $prefix . 'system_settings';
        if (!self::tableExists($db, $table)) { return null; }
        $rows = $db->select("SELECT * FROM {$table} WHERE setting_key = ?" . ($lock ? $db->forUpdate() : ''), [self::SETTING]);
        if (count($rows) > 1 || (isset($rows[0]) && $rows[0]['setting_key'] !== self::SETTING)) { throw new \LogicException('Ambiguous application key binding.'); }
        return $rows[0] ?? null;
    }

    public static function guard(Connection $db, ?Encryptor $encryptor = null, string $prefix = ''): void
    {
        $row = self::row($db, $prefix);
        if ($row === null) { return; } // Existing installations require an explicit maintenance binding.
        SecretStorageUpgrade::assertStorage($db, [$prefix . 'system_settings' => ['id', 'setting_key', 'setting_value', 'is_encrypted']]);
        self::validate($row, $encryptor ?? ApplicationKey::encryptor(ApplicationKey::configured()));
    }

    public static function validate(array $row, Encryptor $encryptor): void
    {
        try {
            if ((int) ($row['is_encrypted'] ?? 0) !== 1 || !hash_equals(self::PROBE, (new SecretCipher($encryptor))->open((string) ($row['setting_value'] ?? ''), self::SCOPE))) { throw new \RuntimeException('Invalid probe.'); }
        } catch (\Throwable) { throw new \RuntimeException('Application key does not match the database binding.'); }
    }
}
