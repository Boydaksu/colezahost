<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

use Coleza\Domain\Backup\Storage\BackupStorageAdapterInterface;
use DateTimeImmutable;

/**
 * Enforces backup retention policies (e.g. keep max N backups or keep for M days).
 */
final class BackupRetentionPolicy
{
    /**
     * @param int $maxBackupsToKeep Maximum number of backups to retain (0 = unlimited)
     * @param int $retentionDays Maximum age of backups in days (0 = unlimited)
     */
    public function __construct(
        private int $maxBackupsToKeep = 10,
        private int $retentionDays = 30
    ) {
    }

    public function getMaxBackupsToKeep(): int
    {
        return $this->maxBackupsToKeep;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Applies retention policy on a backup storage adapter by pruning old backups.
     *
     * @param BackupStorageAdapterInterface $storage
     * @param array<string, BackupManifest> $manifests Map of backupId => BackupManifest
     * @return array{retained: array<int, string>, pruned: array<int, string>}
     */
    public function apply(BackupStorageAdapterInterface $storage, array $manifests): array
    {
        $backupIds = $storage->listBackups();
        if (empty($backupIds)) {
            return ['retained' => [], 'pruned' => []];
        }

        // Sort backups newest to oldest
        usort($backupIds, function (string $a, string $b) use ($manifests) {
            $timeA = isset($manifests[$a]) ? strtotime($manifests[$a]->getCreatedAt()) : 0;
            $timeB = isset($manifests[$b]) ? strtotime($manifests[$b]->getCreatedAt()) : 0;
            return $timeB <=> $timeA;
        });

        $now = time();
        $retained = [];
        $pruned = [];

        foreach ($backupIds as $index => $id) {
            $manifest = $manifests[$id] ?? null;
            $shouldPrune = false;

            // 1. Check max count threshold
            if ($this->maxBackupsToKeep > 0 && ($index + 1) > $this->maxBackupsToKeep) {
                $shouldPrune = true;
            }

            // 2. Check retention days threshold
            if ($this->retentionDays > 0 && $manifest !== null) {
                $createdTimestamp = strtotime($manifest->getCreatedAt());
                $ageDays = ($now - $createdTimestamp) / 86400;
                if ($ageDays > $this->retentionDays) {
                    $shouldPrune = true;
                }
            }

            if ($shouldPrune) {
                $storage->delete($id);
                $pruned[] = $id;
            } else {
                $retained[] = $id;
            }
        }

        return [
            'retained' => $retained,
            'pruned' => $pruned,
        ];
    }
}
