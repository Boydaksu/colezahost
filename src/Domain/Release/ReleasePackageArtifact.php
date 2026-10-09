<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

use JsonSerializable;

/**
 * Encapsulates the generated release package artifact bundle,
 * cryptographic signature, checksums, and archive locations.
 */
final class ReleasePackageArtifact implements JsonSerializable
{
    /**
     * @param array<string, string> $fileChecksums
     * @param array<string, mixed> $manifestData
     */
    public function __construct(
        private string $version,
        private string $archivePath,
        private string $manifestPath,
        private string $signaturePath,
        private string $checksumsPath,
        private string $releaseNotesPath,
        private string $archiveSha256,
        private int $archiveSizeBytes,
        private int $filesCount,
        private array $fileChecksums,
        private array $manifestData,
        private string $createdAt
    ) {
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getArchivePath(): string
    {
        return $this->archivePath;
    }

    public function getManifestPath(): string
    {
        return $this->manifestPath;
    }

    public function getSignaturePath(): string
    {
        return $this->signaturePath;
    }

    public function getChecksumsPath(): string
    {
        return $this->checksumsPath;
    }

    public function getReleaseNotesPath(): string
    {
        return $this->releaseNotesPath;
    }

    public function getArchiveSha256(): string
    {
        return $this->archiveSha256;
    }

    public function getArchiveSizeBytes(): int
    {
        return $this->archiveSizeBytes;
    }

    public function getFilesCount(): int
    {
        return $this->filesCount;
    }

    /**
     * @return array<string, string>
     */
    public function getFileChecksums(): array
    {
        return $this->fileChecksums;
    }

    /**
     * @return array<string, mixed>
     */
    public function getManifestData(): array
    {
        return $this->manifestData;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'archive_path' => $this->archivePath,
            'manifest_path' => $this->manifestPath,
            'signature_path' => $this->signaturePath,
            'checksums_path' => $this->checksumsPath,
            'release_notes_path' => $this->releaseNotesPath,
            'archive_sha256' => $this->archiveSha256,
            'archive_size_bytes' => $this->archiveSizeBytes,
            'files_count' => $this->filesCount,
            'created_at' => $this->createdAt,
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
