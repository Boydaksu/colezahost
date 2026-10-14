<?php

declare(strict_types=1);
namespace Coleza\Domain\Vault;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;

/** Explicit, key-dependent data upgrade; no DDL and no guessing from is_encrypted labels. */
final class SecretStorageUpgrade
{
    public function __construct(private Connection $db, private Encryptor $encryptor) {}
    public static function assertStorage(Connection $db, array $requirements): void
    {
        if (!PdoSchema::useInstalledSchema($db, $requirements)) { throw new \LogicException('Install complete secret storage before writing.'); }
        if ($db->getDriverName() === 'mysql') {
            foreach (array_keys($requirements) as $table) {
                $row = $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if (strtoupper((string) ($row['ENGINE'] ?? '')) !== 'INNODB') { throw new \LogicException('Secret storage upgrade requires InnoDB.'); }
            }
        }
    }
    public function upgrade(bool $legacyPlaintextConfirmed = false): int
    {
        self::assertStorage($this->db, ['system_settings' => ['id', 'setting_key', 'setting_value', 'is_encrypted'],
            'servers' => ['id', 'hostname', 'auth_secret_encrypted'], 'user_two_factor' => ['id', 'user_id', 'secret']]);
        return $this->db->transaction(function () use ($legacyPlaintextConfirmed): int {
            $cipher = new SecretCipher($this->encryptor); $changes = [];
            $groups = [
                ['system_settings', 'setting_value', $this->db->select("SELECT id, setting_value FROM system_settings WHERE setting_key = 'mail.password'" . $this->db->forUpdate())],
                ['servers', 'auth_secret_encrypted', $this->db->select('SELECT id, hostname, auth_secret_encrypted FROM servers ORDER BY id' . $this->db->forUpdate())],
                ['user_two_factor', 'secret', $this->db->select('SELECT id, user_id, secret FROM user_two_factor ORDER BY id' . $this->db->forUpdate())],
            ];
            foreach ($groups as [$table, $column, $rows]) {
                foreach ($rows as $row) {
                    $value = $row[$column]; if ($value === null || $value === '') { continue; }
                    $scope = match ($table) { 'system_settings' => 'smtp:', 'servers' => 'server:' . $row['hostname'], 'user_two_factor' => 'totp:' . $row['user_id'] };
                    if (str_starts_with($value, SecretCipher::PREFIX)) { $cipher->open($value, $scope); continue; }
                    if (str_starts_with($value, 'enc:')) { throw new \LogicException('Unknown secret envelope version; explicit reconciliation required.'); }
                    if (!$legacyPlaintextConfirmed) { throw new \LogicException('Legacy secret provenance must be explicitly confirmed before upgrading.'); }
                    $sealed = $cipher->seal($value, $scope);
                    if ($table === 'user_two_factor' && strlen($sealed) > 128) { throw new \LogicException('Legacy TOTP secret exceeds encrypted column capacity; explicit schema reconciliation required.'); }
                    $changes[] = [$table, $column, $row['id'], $sealed];
                }
            }
            foreach ($changes as [$table, $column, $id, $sealed]) {
                $data = [$column => $sealed]; if ($table === 'system_settings') { $data['is_encrypted'] = 1; }
                $this->db->update($table, $data, 'id = :id', ['id' => $id]);
            }
            return count($changes);
        });
    }
}
