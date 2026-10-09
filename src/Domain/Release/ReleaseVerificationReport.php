<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

use JsonSerializable;

/**
 * Report generated upon cryptographic and checksum verification of a release package.
 */
final class ReleaseVerificationReport implements JsonSerializable
{
    /**
     * @param list<string> $tamperedFiles
     * @param list<string> $missingFiles
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $version,
        private bool $signatureValid,
        private bool $checksumsValid,
        private array $tamperedFiles = [],
        private array $missingFiles = [],
        private ?string $errorMessage = null,
        private array $metadata = []
    ) {
    }

    public function isValid(): bool
    {
        return $this->signatureValid
            && $this->checksumsValid
            && empty($this->tamperedFiles)
            && empty($this->missingFiles)
            && $this->errorMessage === null;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function isSignatureValid(): bool
    {
        return $this->signatureValid;
    }

    public function isChecksumsValid(): bool
    {
        return $this->checksumsValid;
    }

    /**
     * @return list<string>
     */
    public function getTamperedFiles(): array
    {
        return $this->tamperedFiles;
    }

    /**
     * @return list<string>
     */
    public function getMissingFiles(): array
    {
        return $this->missingFiles;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'is_valid' => $this->isValid(),
            'signature_valid' => $this->signatureValid,
            'checksums_valid' => $this->checksumsValid,
            'tampered_files' => $this->tamperedFiles,
            'missing_files' => $this->missingFiles,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
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
