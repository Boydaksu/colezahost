<?php

declare(strict_types=1);

namespace Coleza\Domain\Updater;

/**
 * Result of staged update validation and preparation.
 */
final class StagedUpdateReport
{
    /**
     * @param string $packagePath Path to staged update directory/archive
     * @param string $targetVersion Target core version
     * @param string $currentVersion Current core version
     * @param bool $signatureValid Whether detached cryptographic signature is verified
     * @param bool $checksumsValid Whether all file hashes match the manifest exactly
     * @param bool $modulesCompatible Whether installed modules are compatible
     * @param array<int, string> $failedChecksumFiles List of relative paths where checksum failed
     * @param array<int, array<string, mixed>> $incompatibleModules List of incompatible modules
     * @param array<int, string> $migrations Ordered list of migrations to run
     * @param ?string $error Top-level error message if validation failed
     */
    public function __construct(
        private string $packagePath,
        private string $targetVersion,
        private string $currentVersion,
        private bool $signatureValid,
        private bool $checksumsValid,
        private bool $modulesCompatible,
        private array $failedChecksumFiles = [],
        private array $incompatibleModules = [],
        private array $migrations = [],
        private ?string $error = null
    ) {
    }

    public function getPackagePath(): string
    {
        return $this->packagePath;
    }

    public function getTargetVersion(): string
    {
        return $this->targetVersion;
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    public function isSignatureValid(): bool
    {
        return $this->signatureValid;
    }

    public function isChecksumsValid(): bool
    {
        return $this->checksumsValid;
    }

    public function isModulesCompatible(): bool
    {
        return $this->modulesCompatible;
    }

    /**
     * @return array<int, string>
     */
    public function getFailedChecksumFiles(): array
    {
        return $this->failedChecksumFiles;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIncompatibleModules(): array
    {
        return $this->incompatibleModules;
    }

    /**
     * @return array<int, string>
     */
    public function getMigrations(): array
    {
        return $this->migrations;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isReadyToApply(): bool
    {
        return $this->signatureValid
            && $this->checksumsValid
            && $this->modulesCompatible
            && empty($this->failedChecksumFiles)
            && empty($this->incompatibleModules)
            && $this->error === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package_path' => $this->packagePath,
            'target_version' => $this->targetVersion,
            'current_version' => $this->currentVersion,
            'signature_valid' => $this->signatureValid,
            'checksums_valid' => $this->checksumsValid,
            'modules_compatible' => $this->modulesCompatible,
            'ready_to_apply' => $this->isReadyToApply(),
            'failed_checksum_files' => $this->failedChecksumFiles,
            'incompatible_modules' => $this->incompatibleModules,
            'migrations' => $this->migrations,
            'error' => $this->error,
        ];
    }
}
