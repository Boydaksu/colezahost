<?php

declare(strict_types=1);

namespace Tests\Integration\ReleasePackage;

use Coleza\Domain\Release\ReleasePackagingService;
use Coleza\Domain\Updater\PackageSignatureVerifier;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * P18.9 Release Package, Assets, Checksum, Signature & Release Notes Suite:
 * 1. PKG-01: Deterministic SHA256 checksum manifest generation
 * 2. PKG-02: Cryptographic Ed25519 detached signature and package verification
 * 3. PKG-03: Tamper detection & rejection (file tampering & manifest tampering)
 * 4. PKG-04: Distribution archive bundling & structure integrity
 * 5. PKG-05: Release notes completeness & version alignment
 */
final class ReleasePackagingAndSignatureSuiteTest extends TestCase
{
    private string $tempDir;
    private ReleasePackagingService $service;
    /** @var array{public: string, private: string, scheme: string} */
    private array $keyPair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coleza_pkg_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);

        $this->service = new ReleasePackagingService($this->tempDir);
        $this->keyPair = PackageSignatureVerifier::generateKeyPair('ed25519');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
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

    /**
     * PKG-01 & PKG-04: Deterministic Checksum Manifest & Archive Bundling.
     */
    public function testReleasePackageGenerationAndChecksumManifest(): void
    {
        $payloadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'payload_src';
        mkdir($payloadDir . '/src', 0755, true);
        mkdir($payloadDir . '/config', 0755, true);

        file_put_contents($payloadDir . '/src/Kernel.php', '<?php echo "kernel";');
        file_put_contents($payloadDir . '/config/app.php', '<?php return ["name" => "Coleza Host"];');

        $files = [
            'src/Kernel.php' => $payloadDir . '/src/Kernel.php',
            'config/app.php' => $payloadDir . '/config/app.php',
        ];

        $releaseNotes = "# Release Notes V1.0.0\nStable release.";
        $artifact = $this->service->buildPackage(
            filesToBundle: $files,
            version: '1.0.0',
            releaseNotes: $releaseNotes,
            signingPrivateKey: $this->keyPair['private']
        );

        $this->assertSame('1.0.0', $artifact->getVersion());
        $this->assertSame(2, $artifact->getFilesCount());
        $this->assertFileExists($artifact->getArchivePath());
        $this->assertNotEmpty($artifact->getArchiveSha256());
        $this->assertGreaterThan(0, $artifact->getArchiveSizeBytes());

        // Checksums verification
        $checksums = $artifact->getFileChecksums();
        $this->assertArrayHasKey('src/Kernel.php', $checksums);
        $this->assertArrayHasKey('config/app.php', $checksums);
        $this->assertSame(hash_file('sha256', $payloadDir . '/src/Kernel.php'), $checksums['src/Kernel.php']);

        // Inspect ZIP structure
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($artifact->getArchivePath()));
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('manifest.sig'));
        $this->assertNotFalse($zip->locateName('CHECKSUMS.sha256'));
        $this->assertNotFalse($zip->locateName('RELEASE_NOTES.md'));
        $this->assertNotFalse($zip->locateName('payload/src/Kernel.php'));
        $zip->close();
    }

    /**
     * PKG-02: Cryptographic Ed25519 Detached Signature Verification.
     */
    public function testCryptographicSignatureAndArchiveVerification(): void
    {
        $payloadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'payload_src2';
        mkdir($payloadDir, 0755, true);
        file_put_contents($payloadDir . '/version.json', '{"version": "1.0.0"}');

        $files = ['version.json' => $payloadDir . '/version.json'];
        $artifact = $this->service->buildPackage(
            filesToBundle: $files,
            version: '1.0.0',
            releaseNotes: 'V1 Stable Release',
            signingPrivateKey: $this->keyPair['private']
        );

        $report = $this->service->verifyPackageArchive($artifact->getArchivePath(), $this->keyPair['public']);

        $this->assertTrue($report->isValid());
        $this->assertTrue($report->isSignatureValid());
        $this->assertTrue($report->isChecksumsValid());
        $this->assertEmpty($report->getTamperedFiles());
        $this->assertEmpty($report->getMissingFiles());
        $this->assertSame('1.0.0', $report->getVersion());
    }

    /**
     * PKG-03: Tamper Detection & Rejection.
     */
    public function testTamperedPackageDetectionAndRejection(): void
    {
        $payloadDir = $this->tempDir . DIRECTORY_SEPARATOR . 'payload_src3';
        mkdir($payloadDir, 0755, true);
        file_put_contents($payloadDir . '/file1.txt', 'clean content');

        $files = ['file1.txt' => $payloadDir . '/file1.txt'];
        $artifact = $this->service->buildPackage(
            filesToBundle: $files,
            version: '1.0.0',
            releaseNotes: 'V1 Notes',
            signingPrivateKey: $this->keyPair['private']
        );

        // Scenario A: Tampering payload file inside archive
        $tamperedZip = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered.zip';
        copy($artifact->getArchivePath(), $tamperedZip);

        $zip = new ZipArchive();
        $zip->open($tamperedZip);
        $zip->deleteName('payload/file1.txt');
        $zip->addFromString('payload/file1.txt', 'MALICIOUS MODIFIED CONTENT');
        $zip->close();

        $reportA = $this->service->verifyPackageArchive($tamperedZip, $this->keyPair['public']);
        $this->assertFalse($reportA->isValid());
        $this->assertFalse($reportA->isChecksumsValid());
        $this->assertContains('file1.txt', $reportA->getTamperedFiles());

        // Scenario B: Tampering manifest.json causes cryptographic signature failure
        $tamperedManifestZip = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered_manifest.zip';
        copy($artifact->getArchivePath(), $tamperedManifestZip);

        $zip = new ZipArchive();
        $zip->open($tamperedManifestZip);
        $manifestContent = (string) $zip->getFromName('manifest.json');
        $zip->deleteName('manifest.json');
        $zip->addFromString('manifest.json', $manifestContent . ' ');
        $zip->close();

        $reportB = $this->service->verifyPackageArchive($tamperedManifestZip, $this->keyPair['public']);
        $this->assertFalse($reportB->isValid());
        $this->assertFalse($reportB->isSignatureValid());
    }

    /**
     * PKG-05: Release Notes Completeness & Invariant Alignment.
     */
    public function testReleaseNotesCompletenessAndVersionAlignment(): void
    {
        $notesPath = dirname(__DIR__, 3) . '/RELEASE_NOTES.md';
        $this->assertFileExists($notesPath);

        $content = (string) file_get_contents($notesPath);

        // Core release invariants
        $this->assertStringContainsString('Version 1.0.0 Stable', $content);
        $this->assertStringContainsString('coleza-host-v1.0.0.zip', $content);
        $this->assertStringContainsString('Ed25519', $content);
        $this->assertStringContainsString('SHA256', $content);
        $this->assertStringContainsString('Zero-Silent-Loss', $content);
        $this->assertStringContainsString('Cent-for-Cent Financial Ledger Reconciliation', $content);
        $this->assertStringContainsString('GDPR Privacy Tombstone Invariant', $content);
        $this->assertStringContainsString('Constrained Shared-Host Compatibility', $content);
        $this->assertStringContainsString('WCAG 2.1 AA', $content);
        $this->assertStringContainsString('PHP 8.4', $content);
    }
}
