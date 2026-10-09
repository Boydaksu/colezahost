<?php

declare(strict_types=1);

namespace Coleza\Domain\Updater;

use Coleza\Domain\Backup\RestoreResult;
use Coleza\Domain\Backup\RestoreWizardService;
use Coleza\Domain\Backup\Storage\BackupStorageAdapterInterface;
use Coleza\Foundation\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Service managing automated and manual rollback paths:
 * - Triggered automatically if an update fails during deployment or database migration.
 * - Restores the mandatory verified pre-update backup archive.
 * - Re-applies Privacy Tombstones to guarantee zero PII resurrection after restore.
 * - Returns system to a known good state.
 */
final class RollbackManager
{
    public function __construct(
        private RestoreWizardService $restoreWizard,
        private BackupStorageAdapterInterface $backupStorage
    ) {
    }

    /**
     * Executes emergency rollback to the specified pre-update backup archive.
     *
     * @param string $preUpdateBackupId
     * @param Connection $targetDb
     * @param string $targetAppDir
     * @param ?string $decryptionPassphrase
     * @return RestoreResult
     */
    public function executeRollback(
        string $preUpdateBackupId,
        Connection $targetDb,
        string $targetAppDir,
        ?string $decryptionPassphrase = null
    ): RestoreResult {
        if (!$this->backupStorage->exists($preUpdateBackupId)) {
            throw new RuntimeException("Rollback aborted: Pre-update backup [{$preUpdateBackupId}] does not exist in storage.");
        }

        return $this->restoreWizard->executeRestore(
            backupId: $preUpdateBackupId,
            sourceStorage: $this->backupStorage,
            targetDb: $targetDb,
            targetAppDir: $targetAppDir,
            decryptionPassphrase: $decryptionPassphrase
        );
    }
}
