<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

/**
 * Result report of verifying a backup archive.
 */
final class BackupVerificationReport
{
    /**
     * @param string $backupId
     * @param string $archivePath
     * @param bool $isValid Overall verification success
     * @param bool $archiveExists Whether file exists on storage
     * @param bool $manifestMatchesChecksum Whether archive SHA256 matches manifest hash
     * @param bool $contentsReadable Whether archive can be read/unpacked
     * @param array<int, string> $errors List of identified errors
     * @param array<string, mixed> $details Extra diagnostic metadata
     */
    public function __construct(
        private string $backupId,
        private string $archivePath,
        private bool $isValid,
        private bool $archiveExists,
        private bool $manifestMatchesChecksum,
        private bool $contentsReadable,
        private array $errors = [],
        private array $details = []
    ) {
    }

    public function getBackupId(): string
    {
        return $this->backupId;
    }

    public function getArchivePath(): string
    {
        return $this->archivePath;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function isArchiveExists(): bool
    {
        return $this->archiveExists;
    }

    public function isManifestMatchesChecksum(): bool
    {
        return $this->manifestMatchesChecksum;
    }

    public function isContentsReadable(): bool
    {
        return $this->contentsReadable;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'backup_id' => $this->backupId,
            'archive_path' => $this->archivePath,
            'valid' => $this->isValid,
            'archive_exists' => $this->archiveExists,
            'checksum_matches' => $this->manifestMatchesChecksum,
            'contents_readable' => $this->contentsReadable,
            'errors' => $this->errors,
            'details' => $this->details,
        ];
    }
}
