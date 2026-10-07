<?php

declare(strict_types=1);

namespace Coleza\Foundation\Backup;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Storage\StorageInterface;

final class BackupSkeleton
{
    public function __construct(
        private ?Connection $db = null,
        private ?StorageInterface $backupStorage = null
    ) {
    }

    /**
     * Create a backup metadata record structure.
     *
     * @param array<int, string> $includedTables
     * @return array<string, mixed>
     */
    public function createManifest(string $backupType, array $includedTables = []): array
    {
        return [
            'backup_id' => 'bak_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)),
            'type' => $backupType,
            'tables' => $includedTables,
            'created_at' => date('c'),
            'status' => 'PENDING',
        ];
    }

    /**
     * Store backup archive or metadata to backup storage.
     */
    public function storeManifest(array $manifest): string
    {
        if ($this->backupStorage === null) {
            return '';
        }

        $filename = sprintf('manifests/%s.json', $manifest['backup_id']);
        $this->backupStorage->put($filename, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $filename;
    }
}
