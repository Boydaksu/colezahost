<?php
declare(strict_types=1);
namespace Coleza\Domain\Privacy\Tombstone;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;

/** A missing legacy hash/signature stays empty; schema installation never fabricates evidence. */
final class PrivacyTombstoneSchema
{
    public static function ensure(Connection $db): void
    {
        if (PdoSchema::useInstalledSchemaInTransaction($db, ['privacy_tombstones' => [
            'id', 'user_id', 'user_email_hash', 'erasure_type', 'reason', 'erasure_checksum', 'metadata', 'tombstoned_at', 'integrity_signature',
        ]])) { return; }
        $id = PdoSchema::autoIncrement($db->getPdo());
        $db->statement("CREATE TABLE IF NOT EXISTS privacy_tombstones (
            id {$id},
            user_id INT NOT NULL UNIQUE,
            user_email_hash VARCHAR(64) NOT NULL DEFAULT '',
            erasure_type VARCHAR(50) NOT NULL,
            reason TEXT NULL,
            erasure_checksum VARCHAR(64) NOT NULL DEFAULT '',
            metadata TEXT NULL,
            tombstoned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            integrity_signature VARCHAR(64) NOT NULL DEFAULT ''
        )");
        $columns = $db->getDriverName() === 'sqlite'
            ? array_column($db->select('PRAGMA table_info(privacy_tombstones)'), 'name')
            : array_column($db->select('SHOW COLUMNS FROM privacy_tombstones'), 'Field');
        foreach ([
            'user_email_hash' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'erasure_checksum' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'metadata' => 'TEXT NULL',
            'integrity_signature' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $db->statement("ALTER TABLE privacy_tombstones ADD COLUMN {$column} {$definition}");
            }
        }
        if ($db->getDriverName() === 'sqlite') {
            $db->statement('CREATE INDEX IF NOT EXISTS idx_tombstone_email_hash ON privacy_tombstones (user_email_hash)');
        } elseif ($db->selectOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'privacy_tombstones' AND INDEX_NAME = 'idx_tombstone_email_hash'") === null) {
            $db->statement('CREATE INDEX idx_tombstone_email_hash ON privacy_tombstones (user_email_hash)');
        }
    }
}
