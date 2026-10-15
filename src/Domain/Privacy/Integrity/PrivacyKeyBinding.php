<?php

declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;

use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Identity\IdentityPersistence;
use Coleza\Domain\Vault\KeyBinding;

/** Immutable purpose bindings; runtime writers retain these locks until their transaction ends. */
final class PrivacyKeyBinding
{
    public const TABLE = 'privacy_key_bindings';
    public static function requirements(): array { return [self::TABLE => ['purpose', 'key_id', 'proof']]; }
    public static function installed(Connection $db): bool
    {
        return IdentityPersistence::installed($db, self::requirements(), installation: false,
            primary: [self::TABLE => 'purpose'], onlyPrimary: [self::TABLE]);
    }
    public static function ensure(Connection $db): void
    {
        if (self::installed($db)) {
            (new \Coleza\Domain\Identity\Audit\AuditLogger($db))->ensureTable();
            \Coleza\Domain\Vault\SecretStorageUpgrade::assertStorage($db, ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload', 'created_at']]);
            return;
        }
        $suffix = $db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : '';
        $db->statement('CREATE TABLE privacy_key_bindings (purpose VARCHAR(16) PRIMARY KEY, key_id VARCHAR(64) NOT NULL, proof VARCHAR(64) NOT NULL)' . $suffix);
        if (!self::installed($db)) { throw new \LogicException('Privacy binding schema unavailable.'); }
        self::ensure($db);
    }
    public static function value(string $purpose, #[\SensitiveParameter] string $key): array
    {
        if (!isset(PrivacySigningKey::FILES[$purpose])) { throw new \InvalidArgumentException('Unknown privacy purpose.'); }
        $data = ['purpose' => $purpose, 'key_id' => PrivacySigningKey::id($key)];
        return $data + ['proof' => PrivacySignature::sign('privacy-key-binding', $data, $key)];
    }
    public static function validate(array $row, string $purpose, #[\SensitiveParameter] string $key): void
    {
        $expected = self::value($purpose, $key);
        if (($row['purpose'] ?? null) !== $purpose || ($row['key_id'] ?? null) !== $expected['key_id']
            || !is_string($row['proof'] ?? null) || !hash_equals($expected['proof'], $row['proof'])) {
            throw new \RuntimeException('Privacy key does not match its persistent purpose binding.');
        }
    }
    public static function rows(Connection $db, bool $lock = false): array
    {
        if (!KeyBinding::tableExists($db, self::TABLE)) { return []; }
        if (!self::installed($db)) { throw new \LogicException('Privacy binding schema unavailable.'); }
        return $db->select('SELECT purpose, key_id, proof FROM privacy_key_bindings ORDER BY purpose' . ($lock ? $db->forUpdate() : ''));
    }
    public static function check(Connection $db, #[\SensitiveParameter] array $keys): void
    {
        foreach (self::rows($db) as $row) {
            if (!isset(PrivacySigningKey::FILES[$row['purpose']])) { throw new \RuntimeException('Unknown privacy binding purpose.'); }
            if (isset($keys[$row['purpose']])) { self::validate($row, $row['purpose'], $keys[$row['purpose']]); }
        }
    }
    public static function guard(Connection $db, #[\SensitiveParameter] array $keys, callable $verifyExisting): void
    {
        if (!$db->inTransaction() || !self::installed($db)) { throw new \LogicException('Privacy writers require installed binding schema and a transaction.'); }
        ksort($keys); $newBinding = false;
        foreach ($keys as $purpose => $key) {
            $data = self::value($purpose, $key);
            $sql = 'INSERT INTO privacy_key_bindings (purpose, key_id, proof) VALUES (?, ?, ?)';
            $sql .= $db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE purpose = purpose' : ' ON CONFLICT(purpose) DO NOTHING';
            // Upsert first avoids competing gap locks on an absent purpose; no identity can redirect it.
            $affected = $db->affectingStatement($sql, array_values($data));
            $newBinding = $newBinding || $affected > 0;
            $row = $db->selectOne('SELECT purpose, key_id, proof FROM privacy_key_bindings WHERE purpose = ?' . $db->forUpdate(), [$purpose]);
            self::validate($row ?? [], $purpose, $key);
        }
        $ids = [];
        foreach (self::rows($db, lock: true) as $row) {
            if (!isset(PrivacySigningKey::FILES[$row['purpose']]) || in_array($row['key_id'], $ids, true)) {
                throw new \RuntimeException('Privacy purposes require independent bound keys.');
            }
            $ids[] = $row['key_id'];
        }
        if ($newBinding) {
            $verifyExisting();
            (new \Coleza\Domain\Identity\Audit\AuditLogger($db))->log(0, 'PRIVACY_KEYS_BOUND', 'privacy-keys',
                payload: ['key_ids' => array_map(PrivacySigningKey::id(...), $keys)]);
        } // Existing data must authenticate before a first binding can commit.
    }
}
