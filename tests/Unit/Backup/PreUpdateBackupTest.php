<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\BackupManifest;
use Coleza\Domain\Backup\BackupVerificationReport;
use Coleza\Domain\Backup\PreUpdateBackupService;
use Coleza\Domain\Updater\ModuleCompatibilityChecker;
use Coleza\Domain\Updater\PackageSignatureVerifier;
use Coleza\Domain\Updater\StagedUpdateService;
use Coleza\Domain\Updater\UpdatePackageManifest;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PreUpdateBackupTest extends TestCase
{
    private string $tempBackupDir;
    private string $tempStagedDir;
    private string $tempTargetAppDir;
    private Connection $db;
    /** @var array{public: string, private: string} */
    private array $keyPair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBackupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup_test_' . uniqid();
        $this->tempStagedDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'staged_upd_' . uniqid();
        $this->tempTargetAppDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'target_app_' . uniqid();

        mkdir($this->tempBackupDir, 0777, true);
        mkdir($this->tempStagedDir, 0777, true);
        mkdir($this->tempStagedDir . '/files', 0777, true);
        mkdir($this->tempTargetAppDir, 0777, true);

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // Seed some sample database tables
        $this->db->statement("CREATE TABLE app_config (key TEXT PRIMARY KEY, val TEXT)");
        $this->db->statement("INSERT INTO app_config (key, val) VALUES ('site_name', 'Coleza Production')");

        $this->keyPair = PackageSignatureVerifier::generateKeyPair();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempBackupDir);
        $this->removeDirectory($this->tempStagedDir);
        $this->removeDirectory($this->tempTargetAppDir);

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

    public function testPreUpdateBackupCreatesVerifiedArchive(): void
    {
        $sampleConfigFile = $this->tempTargetAppDir . '/config.php';
        file_put_contents($sampleConfigFile, "<?php return ['env' => 'production'];");

        $backupService = new PreUpdateBackupService(
            backupStorageDir: $this->tempBackupDir,
            db: $this->db,
            appVersion: '1.0.0'
        );

        $result = $backupService->createVerifiedPreUpdateBackup('1.1.0', [$sampleConfigFile]);

        $this->assertInstanceOf(BackupManifest::class, $result['manifest']);
        $this->assertFileExists($result['archive_path']);
        $this->assertTrue($result['verification']->isValid());
        $this->assertTrue($result['verification']->isManifestMatchesChecksum());
        $this->assertTrue($result['verification']->isContentsReadable());
        $this->assertSame('1.0.0', $result['manifest']->getSourceAppVersion());
        $this->assertSame('1.1.0', $result['manifest']->getMetadata()['target_version']);
    }

    public function testCorruptedBackupFailsIntegrityVerification(): void
    {
        $backupService = new PreUpdateBackupService(
            backupStorageDir: $this->tempBackupDir,
            db: $this->db,
            appVersion: '1.0.0'
        );

        $result = $backupService->createVerifiedPreUpdateBackup('1.1.0');
        $archivePath = $result['archive_path'];

        // Corrupt archive by appending garbage bytes
        file_put_contents($archivePath, 'corrupted_archive_data', FILE_APPEND);

        $verification = $backupService->verifyBackup($result['manifest']->getBackupId());

        $this->assertFalse($verification->isValid());
        $this->assertFalse($verification->isManifestMatchesChecksum());
        $this->assertNotEmpty($verification->getErrors());
    }

    public function testStagedUpdateEnforcesMandatoryPreUpdateBackup(): void
    {
        // 1. Prepare staged package
        $relPath = 'core.php';
        $fullPath = $this->tempStagedDir . '/files/' . $relPath;
        file_put_contents($fullPath, '<?php echo "core v1.1.0";');
        $sha = hash_file('sha256', $fullPath);

        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Backup mandatory test',
            fileChecksums: [$relPath => (string) $sha]
        );

        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->tempStagedDir . '/manifest.json', $json);
        $sig = PackageSignatureVerifier::signPayload((string) $json, $this->keyPair['private']);
        file_put_contents($this->tempStagedDir . '/manifest.sig', $sig);

        // 2. Setup updater with backup service
        $backupService = new PreUpdateBackupService($this->tempBackupDir, $this->db, '1.0.0');
        $verifier = new PackageSignatureVerifier($this->keyPair['public']);
        $checker = new ModuleCompatibilityChecker($this->db);

        $updater = new StagedUpdateService($verifier, $checker, '1.0.0', $this->db, $backupService);

        // Apply with default requireBackup = true
        $applyResult = $updater->applyValidatedUpdate($this->tempStagedDir, $this->tempTargetAppDir, requireBackup: true);

        $this->assertTrue($applyResult['success']);
        $this->assertNotNull($applyResult['backup_id']);
        $this->assertFileExists($this->tempBackupDir . '/' . $applyResult['backup_id'] . '.zip');
        $this->assertFileExists($this->tempBackupDir . '/' . $applyResult['backup_id'] . '.manifest.json');
    }

    public function testStagedUpdateAbortsIfBackupServiceNotConfigured(): void
    {
        $relPath = 'core.php';
        $fullPath = $this->tempStagedDir . '/files/' . $relPath;
        file_put_contents($fullPath, '<?php echo "core v1.1.0";');
        $sha = hash_file('sha256', $fullPath);

        $manifest = new UpdatePackageManifest(
            version: '1.1.0',
            minCurrentVersion: '1.0.0',
            releaseNotes: 'Backup missing test',
            fileChecksums: [$relPath => (string) $sha]
        );

        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->tempStagedDir . '/manifest.json', $json);
        $sig = PackageSignatureVerifier::signPayload((string) $json, $this->keyPair['private']);
        file_put_contents($this->tempStagedDir . '/manifest.sig', $sig);

        $verifier = new PackageSignatureVerifier($this->keyPair['public']);
        $checker = new ModuleCompatibilityChecker($this->db);

        // No backup service injected
        $updater = new StagedUpdateService($verifier, $checker, '1.0.0', $this->db, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mandatory pre-update backup failed');

        $updater->applyValidatedUpdate($this->tempStagedDir, $this->tempTargetAppDir, requireBackup: true);
    }
}
