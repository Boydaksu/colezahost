<?php

declare(strict_types=1);

namespace Coleza\Foundation\Installer;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\Migrator;
use RuntimeException;

final class InstallerSkeleton
{
    public function __construct(
        private ?Connection $db = null,
        private ?Migrator $migrator = null
    ) {
    }

    /**
     * Check if the application is currently marked as installed.
     */
    public function isInstalled(string $lockFilePath): bool
    {
        return file_exists($lockFilePath);
    }

    /**
     * Verify prerequisites for installation.
     *
     * @return array<string, bool>
     */
    public function verifyPrerequisites(): array
    {
        return [
            'php_version' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'pdo_available' => extension_loaded('pdo'),
            'mbstring_available' => extension_loaded('mbstring'),
            'openssl_available' => extension_loaded('openssl'),
        ];
    }

    /**
     * Mark application as installed by generating installation lock record.
     */
    public function markInstalled(string $lockFilePath): void
    {
        $payload = json_encode([
            'installed_at' => date('c'),
            'app_version' => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $dir = dirname($lockFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($lockFilePath, $payload) === false) {
            throw new RuntimeException(sprintf('Failed to write install lock file [%s]', $lockFilePath));
        }
    }
}
