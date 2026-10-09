<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

/**
 * Evaluates host server environment, runtime extensions, memory limits, and writable paths.
 */
final class EnvironmentRequirementChecker
{
    private const MIN_PHP_VERSION = '8.2.0';
    private const RECOMMENDED_PHP_VERSION = '8.4.0';

    private ?string $phpVersionOverride = null;
    /** @var array<string, bool> */
    private array $extensionOverrides = [];
    /** @var array<string, bool> */
    private array $directoryWritableOverrides = [];
    private ?int $memoryLimitBytesOverride = null;

    /**
     * @param list<string> $writableDirectories
     */
    public function __construct(
        private array $writableDirectories = [
            'storage',
            'config',
            'logs',
        ]
    ) {
    }

    public function setPhpVersionOverride(?string $version): void
    {
        $this->phpVersionOverride = $version;
    }

    public function setExtensionOverride(string $extension, bool $loaded): void
    {
        $this->extensionOverrides[strtolower($extension)] = $loaded;
    }

    public function setDirectoryWritableOverride(string $path, bool $writable): void
    {
        $this->directoryWritableOverrides[$path] = $writable;
    }

    public function setMemoryLimitBytesOverride(?int $bytes): void
    {
        $this->memoryLimitBytesOverride = $bytes;
    }

    public function check(): SystemRequirementsReport
    {
        $items = [];

        // 1. PHP Version
        $currentPhp = $this->phpVersionOverride ?? PHP_VERSION;
        $phpPassed = version_compare($currentPhp, self::MIN_PHP_VERSION, '>=');
        $items[] = new SystemRequirementItem(
            key: 'php_version',
            category: 'runtime',
            name: 'PHP Version',
            required: '>= ' . self::MIN_PHP_VERSION,
            current: $currentPhp,
            passed: $phpPassed,
            severity: 'REQUIRED',
            message: $phpPassed
                ? sprintf('PHP %s meets the minimum required version (%s).', $currentPhp, self::MIN_PHP_VERSION)
                : sprintf('PHP %s is too old. Upgrade to PHP %s or higher.', $currentPhp, self::MIN_PHP_VERSION)
        );

        // 2. Required PHP Extensions
        $requiredExtensions = [
            'pdo' => 'PDO Database Abstraction',
            'mbstring' => 'Multibyte String Support',
            'openssl' => 'OpenSSL Cryptography',
            'json' => 'JSON Parser & Serializer',
            'intl' => 'Internationalization (intl) Support',
            'curl' => 'Client URL Library (cURL)',
            'xml' => 'XML Processing Support',
            'fileinfo' => 'File Information Utility (MIME Detection)',
        ];

        foreach ($requiredExtensions as $ext => $name) {
            $isLoaded = $this->isExtensionLoaded($ext);
            $items[] = new SystemRequirementItem(
                key: 'ext_' . $ext,
                category: 'extension',
                name: $name . ' (' . $ext . ')',
                required: 'Enabled',
                current: $isLoaded ? 'Enabled' : 'Missing',
                passed: $isLoaded,
                severity: 'REQUIRED',
                message: $isLoaded
                    ? sprintf('Extension [%s] is active.', $ext)
                    : sprintf('Required extension [%s] is not loaded in PHP configuration.', $ext)
            );
        }

        // Database Driver Extension (pdo_mysql or pdo_sqlite)
        $hasMysql = $this->isExtensionLoaded('pdo_mysql');
        $hasSqlite = $this->isExtensionLoaded('pdo_sqlite');
        $hasDbDriver = $hasMysql || $hasSqlite;
        $items[] = new SystemRequirementItem(
            key: 'ext_pdo_driver',
            category: 'extension',
            name: 'PDO Database Driver (MySQL or SQLite)',
            required: 'pdo_mysql or pdo_sqlite',
            current: $hasDbDriver ? ($hasMysql ? 'pdo_mysql' : 'pdo_sqlite') : 'None',
            passed: $hasDbDriver,
            severity: 'REQUIRED',
            message: $hasDbDriver
                ? 'Supported PDO database driver available.'
                : 'Neither pdo_mysql nor pdo_sqlite is enabled.'
        );

        // Recommended Extension: GD / Imagick for invoice logos & image processing
        $hasGd = $this->isExtensionLoaded('gd');
        $hasImagick = $this->isExtensionLoaded('imagick');
        $hasGraphics = $hasGd || $hasImagick;
        $items[] = new SystemRequirementItem(
            key: 'ext_graphics',
            category: 'extension',
            name: 'Image Processing (gd or imagick)',
            required: 'Recommended',
            current: $hasGraphics ? 'Enabled' : 'Missing',
            passed: $hasGraphics,
            severity: 'RECOMMENDED',
            message: $hasGraphics
                ? 'Graphics processing extension enabled.'
                : 'Neither gd nor imagick is enabled. Invoice logo resizing may be limited.'
        );

        // 3. Writable Directories
        foreach ($this->writableDirectories as $dir) {
            $isWritable = $this->isDirectoryWritable($dir);
            $items[] = new SystemRequirementItem(
                key: 'dir_' . str_replace(['/', '\\'], '_', $dir),
                category: 'filesystem',
                name: 'Writable Directory: ' . $dir,
                required: 'Writable',
                current: $isWritable ? 'Writable' : 'Read-only / Not found',
                passed: $isWritable,
                severity: 'REQUIRED',
                message: $isWritable
                    ? sprintf('Directory [%s] has write permissions.', $dir)
                    : sprintf('Directory [%s] is not writable or does not exist.', $dir)
            );
        }

        // 4. Memory Limit
        $memoryBytes = $this->getMemoryLimitBytes();
        $minMemoryBytes = 128 * 1024 * 1024; // 128MB
        $memoryPassed = $memoryBytes === -1 || $memoryBytes >= $minMemoryBytes;
        $items[] = new SystemRequirementItem(
            key: 'memory_limit',
            category: 'setting',
            name: 'PHP Memory Limit',
            required: '>= 128M',
            current: $memoryBytes === -1 ? 'Unlimited' : sprintf('%dM', (int) round($memoryBytes / (1024 * 1024))),
            passed: $memoryPassed,
            severity: 'REQUIRED',
            message: $memoryPassed
                ? 'Memory limit is sufficient for application workflows.'
                : 'Memory limit is less than 128M. Increase memory_limit in php.ini.'
        );

        return new SystemRequirementsReport($items);
    }

    private function isExtensionLoaded(string $extension): bool
    {
        $key = strtolower($extension);
        if (array_key_exists($key, $this->extensionOverrides)) {
            return $this->extensionOverrides[$key];
        }

        return extension_loaded($extension);
    }

    private function isDirectoryWritable(string $dir): bool
    {
        if (array_key_exists($dir, $this->directoryWritableOverrides)) {
            return $this->directoryWritableOverrides[$dir];
        }

        if (file_exists($dir)) {
            return is_writable($dir);
        }

        // In case relative path exists under current directory
        $abs = getcwd() . DIRECTORY_SEPARATOR . $dir;
        if (file_exists($abs)) {
            return is_writable($abs);
        }

        return false;
    }

    private function getMemoryLimitBytes(): int
    {
        if ($this->memoryLimitBytesOverride !== null) {
            return $this->memoryLimitBytesOverride;
        }

        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return -1;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $val = (int) substr($limit, 0, -1);

        return match ($last) {
            'g' => $val * 1024 * 1024 * 1024,
            'm' => $val * 1024 * 1024,
            'k' => $val * 1024,
            default => (int) $limit,
        };
    }
}
