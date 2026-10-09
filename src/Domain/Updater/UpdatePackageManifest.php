<?php

declare(strict_types=1);

namespace Coleza\Domain\Updater;

/**
 * Manifest definition for an update package.
 */
final class UpdatePackageManifest
{
    /**
     * @param string $version Target application version (e.g. '1.1.0')
     * @param string $minCurrentVersion Minimum installed version required to apply this update
     * @param string $releaseNotes Markdown or text summary of release changes
     * @param array<string, string> $fileChecksums Relative file path => SHA256 checksum
     * @param array<string, string> $requiredModules Module id => minimum compatible version
     * @param array<int, string> $migrations Ordered list of migration class/file names to run
     * @param string $releasedAt ISO 8601 release timestamp
     */
    public function __construct(
        private string $version,
        private string $minCurrentVersion,
        private string $releaseNotes,
        private array $fileChecksums,
        private array $requiredModules = [],
        private array $migrations = [],
        private ?string $releasedAt = null
    ) {
        $this->releasedAt = $releasedAt ?? date('c');
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getMinCurrentVersion(): string
    {
        return $this->minCurrentVersion;
    }

    public function getReleaseNotes(): string
    {
        return $this->releaseNotes;
    }

    /**
     * @return array<string, string>
     */
    public function getFileChecksums(): array
    {
        return $this->fileChecksums;
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredModules(): array
    {
        return $this->requiredModules;
    }

    /**
     * @return array<int, string>
     */
    public function getMigrations(): array
    {
        return $this->migrations;
    }

    public function getReleasedAt(): string
    {
        return (string) $this->releasedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'min_current_version' => $this->minCurrentVersion,
            'release_notes' => $this->releaseNotes,
            'file_checksums' => $this->fileChecksums,
            'required_modules' => $this->requiredModules,
            'migrations' => $this->migrations,
            'released_at' => $this->releasedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: (string) ($data['version'] ?? '1.0.0'),
            minCurrentVersion: (string) ($data['min_current_version'] ?? '1.0.0'),
            releaseNotes: (string) ($data['release_notes'] ?? ''),
            fileChecksums: is_array($data['file_checksums'] ?? null) ? $data['file_checksums'] : [],
            requiredModules: is_array($data['required_modules'] ?? null) ? $data['required_modules'] : [],
            migrations: is_array($data['migrations'] ?? null) ? array_values($data['migrations']) : [],
            releasedAt: isset($data['released_at']) ? (string) $data['released_at'] : null
        );
    }
}
