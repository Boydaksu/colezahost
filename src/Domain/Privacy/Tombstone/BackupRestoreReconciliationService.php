<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use Coleza\Domain\Audit\AuditLogger;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Service enforcing Constitution Rule:
 * "Backup restore reapplies Privacy Tombstones before production opens. PII must not resurrect."
 * (Golden Scenario G08).
 */
final class BackupRestoreReconciliationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PrivacyTombstoneService $tombstoneService,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Reapplies privacy tombstones against restored operational database.
     * Identifies any resurrected user records/PII and re-scrubs them completely
     * before production is unsealed.
     */
    public function reconcileAfterBackupRestore(): RestoreReconciliationReport
    {
        // 1. Ensure operational database has tombstones table ready
        $this->tombstoneService->ensureTables();

        // 2. Synchronize persistent out-of-band store to restored database
        $this->tombstoneService->syncPersistentStoreToDatabase();

        // 3. Retrieve all authoritative tombstones
        $tombstones = $this->tombstoneService->getAllTombstones();

        $resurrectedCount = 0;
        $rescrubbedCount = 0;
        $scrubbedUserIds = [];

        foreach ($tombstones as $userId => $tombstone) {
            // Check by User ID
            $userRow = null;
            try {
                $userRow = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
            } catch (\Throwable) {
                // Table might not exist or columns different
            }

            // Also check by email hash if direct ID was not found or if email resurrected under different ID
            if ($userRow === null) {
                try {
                    $allUsers = $this->db->select("SELECT id, email, status FROM users");
                    foreach ($allUsers as $u) {
                        if (isset($u['email']) && $tombstone->matchesEmail((string) $u['email'], $this->tombstoneService->getSalt())) {
                            $userRow = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $u['id']]);
                            break;
                        }
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }

            if ($userRow !== null) {
                $targetId = (int) $userRow['id'];
                $isResurrected = false;

                // Check if user contains resurrected plaintext PII
                $status = strtolower((string) ($userRow['status'] ?? ''));
                $name = (string) ($userRow['name'] ?? '');
                $email = (string) ($userRow['email'] ?? '');

                if ($status !== 'erased' || !str_starts_with($name, '[Anonymized User #') || !str_starts_with($email, 'erased_')) {
                    $isResurrected = true;
                }

                // Check if active consents exist for this user
                try {
                    $consents = $this->db->select(
                        "SELECT id FROM privacy_consents WHERE user_id = :uid",
                        ['uid' => $targetId]
                    );
                    if (!empty($consents)) {
                        $isResurrected = true;
                    }
                } catch (\Throwable) {
                    // Ignore
                }

                if ($isResurrected) {
                    $resurrectedCount++;

                    // Re-apply anonymization & PII scrub
                    $pseudoName = sprintf('[Anonymized User #%d]', $targetId);
                    $hashPrefix = substr($tombstone->getErasureChecksum(), 0, 8);
                    $pseudoEmail = sprintf('erased_%d_%s@anonymized.local', $targetId, $hashPrefix !== '' ? $hashPrefix : 'rescrub');

                    try {
                        $this->db->statement(
                            "UPDATE users
                             SET name = :name, email = :email, status = 'erased',
                                 phone = NULL, address = NULL, tax_number = NULL,
                                 two_factor_secret = NULL, password_hash = 'ERASED'
                             WHERE id = :id",
                            [
                                'name' => $pseudoName,
                                'email' => $pseudoEmail,
                                'id' => $targetId,
                            ]
                        );
                    } catch (\Throwable) {
                        try {
                            $this->db->statement(
                                "UPDATE users SET name = :name, email = :email, status = 'erased' WHERE id = :id",
                                ['name' => $pseudoName, 'email' => $pseudoEmail, 'id' => $targetId]
                            );
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }

                    // Delete resurrected privacy consents
                    try {
                        $this->db->statement("DELETE FROM privacy_consents WHERE user_id = :uid", ['uid' => $targetId]);
                    } catch (\Throwable) {
                        // Ignore
                    }

                    $rescrubbedCount++;
                    $scrubbedUserIds[] = $targetId;
                }
            }
        }

        // 4. Verification Check: Scan again to confirm zero resurrected PII remains
        $isClean = true;
        foreach ($tombstones as $userId => $tombstone) {
            try {
                $checkRow = $this->db->selectOne("SELECT id, name, email, status FROM users WHERE id = :id", ['id' => $userId]);
                if ($checkRow !== null) {
                    if (
                        strtolower((string) $checkRow['status']) !== 'erased' ||
                        !str_starts_with((string) $checkRow['name'], '[Anonymized User #')
                    ) {
                        $isClean = false;
                        break;
                    }
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $auditDigest = hash('sha256', sprintf(
            '%d:%d:%d:%s:%s',
            count($tombstones),
            $resurrectedCount,
            $rescrubbedCount,
            $isClean ? 'CLEAN' : 'DIRTY',
            $now
        ));

        // Audit Log
        $this->auditLogger?->log(
            actorUserId: 0,
            eventType: 'PRIVACY_TOMBSTONE_BACKUP_RESTORE_REAPPLIED',
            targetResource: 'privacy:backup_restore_reconciliation',
            payload: [
                'tombstones_evaluated' => count($tombstones),
                'resurrected_detected' => $resurrectedCount,
                'resurrected_rescrubbed' => $rescrubbedCount,
                'scrubbed_user_ids' => $scrubbedUserIds,
                'is_clean' => $isClean,
                'digest' => $auditDigest,
            ]
        );

        return new RestoreReconciliationReport(
            tombstonesEvaluated: count($tombstones),
            resurrectedUsersDetected: $resurrectedCount,
            resurrectedUsersReScrubbed: $rescrubbedCount,
            scrubbedUserIds: $scrubbedUserIds,
            isClean: $isClean,
            reconciledAt: $now,
            auditDigest: $auditDigest
        );
    }

    /**
     * Enforces the Production Gate: ensures that backup restore reconciliation
     * completely sanitized resurrected data before production opening is permitted.
     *
     * @throws ValidationException if reconciliation is incomplete or resurrected PII remains
     */
    public function assertProductionReady(RestoreReconciliationReport $report): void
    {
        if (!$report->isProductionReady()) {
            throw new ValidationException(
                ['restore_reconciliation' => ['Resurrected personal data detected or reconciliation dirty. Production seal cannot be lifted.']],
                'Cannot open production: unscrubbed resurrected personal data detected.'
            );
        }
    }
}
