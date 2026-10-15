<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use Coleza\Domain\Identity\Audit\AuditLogger;
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

    public function forDatabase(Connection $db): self
    {
        return new self($db, $this->tombstoneService->forDatabase($db), $this->auditLogger === null ? null : new AuditLogger($db));
    }
    public function verifier(Connection $db): \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier { return $this->tombstoneService->verifier($db); }

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

        $resurrectedCount = 0; $rescrubbedCount = 0; $scrubbedUserIds = [];
        $columns = $this->columns('users');
        foreach (['id','name','email','status'] as $required) { if (!in_array($required,$columns,true)) { throw new \RuntimeException('Restored users schema cannot be verified for privacy reconciliation.'); } }
        $targets = [];
        foreach ($this->db->select('SELECT * FROM users ORDER BY id') as $user) {
            foreach ($tombstones as $tombstone) {
                if ((int)$user['id'] === $tombstone->getUserId() || $this->tombstoneService->matchesTombstoneEmail($tombstone,(string)$user['email'])) { $targets[(int)$user['id']]=$tombstone; break; }
            }
        }
        foreach ($targets as $targetId => $tombstone) {
            $this->db->transaction(function () use ($targetId,$tombstone,$columns,&$resurrectedCount,&$rescrubbedCount,&$scrubbedUserIds): void {
                $values=['name'=>'[Anonymized User #'.$targetId.']','email'=>'erased_'.$targetId.'_'.substr($tombstone->getErasureChecksum(),0,8).'@anonymized.local','status'=>'erased',
                    'phone'=>null,'address'=>null,'tax_number'=>null,'two_factor_secret'=>null,'password_hash'=>'ERASED','first_name'=>null,'last_name'=>null,'is_active'=>0,'email_verified_at'=>null];
                $values=array_intersect_key($values,array_flip($columns));
                $user=$this->db->selectOne('SELECT * FROM users WHERE id=?',[$targetId]);$changed=false;
                foreach($values as$key=>$value){if($user[$key]!==$value){$changed=true;}}
                $this->db->update('users',$values,'id=:id',['id'=>$targetId]);
                foreach(['privacy_consents','sessions','user_two_factor','user_recovery_codes','user_trusted_devices','two_factor_login_steps','api_keys']as$table){
                    if(!\Coleza\Domain\Vault\KeyBinding::tableExists($this->db,$table)){continue;}
                    $childColumns=$this->columns($table);if(!in_array('user_id',$childColumns,true)){throw new \RuntimeException('Privacy recovery cannot identify restored authentication/consent owner.');}
                    if($this->db->affectingStatement('DELETE FROM `'.$table.'` WHERE user_id=?',[$targetId])>0){$changed=true;}
                }
                $redactions=[
                    'payment_methods'=>['token'=>'ERASED','card_last4'=>'0000','card_brand'=>'ERASED','billing_name'=>'ERASED','is_active'=>0],
                    'billing_profiles'=>['company_name'=>'[Anonymized]','tax_number'=>null,'tax_office'=>null,'address'=>'[Anonymized]','city'=>null,'state'=>null,'postal_code'=>null,'phone'=>null],
                    'hosting_services'=>['username'=>'erased_user','password_encrypted'=>'ERASED','contact_email'=>'erased@anonymized.local'],
                    'support_tickets'=>['subject'=>'[Redacted]','customer_email'=>'erased@anonymized.local','status'=>'closed','metadata_json'=>'{}'],
                    'support_messages'=>['message'=>'[Personal content redacted under right to erasure]','ip_address'=>'127.0.0.1'],
                    'support_ticket_messages'=>['message'=>'[Personal content redacted under right to erasure]','metadata_json'=>'{}'],
                ];
                foreach($redactions as$table=>$redacted){
                    if(!\Coleza\Domain\Vault\KeyBinding::tableExists($this->db,$table)){continue;}$childColumns=$this->columns($table);
                    if(!in_array('user_id',$childColumns,true)){throw new \RuntimeException('Privacy recovery requires an explicit owner mapping for restored related tables.');}
                    $redacted=array_intersect_key($redacted,array_flip($childColumns));if($redacted===[]){throw new \RuntimeException('Privacy recovery has no verified redaction mapping for a restored related table.');}
                    foreach($this->db->select('SELECT * FROM `'.$table.'` WHERE user_id=?',[$targetId])as$row){foreach($redacted as$key=>$value){if((string)$row[$key] !== (string)$value || ($row[$key]===null)!==($value===null)){$changed=true;}}}
                    $this->db->update('`'.$table.'`',$redacted,'user_id=:uid',['uid'=>$targetId]);
                    foreach($this->db->select('SELECT * FROM `'.$table.'` WHERE user_id=?',[$targetId])as$row){foreach($redacted as$key=>$value){if((string)$row[$key] !== (string)$value || ($row[$key]===null)!==($value===null)){throw new \RuntimeException('Privacy recovery related-field verification failed.');}}}
                }
                $verified=$this->db->selectOne('SELECT * FROM users WHERE id=?',[$targetId]);
                foreach($values as$key=>$value){if($verified[$key]!==$value){throw new \RuntimeException('Privacy recovery user verification failed.');}}
                if($changed){$resurrectedCount++;$rescrubbedCount++;$scrubbedUserIds[]=$targetId;}
            });
        }
        $isClean=true;

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

    private function columns(string $table): array
    {
        if($this->db->getDriverName()==='sqlite'){return array_column($this->db->select('PRAGMA table_info(`'.$table.'`)'),'name');}
        return array_column($this->db->select('SHOW COLUMNS FROM `'.$table.'`'),'Field');
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
