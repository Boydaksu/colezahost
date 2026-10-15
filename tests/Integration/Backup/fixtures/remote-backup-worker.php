<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Coleza\Domain\Backup\Storage\{S3BackupStorageAdapter, SftpBackupStorageAdapter};

$job = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$adapter = $job['type'] === 's3' ? new S3BackupStorageAdapter($job['config']) : new SftpBackupStorageAdapter($job['config']);
try {
    $adapter->store('concurrent', $job['archive'], $job['manifest']);
    echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
} catch (RuntimeException) {
    echo json_encode(['success' => false], JSON_THROW_ON_ERROR);
}
