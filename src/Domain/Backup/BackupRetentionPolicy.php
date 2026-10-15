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
        if($maxBackupsToKeep<0||$retentionDays<0){throw new \InvalidArgumentException('Retention limits cannot be negative.');}
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

        // Never classify unknown, malformed or unbound manifest metadata as an old backup.
        $known=[];$retained=[];$pruned=[];
        foreach($backupIds as$id){
            $manifest=$manifests[$id]??null;
            if(!$manifest instanceof BackupManifest||$manifest->getBackupId()!==$id||$manifest->getFileSizeBytes()<=0||!preg_match('/^[a-f0-9]{64}$/D',$manifest->getSha256Checksum())||strtotime($manifest->getCreatedAt())===false){$retained[]=$id;continue;}
            $known[]=$id;
        }
        usort($known,static fn(string $a,string $b):int=>strtotime($manifests[$b]->getCreatedAt())<=>strtotime($manifests[$a]->getCreatedAt()));
        foreach($known as$index=>$id){
            $tooMany=$this->maxBackupsToKeep>0&&$index>=$this->maxBackupsToKeep;
            $tooOld=$this->retentionDays>0&&(time()-strtotime($manifests[$id]->getCreatedAt()))/86400>$this->retentionDays;
            // Always keep the newest known complete copy, even when its age exceeds policy.
            if($index>0&&($tooMany||$tooOld)){
                if(!$storage->delete($id)){throw new \RuntimeException('Backup retention deletion failed; pruning stopped.');}$pruned[]=$id;
            }else{$retained[]=$id;}
        }
        return ['retained'=>$retained,'pruned'=>$pruned];
    }
}
