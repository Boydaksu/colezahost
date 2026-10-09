<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

use Coleza\Domain\Updater\PackageSignatureVerifier;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Enterprise Release Packaging Service.
 * Bundles distribution assets, computes deterministic SHA256 checksums,
 * signs manifests cryptographically using Ed25519 keys, and validates
 * distribution packages prior to release deployment.
 */
final class ReleasePackagingService
{
    public function __construct(
        private string $workingDirectory
    ) {
        if (!is_dir($workingDirectory)) {
            mkdir($workingDirectory, 0755, true);
        }
    }

    /**
     * Builds and packages a verified release artifact bundle.
     *
     * @param array<string, string> $filesToBundle Relative path in archive => Absolute filesystem source path
     * @param string $version Semantic version string (e.g. '1.0.0')
     * @param string $releaseNotes Markdown formatted release notes
     * @param string $signingPrivateKey Hex or Base64 Ed25519 private key
     * @param ?string $outputZipPath Optional custom archive destination
     * @return ReleasePackageArtifact
     */
    public function buildPackage(
        array $filesToBundle,
        string $version,
        string $releaseNotes,
        string $signingPrivateKey,
        ?string $outputZipPath = null
    ): ReleasePackageArtifact {
        $stageId = 'pkg_build_' . bin2hex(random_bytes(6));
        $stageDir = rtrim($this->workingDirectory, '/\\') . DIRECTORY_SEPARATOR . $stageId;
        mkdir($stageDir, 0755, true);

        try {
            $fileChecksums = [];
            $totalBytes = 0;

            // 1. Process and compute SHA256 for all payload files
            foreach ($filesToBundle as $relPath => $srcPath) {
                if (!file_exists($srcPath)) {
                    throw new RuntimeException("Source payload file does not exist: {$srcPath}");
                }
                $cleanRelPath = str_replace('\\', '/', ltrim($relPath, '/\\'));
                $sha = hash_file('sha256', $srcPath);
                $fileChecksums[$cleanRelPath] = (string) $sha;
                $totalBytes += (int) filesize($srcPath);
            }
            ksort($fileChecksums);

            $createdAt = date('c');

            // 2. Generate manifest.json
            $manifestData = [
                'name' => 'Coleza Host',
                'version' => $version,
                'min_current_version' => '1.0.0',
                'release_type' => 'stable',
                'files_count' => count($fileChecksums),
                'total_payload_bytes' => $totalBytes,
                'created_at' => $createdAt,
                'required_extensions' => ['pdo', 'mbstring', 'json', 'filter', 'sodium'],
                'file_checksums' => $fileChecksums,
            ];

            $manifestJson = (string) json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $manifestPath = $stageDir . DIRECTORY_SEPARATOR . 'manifest.json';
            file_put_contents($manifestPath, $manifestJson);

            // 3. Cryptographically sign manifest with Ed25519
            $sigBase64 = PackageSignatureVerifier::signPayload($manifestJson, $signingPrivateKey, 'ed25519');
            $signaturePath = $stageDir . DIRECTORY_SEPARATOR . 'manifest.sig';
            file_put_contents($signaturePath, $sigBase64);

            // 4. Generate CHECKSUMS.sha256 in standard sha256sum format
            $checksumsLines = [];
            foreach ($fileChecksums as $rel => $hash) {
                $checksumsLines[] = sprintf('%s  %s', $hash, $rel);
            }
            $checksumsPath = $stageDir . DIRECTORY_SEPARATOR . 'CHECKSUMS.sha256';
            file_put_contents($checksumsPath, implode("\n", $checksumsLines) . "\n");

            // 5. Save RELEASE_NOTES.md
            $releaseNotesPath = $stageDir . DIRECTORY_SEPARATOR . 'RELEASE_NOTES.md';
            file_put_contents($releaseNotesPath, $releaseNotes);

            // 6. Assemble into distribution ZIP archive
            $zipPath = $outputZipPath ?? ($this->workingDirectory . DIRECTORY_SEPARATOR . sprintf('coleza-host-v%s.zip', $version));
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }

            $zip = new ZipArchive();
            $zipRes = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($zipRes !== true) {
                throw new RuntimeException("Failed to create ZIP package at [{$zipPath}]: ZipArchive error {$zipRes}");
            }

            // Add bundle files
            foreach ($filesToBundle as $relPath => $srcPath) {
                $cleanRelPath = 'payload/' . str_replace('\\', '/', ltrim($relPath, '/\\'));
                $zip->addFile($srcPath, $cleanRelPath);
            }

            // Add metadata files at archive root
            $zip->addFile($manifestPath, 'manifest.json');
            $zip->addFile($signaturePath, 'manifest.sig');
            $zip->addFile($checksumsPath, 'CHECKSUMS.sha256');
            $zip->addFile($releaseNotesPath, 'RELEASE_NOTES.md');

            $zip->close();

            $archiveSha256 = (string) hash_file('sha256', $zipPath);
            $archiveSize = (int) filesize($zipPath);

            return new ReleasePackageArtifact(
                version: $version,
                archivePath: $zipPath,
                manifestPath: $manifestPath,
                signaturePath: $signaturePath,
                checksumsPath: $checksumsPath,
                releaseNotesPath: $releaseNotesPath,
                archiveSha256: $archiveSha256,
                archiveSizeBytes: $archiveSize,
                filesCount: count($fileChecksums),
                fileChecksums: $fileChecksums,
                manifestData: $manifestData,
                createdAt: $createdAt
            );
        } finally {
            // Cleanup transient staging files except the final zip
            // (Files in $stageDir can be preserved or cleaned up)
        }
    }

    /**
     * Inspects and validates a release package ZIP archive against an expected public key.
     */
    public function verifyPackageArchive(string $zipPath, string $expectedPublicKey): ReleaseVerificationReport
    {
        if (!file_exists($zipPath)) {
            return new ReleaseVerificationReport(
                version: 'unknown',
                signatureValid: false,
                checksumsValid: false,
                errorMessage: "Archive file does not exist: {$zipPath}"
            );
        }

        $inspectDir = rtrim($this->workingDirectory, '/\\') . DIRECTORY_SEPARATOR . 'inspect_' . bin2hex(random_bytes(6));
        mkdir($inspectDir, 0755, true);

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return new ReleaseVerificationReport(
                    version: 'unknown',
                    signatureValid: false,
                    checksumsValid: false,
                    errorMessage: "Failed to open ZIP archive: {$zipPath}"
                );
            }

            $zip->extractTo($inspectDir);
            $zip->close();

            $manifestFile = $inspectDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $signatureFile = $inspectDir . DIRECTORY_SEPARATOR . 'manifest.sig';

            if (!file_exists($manifestFile) || !file_exists($signatureFile)) {
                return new ReleaseVerificationReport(
                    version: 'unknown',
                    signatureValid: false,
                    checksumsValid: false,
                    errorMessage: 'manifest.json or manifest.sig is missing from archive root.'
                );
            }

            $manifestContent = (string) file_get_contents($manifestFile);
            $signatureBase64 = trim((string) file_get_contents($signatureFile));

            // 1. Verify Detached Ed25519 Cryptographic Signature
            $verifier = new PackageSignatureVerifier($expectedPublicKey, 'ed25519');
            $sigValid = $verifier->verify($manifestContent, $signatureBase64);
            if (!$sigValid) {
                return new ReleaseVerificationReport(
                    version: 'unknown',
                    signatureValid: false,
                    checksumsValid: false,
                    errorMessage: 'Detached cryptographic signature verification failed: package may be tampered with.'
                );
            }

            $manifestData = json_decode($manifestContent, true);
            if (!is_array($manifestData)) {
                return new ReleaseVerificationReport(
                    version: 'unknown',
                    signatureValid: true,
                    checksumsValid: false,
                    errorMessage: 'Invalid manifest JSON structure.'
                );
            }

            $version = (string) ($manifestData['version'] ?? 'unknown');
            $expectedChecksums = (array) ($manifestData['file_checksums'] ?? []);

            // 2. Verify all payload file checksums
            $tampered = [];
            $missing = [];

            foreach ($expectedChecksums as $relPath => $expectedHash) {
                $filePath = $inspectDir . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relPath);
                if (!file_exists($filePath)) {
                    $missing[] = (string) $relPath;
                    continue;
                }

                $actualHash = (string) hash_file('sha256', $filePath);
                if (!hash_equals(strtolower((string) $expectedHash), strtolower($actualHash))) {
                    $tampered[] = (string) $relPath;
                }
            }

            $checksumsValid = empty($tampered) && empty($missing);

            return new ReleaseVerificationReport(
                version: $version,
                signatureValid: true,
                checksumsValid: $checksumsValid,
                tamperedFiles: $tampered,
                missingFiles: $missing,
                errorMessage: null,
                metadata: [
                    'files_checked' => count($expectedChecksums),
                    'verified_at' => date('c'),
                ]
            );
        } catch (Throwable $e) {
            return new ReleaseVerificationReport(
                version: 'unknown',
                signatureValid: false,
                checksumsValid: false,
                errorMessage: 'Exception during verification: ' . $e->getMessage()
            );
        } finally {
            $this->deleteDirectory($inspectDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
