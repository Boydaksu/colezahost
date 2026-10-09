<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

/**
 * Configuration DTO for fresh-install migration import.
 */
final class FreshInstallMigrationConfig
{
    /**
     * @param string $sourceType Source platform type (e.g. 'whmcs', 'csv')
     * @param array<string, mixed> $connectionConfig Database/file connection parameters for source
     * @param bool $autoHold Automatically engage global migration hold during and post migration
     * @param bool $suppressNotifications Suppress customer notifications during initial data ingestion
     * @param string $conflictStrategy Strategy for conflicts ('skip', 'quarantine', 'overwrite')
     * @param array<string, mixed> $options Additional source-specific options
     */
    public function __construct(
        private string $sourceType,
        private array $connectionConfig,
        private bool $autoHold = true,
        private bool $suppressNotifications = true,
        private string $conflictStrategy = 'quarantine',
        private array $options = []
    ) {
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConnectionConfig(): array
    {
        return $this->connectionConfig;
    }

    public function shouldAutoHold(): bool
    {
        return $this->autoHold;
    }

    public function shouldSuppressNotifications(): bool
    {
        return $this->suppressNotifications;
    }

    public function getConflictStrategy(): string
    {
        return $this->conflictStrategy;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
