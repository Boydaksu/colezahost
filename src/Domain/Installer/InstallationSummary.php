<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use JsonSerializable;

/**
 * Value object encapsulating the completed installation record.
 */
final class InstallationSummary implements JsonSerializable
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private string $appVersion,
        private string $installedAt,
        private string $adminEmail,
        private string $brandName,
        private string $databaseDriver,
        private string $defaultLocale,
        private string $defaultCurrency,
        private string $cronCliCommand,
        private string $lockFilePath,
        private array $extra = []
    ) {
    }

    public function getAppVersion(): string
    {
        return $this->appVersion;
    }

    public function getInstalledAt(): string
    {
        return $this->installedAt;
    }

    public function getAdminEmail(): string
    {
        return $this->adminEmail;
    }

    public function getBrandName(): string
    {
        return $this->brandName;
    }

    public function getDatabaseDriver(): string
    {
        return $this->databaseDriver;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    public function getCronCliCommand(): string
    {
        return $this->cronCliCommand;
    }

    public function getLockFilePath(): string
    {
        return $this->lockFilePath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app_version' => $this->appVersion,
            'installed_at' => $this->installedAt,
            'admin_email' => $this->adminEmail,
            'brand_name' => $this->brandName,
            'database_driver' => $this->databaseDriver,
            'default_locale' => $this->defaultLocale,
            'default_currency' => $this->defaultCurrency,
            'cron_cli_command' => $this->cronCliCommand,
            'lock_file_path' => $this->lockFilePath,
            'extra' => $this->extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
