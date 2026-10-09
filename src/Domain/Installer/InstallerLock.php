<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * Manages the installation lock file and database marker, preventing installer re-entry attacks.
 */
final class InstallerLock
{
    public function __construct(
        private string $lockFilePath,
        private ?Connection $db = null
    ) {
    }

    public function isLocked(): bool
    {
        if (file_exists($this->lockFilePath)) {
            return true;
        }

        if ($this->db !== null) {
            try {
                $row = $this->db->selectOne('SELECT setting_value FROM system_settings WHERE setting_key = "installer.locked"');
                if ($row !== null && $row['setting_value'] === '1') {
                    return true;
                }
            } catch (\Throwable) {
                // Table might not exist yet before installation
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function lock(array $metadata = []): void
    {
        $payload = array_merge([
            'installed_at' => date('c'),
            'app_version' => '1.0.0',
        ], $metadata);

        $payload['sha256_checksum'] = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $content = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $dir = dirname($this->lockFilePath);
        if (!is_dir($dir) && $dir !== '' && $dir !== '.') {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($this->lockFilePath, (string) $content) === false) {
            throw new RuntimeException(sprintf('Failed to write installation lock file [%s].', $this->lockFilePath));
        }

        // Also record in database if connection is available
        if ($this->db !== null) {
            try {
                $existing = $this->db->selectOne('SELECT id FROM system_settings WHERE setting_key = "installer.locked"');
                if ($existing !== null) {
                    $this->db->statement('UPDATE system_settings SET setting_value = "1", updated_at = CURRENT_TIMESTAMP WHERE setting_key = "installer.locked"');
                } else {
                    $this->db->statement('INSERT INTO system_settings (setting_key, setting_value, is_encrypted, updated_at) VALUES ("installer.locked", "1", 0, CURRENT_TIMESTAMP)');
                }
            } catch (\Throwable) {
                // Ignore DB error during file lock creation
            }
        }
    }

    public function unlock(): void
    {
        if (file_exists($this->lockFilePath)) {
            unlink($this->lockFilePath);
        }

        if ($this->db !== null) {
            try {
                $this->db->statement('UPDATE system_settings SET setting_value = "0" WHERE setting_key = "installer.locked"');
            } catch (\Throwable) {
                // Table might not exist
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        if (!file_exists($this->lockFilePath)) {
            return null;
        }

        $raw = file_get_contents($this->lockFilePath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function getLockFilePath(): string
    {
        return $this->lockFilePath;
    }
}
