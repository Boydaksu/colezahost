<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

use Coleza\Domain\Backup\Storage\BackupStorageAdapterInterface;
use Coleza\Foundation\Database\Connection;
use RuntimeException;
use ZipArchive;

/**
 * Master Enterprise Backup Management Service.
 * Supports:
 * - Scopes: Full (DB + Files), DB-only, Files-only.
 * - Destinations: Local disk, SFTP remote, AWS S3.
 * - Optional authenticated AES-256-GCM encryption.
 * - Cryptographic manifest with SHA-256 integrity seal.
 * - Policy-driven automated retention and pruning.
 */
final class EnterpriseBackupService
{
    public function __construct(
        private string $workingDirectory,
        private ?Connection $db = null,
        private string $appVersion = '1.0.0',
        private ?BackupEncryptionService $encryptionService = null
    ) {
        if (!is_dir($workingDirectory)) {
            if(!mkdir($workingDirectory, 0700, true)){throw new RuntimeException('Cannot create private backup working directory.');}
        }
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Creates an enterprise backup according to specified scope and options.
     *
     * @param BackupScope $scope
     * @param BackupStorageAdapterInterface $destination
     * @param array<int|string, string> $filePaths Files/directories; string keys supply distinct relative aliases
     * @param bool $encrypt Whether to apply AES-256-GCM encryption
     * @param ?BackupRetentionPolicy $retentionPolicy Optional retention policy to apply immediately
     * @return array{manifest: BackupManifest, destination_type: string, encrypted: bool}
     */
    public function createBackup(
        BackupScope $scope,
        BackupStorageAdapterInterface $destination,
        array $filePaths = [],
        bool $encrypt = false,
        ?BackupRetentionPolicy $retentionPolicy = null
    ): array {
        if(($scope===BackupScope::FULL||$scope===BackupScope::DATABASE)&&$this->db===null){throw new RuntimeException('A database connection is required for this backup scope.');}
        if($encrypt&&$this->encryptionService===null){throw new RuntimeException('Backup encryption is not configured.');}
        if($scope===BackupScope::FILES&&$filePaths===[]){throw new RuntimeException('Files-only backup requires explicit source files.');}
        $timestamp = date('Ymd_His');
        $backupId = sprintf('backup_%s_%s_%s', $scope->value, $timestamp, bin2hex(random_bytes(4)));
        $tempArchive = rtrim($this->workingDirectory, '/\\') . '/' . $backupId . '.zip';
        $tempManifest = rtrim($this->workingDirectory, '/\\') . '/' . $backupId . '.manifest.json';

        $payloadStage = rtrim($this->workingDirectory, '/\\') . '/payload_' . bin2hex(random_bytes(12));
        if (!mkdir($payloadStage, 0700)) { throw new RuntimeException('Cannot create private backup snapshot directory.'); }
        try {
        $database = null;
        if ($scope === BackupScope::FULL || $scope === BackupScope::DATABASE) {
            $database = (new LogicalDatabaseDump())->export($this->db, $payloadStage . '/database.sql');
        }
        $files = $scope === BackupScope::DATABASE ? [] : (new BackupFileTree())->snapshot($filePaths, $payloadStage, [$this->workingDirectory]);
        $total=$database===null?0:(int)filesize($payloadStage.'/database.sql');foreach($files as$source){if($source!==null){$total+=(int)filesize($source);}}
        if($total>10*1024*1024*1024){throw new RuntimeException('Backup payload exceeds the 10 GiB uncompressed limit.');}
        $zip = new ZipArchive();
        if ($zip->open($tempArchive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Cannot create backup archive.'); }
        $containedFiles = [];
        try {
            if ($database !== null) {
                if (!$zip->addFile($payloadStage . '/database.sql', 'database.sql')) { throw new RuntimeException('Cannot add database snapshot.'); }
                $containedFiles['database.sql'] = (string) hash_file('sha256', $payloadStage . '/database.sql');
            }
            foreach ($files as $relative => $source) {
                $name = 'files/' . $relative;
                if ($source === null) { if (!$zip->addEmptyDir(rtrim($name, '/'))) { throw new RuntimeException('Cannot preserve backup directory.'); } $containedFiles[$name] = hash('sha256', ''); }
                else { if (!$zip->addFile($source, $name)) { throw new RuntimeException('Cannot add backup snapshot file.'); } $containedFiles[$name] = (string) hash_file('sha256', $source); }
            }
        } finally { if (!$zip->close()) { throw new RuntimeException('Cannot finalize backup archive.'); } }

        // 3. Optional AES-256-GCM Encryption
        $isEncrypted = false;
        if ($encrypt) {
            if ($this->encryptionService === null) {
                throw new RuntimeException('Encryption requested but BackupEncryptionService is not configured.');
            }

            $encryptedArchive = $tempArchive . '.enc';
            $this->encryptionService->encryptFile($tempArchive, $encryptedArchive);
            unlink($tempArchive);
            rename($encryptedArchive, $tempArchive);
            $isEncrypted = true;
        }

        // 4. Compute Checksum and Manifest
        $fileSize = (int) filesize($tempArchive);
        $checksum = (string) hash_file('sha256', $tempArchive);

        $manifest = new BackupManifest(
            backupId: $backupId,
            type: $scope->value,
            sourceAppVersion: $this->appVersion,
            fileSizeBytes: $fileSize,
            sha256Checksum: $checksum,
            createdAt: date('c'),
            containedFiles: $containedFiles,
            metadata: [
                'encrypted' => $isEncrypted,
                'encryption_algorithm' => $isEncrypted ? 'aes-256-gcm' : 'none',
                'destination' => $destination->getType()->value,
                'database' => $database,
                'file_layout' => 2,
            ]
        );

        $manifestJson=json_encode($manifest->toArray(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tempManifest,$manifestJson,LOCK_EX)!==strlen($manifestJson)){throw new RuntimeException('Cannot write backup manifest.');}
        \Coleza\Domain\Backup\Storage\BackupPackage::verify($backupId,$tempArchive,$tempManifest);
        if($destination->store($backupId,$tempArchive,$tempManifest)!==true){throw new RuntimeException('Backup destination did not accept the package; local files retained.');}
        $readback=rtrim($this->workingDirectory,'/\\').'/verify_'.bin2hex(random_bytes(12));
        if(!mkdir($readback,0700)){throw new RuntimeException('Cannot create independent backup read-back directory.');}
        try{
            if(!$destination->exists($backupId)||$destination->retrieve($backupId,$readback)!==true){throw new RuntimeException('Backup destination read-back failed; local files retained.');}
            $returnedArchive=$readback.'/'.$backupId.'.zip';$returnedManifest=$readback.'/'.$backupId.'.manifest.json';
            \Coleza\Domain\Backup\Storage\BackupPackage::verify($backupId,$returnedArchive,$returnedManifest);
            if(!hash_equals($checksum,(string)hash_file('sha256',$returnedArchive))||!hash_equals(hash('sha256',$manifestJson),(string)hash_file('sha256',$returnedManifest))){throw new RuntimeException('Destination read-back differs from the source package; local files retained.');}
        }finally{
            foreach([$readback.'/'.$backupId.'.zip',$readback.'/'.$backupId.'.manifest.json',$readback.'/.publish.lock']as$file){if(is_file($file)&&!is_link($file)){unlink($file);}}
            if(count(scandir($readback))===2){rmdir($readback);}
        }
        // 6. Apply Retention Policy if provided
        if ($retentionPolicy !== null) {
            $manifests = $this->verifiedHistory($destination);
            $manifests[$backupId] = $manifest;
            $retentionPolicy->apply($destination, $manifests, [$backupId]);
        }
        if($destination->getType()!==BackupDestinationType::LOCAL){
            if(!unlink($tempArchive)||!unlink($tempManifest)){throw new RuntimeException('Verified backup delivered, but local cleanup did not finish.');}
        }

        return [
            'manifest' => $manifest,
            'destination_type' => $destination->getType()->value,
            'encrypted' => $isEncrypted,
            'verified' => true,
        ];
        } finally { BackupFileTree::removeOwned($payloadStage); }
    }

    /** Metadata is admitted to retention only after a complete independent package read-back. */
    private function verifiedHistory(BackupStorageAdapterInterface $destination): array
    {
        $manifests = [];
        foreach ($destination->listBackups() as $id) {
            \Coleza\Domain\Backup\Storage\BackupPackage::id($id);
            $directory = rtrim($this->workingDirectory, '/\\') . '/history_' . bin2hex(random_bytes(12));
            if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create private backup history staging.'); }
            try {
                if (!$destination->retrieve($id, $directory)) { throw new RuntimeException('Listed backup could not be verified; retention stopped.'); }
                $data = \Coleza\Domain\Backup\Storage\BackupPackage::verify($id, $directory . '/' . $id . '.zip', $directory . '/' . $id . '.manifest.json');
                // Missing dates remain unknown and are protected by the retention policy.
                if (is_string($data['created_at'] ?? null) && strtotime($data['created_at']) !== false) { $manifests[$id] = BackupManifest::fromArray($data); }
            } finally {
                foreach ([$id . '.zip', $id . '.manifest.json', '.publish.lock'] as $name) {
                    $path = $directory . '/' . $name;
                    if (is_file($path) && !is_link($path)) { unlink($path); }
                }
                if (count(scandir($directory)) === 2) { rmdir($directory); }
            }
        }
        return $manifests;
    }

}
