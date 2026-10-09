<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Updater;

use Coleza\Domain\Updater\ModuleCompatibilityChecker;
use Coleza\Domain\Updater\PackageSignatureVerifier;
use Coleza\Domain\Updater\StagedUpdateReport;
use Coleza\Domain\Updater\StagedUpdateService;
use Coleza\Domain\Updater\UpdatePackageManifest;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class StagedUpdateAndCompatibilityTest extends TestCase
{
    private string $tempDir;
    private string $targetAppDir;
    private Connection $db;
    /** @var array{public: string, private: string} */
    private array $keyPair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'staged_update_test_' . uniqid();
        $this->targetAppDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'target_app_test_' . uniqid();

        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/files', 0777, true);
        mkdir($this->targetAppDir, 0777, true);

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->keyPair = PackageSignatureVerifier::generateKeyPair();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        $this->removeDirectory($this->targetAppDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createSignedPackage(
        UpdatePackageManifest $manifest,
        ?string $signingPrivateKey = null
    ): void {
        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->tempDir . '/manifest.json', $json);

        $key = $signingPrivateKey ?? $this->keyPair['private'];
        $sig = PackageSignatureVerifier::signPayload((string) $json, $key);
        file_put_contents($this->tempDir . '/manifest.sig', $sig);
    }

    public function testSignatureVerificationPassesOnUntamperedPackage(): void
    {
        // 1. Create payload file
        $relPath = 'src/Core/Version.php';
        $fullPath = $this->tempDir . '/files/' . $relPath;
        @mkdir(dirname($fullPath), 0777, true);
        file_put_contents($fullPath, '<?php return "1.1.0";');

        $sha256 = hash_file('sha256', $fullPath);

        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Update to 1.1.0',
            fileChecksums: [$relPath => (string) $sha256]
        );

        $this->createSignedPackage($manifest);

        $verifier = new PackageSignatureVerifier($this->keyPair['public']);
        $checker = new ModuleCompatibilityChecker($this->db);
        $updater = new StagedUpdateService($verifier, $checker, '1.0.0', $this->db);

        $report = $updater->validateStagedPackage($this->tempDir);

        $this->assertTrue($report->isSignatureValid());
        $this->assertTrue($report->isChecksumsValid());
        $this->assertTrue($report->isModulesCompatible());
        $this->assertTrue($report->isReadyToApply());
        $this->assertSame('1.1.0', $report->getTargetVersion());
    }

    public function testSignatureVerificationFailsOnTamperedManifest(): void
    {
        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Original',
            fileChecksums: []
        );

        $this->createSignedPackage($manifest);

        // Tamper manifest after signing
        $tampered = json_encode(['version' => '1.1.0-malicious', 'file_checksums' => []]);
        file_put_contents($this->tempDir . '/manifest.json', $tampered);

        $verifier = new PackageSignatureVerifier($this->keyPair['public']);
        $checker = new ModuleCompatibilityChecker($this->db);
        $updater = new StagedUpdateService($verifier, $checker, '1.0.0', $this->db);

        $report = $updater->validateStagedPackage($this->tempDir);

        $this->assertFalse($report->isSignatureValid());
        $this->assertFalse($report->isReadyToApply());
        $this->assertStringContainsString('signature verification failed', (string) $report->getError());
    }

    public function testFileChecksumMismatchDetected(): void
    {
        $relPath = 'config/app.php';
        $fullPath = $this->tempDir . '/files/' . $relPath;
        @mkdir(dirname($fullPath), 0777, true);
        file_put_contents($fullPath, 'valid content');

        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Checksum test',
            fileChecksums: [$relPath => 'corrupted_or_bad_hash_value_1234567890abcdef']
        );

        $this->createSignedPackage($manifest);

        $verifier = new PackageSignatureVerifier($this->keyPair['public']);
        $checker = new ModuleCompatibilityChecker($this->db);
        $updater = new StagedUpdateService($verifier, $checker, '1.0.0', $this->db);

        $report = $updater->validateStagedPackage($this->tempDir);

        $this->assertTrue($report->isSignatureValid());
        $this->assertFalse($report->isChecksumsValid());
        $this->assertFalse($report->isReadyToApply());
        $this->assertCount(1, $report->getFailedChecksumFiles());
    }

    public function testModuleCompatibilityRejectsIncompatibleModules(): void
    {
        $checker = new ModuleCompatibilityChecker($this->db);

        // Seed an installed module with max_core_version: 1.0.0
        $manifestMod = [
            'id' => 'legacy_payment_mod',
            'name' => 'Legacy Gateway',
            'version' => '1.0.0',
            'type' => 'payment_gateway',
            'min_core_version' => '1.0.0',
            'max_core_version' => '1.0.9',
        ];

        $this->db->statement(
            "CREATE TABLE IF NOT EXISTS installed_modules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id VARCHAR(100) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                version VARCHAR(50) NOT NULL,
                type VARCHAR(50) NOT NULL,
                is_enabled INT NOT NULL DEFAULT 1,
                manifest LONGTEXT NOT NULL
            )"
        );

        $this->db->statement(
            "INSERT INTO installed_modules (module_id, name, version, type, is_enabled, manifest) VALUES (?, ?, ?, ?, 1, ?)",
            ['legacy_payment_mod', 'Legacy Gateway', '1.0.0', 'payment_gateway', json_encode($manifestMod)]
        );

        // Check against target 1.2.0
        $result = $checker->checkCompatibility('1.2.0');

        $this->assertFalse($result['compatible']);
        $this->assertCount(1, $result['incompatible_modules']);
        $this->assertStringContainsString('exceeds module maximum tested version', $result['incompatible_modules'][0]['reason']);
    }

    public function testApplyValidatedUpdateCopiesFiles(): void
    {
        $relPath = 'version.txt';
        $fullPath = $this->tempDir . '/files/' . $relPath;
        file_put_contents($fullPath, 'v1.1.0-release');
        $sha = hash_file('sha256', $fullPath);

        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Atomic copy test',
            fileChecksums: [$relPath => (string) $sha]
        );

        $this->createSignedPackage($manifest);

        $verifier = new PackageSignatureVerifier($this->keyPair['public']);
        $checker = new ModuleCompatibilityChecker($this->db);
        $updater = new StagedUpdateService($verifier, $checker, '1.0.0', $this->db);

        $applyResult = $updater->applyValidatedUpdate($this->tempDir, $this->targetAppDir);

        $this->assertTrue($applyResult['success']);
        $this->assertSame(1, $applyResult['files_applied']);
        $this->assertFileExists($this->targetAppDir . '/version.txt');
        $this->assertSame('v1.1.0-release', file_get_contents($this->targetAppDir . '/version.txt'));
    }
}
