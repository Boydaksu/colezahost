<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\BackupDestinationType;
use Coleza\Domain\Backup\BackupEncryptionService;
use Coleza\Domain\Backup\BackupManifest;
use Coleza\Domain\Backup\BackupRetentionPolicy;
use Coleza\Domain\Backup\BackupScope;
use Coleza\Domain\Backup\EnterpriseBackupService;
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Backup\Storage\S3BackupStorageAdapter;
use Coleza\Domain\Backup\Storage\SftpBackupStorageAdapter;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class EnterpriseBackupLifecycleTest extends TestCase
{
    private string $tempWorkDir;
    private string $tempStorageDir;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempWorkDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ent_backup_work_' . uniqid();
        $this->tempStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ent_backup_store_' . uniqid();

        mkdir($this->tempWorkDir, 0777, true);
        mkdir($this->tempStorageDir, 0777, true);

        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->db->statement("CREATE TABLE hosting_plans (id INTEGER PRIMARY KEY, name TEXT, price REAL)");
        $this->db->statement("INSERT INTO hosting_plans (name, price) VALUES ('Pro Web', 19.99)");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempWorkDir);
        $this->removeDirectory($this->tempStorageDir);

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

    public function testEncryptionAndDecryptionService(): void
    {
        $encryptor = new BackupEncryptionService('super-secret-backup-passphrase-2026!');
        $originalData = 'This is sensitive database SQL dump content with credentials and cards.';

        $cipher = $encryptor->encrypt($originalData);
        $this->assertNotSame($originalData, $cipher);

        $decrypted = $encryptor->decrypt($cipher);
        $this->assertSame($originalData, $decrypted);
    }

    public function testFullBackupWithLocalDestinationAndEncryption(): void
    {
        $encryptor = new BackupEncryptionService('my-passphrase');
        $service = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->db,
            appVersion: '1.0.0',
            encryptionService: $encryptor
        );

        $localAdapter = new LocalBackupStorageAdapter($this->tempStorageDir);

        $sampleFile = $this->tempWorkDir . '/license.key';
        file_put_contents($sampleFile, 'LICENSE-KEY-123456');

        $result = $service->createBackup(
            scope: BackupScope::FULL,
            destination: $localAdapter,
            filePaths: [$sampleFile],
            encrypt: true
        );

        $this->assertInstanceOf(BackupManifest::class, $result['manifest']);
        $this->assertTrue($result['encrypted']);
        $this->assertSame('local', $result['destination_type']);

        $backupId = $result['manifest']->getBackupId();
        $this->assertTrue($localAdapter->exists($backupId));
        $this->assertFileExists($this->tempStorageDir . '/' . $backupId . '.zip');
        $this->assertFileExists($this->tempStorageDir . '/' . $backupId . '.manifest.json');
    }

    public function testDatabaseOnlyBackupToSftpRemoteAdapter(): void
    {
        $service = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->db,
            appVersion: '1.0.0'
        );

        $sftpAdapter = new SftpBackupStorageAdapter(['host' => 'backup.remote.net'], simulated: true);

        $result = $service->createBackup(
            scope: BackupScope::DATABASE,
            destination: $sftpAdapter,
            encrypt: false
        );

        $backupId = $result['manifest']->getBackupId();
        $this->assertSame('sftp', $result['destination_type']);
        $this->assertTrue($sftpAdapter->exists($backupId));
        $this->assertContains($backupId, $sftpAdapter->listBackups());
    }

    public function testFilesOnlyBackupToS3Adapter(): void
    {
        $service = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir,
            db: $this->db,
            appVersion: '1.0.0'
        );

        $s3Adapter = new S3BackupStorageAdapter(['bucket' => 'coleza-backups-bucket'], simulated: true);

        $dummyUpload = $this->tempWorkDir . '/avatar.png';
        file_put_contents($dummyUpload, 'binary_image_data');

        $result = $service->createBackup(
            scope: BackupScope::FILES,
            destination: $s3Adapter,
            filePaths: [$dummyUpload],
            encrypt: false
        );

        $backupId = $result['manifest']->getBackupId();
        $this->assertSame('s3', $result['destination_type']);
        $this->assertTrue($s3Adapter->exists($backupId));
        $this->assertContains($backupId, $s3Adapter->listBackups());
    }

    public function testRetentionPolicyPrunesOldestBackups(): void
    {
        $adapter = new S3BackupStorageAdapter([], simulated: true);

        // Seed simulated backups
        $manifests = [];
        for ($i = 1; $i <= 5; $i++) {
            $id = 'backup_' . $i;
            $mPath = $this->tempWorkDir . '/' . $id . '.manifest.json';
            $zPath = $this->tempWorkDir . '/' . $id . '.zip';
            file_put_contents($mPath, '{}');
            file_put_contents($zPath, 'dummy');

            $adapter->store($id, $zPath, $mPath);

            $manifests[$id] = new BackupManifest(
                backupId: $id,
                type: 'full',
                sourceAppVersion: '1.0.0',
                fileSizeBytes: 100,
                sha256Checksum: 'dummy',
                createdAt: date('c', strtotime("-{$i} days"))
            );
        }

        $this->assertCount(5, $adapter->listBackups());

        // Policy: Keep only 3 latest backups
        $policy = new BackupRetentionPolicy(maxBackupsToKeep: 3, retentionDays: 30);
        $retentionResult = $policy->apply($adapter, $manifests);

        $this->assertCount(3, $retentionResult['retained']);
        $this->assertCount(2, $retentionResult['pruned']);
        $this->assertCount(3, $adapter->listBackups());
    }
}
