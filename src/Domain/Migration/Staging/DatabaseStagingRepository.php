<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

use Coleza\Foundation\Database\Connection;

final class DatabaseStagingRepository implements StagingRepositoryInterface
{
    private string $table = 'migration_staging_records';

    public function __construct(
        private Connection $db,
        private ?\Coleza\Domain\Vault\Encryptor $encryptor=null
    ) {
    }

    public function ensureTable(): void
    {
        if (\Coleza\Foundation\Database\PdoSchema::useInstalledSchemaInTransaction($this->db, ['migration_staging_records' => ['id', 'batch_id', 'source_system', 'source_entity_type', 'source_entity_id', 'raw_payload_json', 'status', 'canonical_entity_type', 'canonical_payload_json', 'validation_errors_json', 'quarantine_reason', 'target_entity_id', 'processed_at', 'created_at']])) { return; }
        \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                batch_id VARCHAR(64) NOT NULL,
                source_system VARCHAR(32) NOT NULL,
                source_entity_type VARCHAR(64) NOT NULL,
                source_entity_id VARCHAR(128) NOT NULL,
                raw_payload_json TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "staged",
                canonical_entity_type VARCHAR(64) NULL,
                canonical_payload_json TEXT NULL,
                validation_errors_json TEXT NULL,
                quarantine_reason TEXT NULL,
                target_entity_id INT NULL,
                processed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (batch_id, source_entity_type, source_entity_id)
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    private ?StagingSecretStore $secretStore=null;
    private function secrets(): StagingSecretStore { return $this->secretStore??=new StagingSecretStore($this->db,$this->encryptor); }
    public function prepareStorage(): void { $this->secrets()->resolveKey();$this->ensureTable();$this->secrets()->prepare(); }
    private function validateIdentity(StagingRecord $record): void
    {
        foreach ([[$record->getBatchId(),64],[$record->getSourceSystem(),32],[$record->getSourceEntityType(),64],[$record->getSourceEntityId(),128]] as [$value,$limit]) {
            if ((string)$value==='' || strlen((string)$value)>$limit) { throw new \InvalidArgumentException('Invalid staging source identity.'); }
        }
        $type=$record->getCanonicalEntityType();if ($type!==null && ($type==='' || strlen($type)>64)) { throw new \InvalidArgumentException('Invalid canonical entity type.'); }
    }
    private function sourceBindings(StagingRecord $record): array { return [$record->getBatchId(),$record->getSourceEntityType(),$record->getSourceEntityId()]; }
    private function assertSameSource(array $row,StagingRecord $record): void
    {
        foreach (['batch_id'=>$record->getBatchId(),'source_system'=>$record->getSourceSystem(),'source_entity_type'=>$record->getSourceEntityType(),'source_entity_id'=>$record->getSourceEntityId()] as $key=>$value) {
            if ($row[$key]!==$value) { throw new \RuntimeException('Staging source ownership is immutable.'); }
        }
    }
    public function save(StagingRecord $record): void
    {
        $this->validateIdentity($record);
        $raw=StagingSecretStore::encode($record->getRawPayload());$canonical=$record->getCanonicalPayload()===null?null:StagingSecretStore::encode($record->getCanonicalPayload());
        $this->prepareStorage();
        $id=$this->db->transaction(function() use($record,$raw,$canonical): int {
            $this->secrets()->guard();$marker='staging:pending:'.bin2hex(random_bytes(24));$created=false;
            if ($record->getId()===null) {
                $conflict=$this->db->getDriverName()==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(batch_id,source_entity_type,source_entity_id) DO UPDATE SET id=id';
                $this->db->statement('INSERT INTO migration_staging_records (batch_id,source_system,source_entity_type,source_entity_id,raw_payload_json,status,canonical_entity_type,created_at) VALUES (?,?,?,?,?,?,?,?)'.$conflict,
                    [$record->getBatchId(),$record->getSourceSystem(),$record->getSourceEntityType(),$record->getSourceEntityId(),$marker,$record->getStatus()->value,$record->getCanonicalEntityType(),$record->getCreatedAt()??date('Y-m-d H:i:s')]);
                $row=$this->db->selectOne('SELECT * FROM migration_staging_records WHERE batch_id=? AND source_entity_type=? AND source_entity_id=?'.$this->db->forUpdate(),$this->sourceBindings($record));
                $created=$row!==null && hash_equals($marker,$row['raw_payload_json']);
            } else { $row=$this->db->selectOne('SELECT * FROM migration_staging_records WHERE id=?'.$this->db->forUpdate(),[$record->getId()]); }
            if ($row===null) { throw new \RuntimeException('Staging owner is missing.'); }
            $this->assertSameSource($row,$record);$new=$row;$new['canonical_entity_type']=$record->getCanonicalEntityType();
            if ($created) {
                $rawPlain=$raw;$oldCanonical=null;$new['raw_payload_json']=$this->secrets()->writeNew($new,'raw_payload_json',$raw);
            } else {
                $rawPlain=$this->secrets()->read($row,'raw_payload_json');$oldCanonical=$this->secrets()->read($row,'canonical_payload_json');
            }
            $new['canonical_payload_json']=null;
            $sameType=$row['canonical_entity_type']===$new['canonical_entity_type'];
            if ($oldCanonical!==null && ($canonical===null || !$sameType)) { $this->secrets()->delete($row,'canonical_payload_json'); }
            if ($canonical!==null) {
                if ($oldCanonical!==null && $sameType) {
                    $new['canonical_payload_json']=StagingSecretStore::decode($oldCanonical)===$record->getCanonicalPayload()?$row['canonical_payload_json']:$this->secrets()->replace($row,'canonical_payload_json',$canonical);
                } else { $new['canonical_payload_json']=$this->secrets()->writeNew($new,'canonical_payload_json',$canonical); }
            }
            $safe=StagingSecretStore::diagnostics($record->getValidationErrors(),$record->getQuarantineReason(),StagingSecretStore::decode($rawPlain),$record->getCanonicalPayload(),['submitted_source'=>$record->getRawPayload(),'previous_canonical'=>$oldCanonical===null?null:StagingSecretStore::decode($oldCanonical)]);
            $this->db->update($this->table,['raw_payload_json'=>$new['raw_payload_json'],'canonical_entity_type'=>$new['canonical_entity_type'],'canonical_payload_json'=>$new['canonical_payload_json'],
                'status'=>$record->getStatus()->value,'validation_errors_json'=>StagingSecretStore::encode($safe['validation_errors']),'quarantine_reason'=>$safe['quarantine_reason'],
                'target_entity_id'=>$record->getTargetEntityId(),'processed_at'=>$record->getProcessedAt()],'id=:id',['id'=>$row['id']]);
            return (int)$row['id'];
        });$record->setId($id);
    }
    private function readRows(string $where,array $bindings): array
    {
        $this->prepareStorage();return $this->db->transaction(function() use($where,$bindings): array {
            $this->secrets()->guard();$rows=$this->db->select('SELECT * FROM migration_staging_records WHERE '.$where.' ORDER BY id'.$this->db->forUpdate(),$bindings);
            return array_map(fn(array $row)=>$this->mapRowToRecord($row),$rows);
        });
    }
    public function find(int $id): ?StagingRecord { return $this->readRows('id=?',[$id])[0]??null; }
    public function findBySourceEntity(string $batchId,string $sourceEntityType,string $sourceEntityId): ?StagingRecord { return $this->readRows('batch_id=? AND source_entity_type=? AND source_entity_id=?',[$batchId,$sourceEntityType,$sourceEntityId])[0]??null; }
    public function getBatchRecords(string $batchId,?StagingRecordStatus $status=null): array
    {
        return $status===null?$this->readRows('batch_id=?',[$batchId]):$this->readRows('batch_id=? AND status=?',[$batchId,$status->value]);
    }
    public function updateStatus(int $id,StagingRecordStatus $status,?string $reason=null,array $errors=[]): void
    {
        $this->prepareStorage();$this->db->transaction(function() use($id,$status,$reason,$errors): void {
            $this->secrets()->guard();$row=$this->db->selectOne('SELECT * FROM migration_staging_records WHERE id=?'.$this->db->forUpdate(),[$id]);if ($row===null) { return; }
            $raw=$this->secrets()->read($row,'raw_payload_json');$canonical=$this->secrets()->read($row,'canonical_payload_json');
            $safe=StagingSecretStore::diagnostics($errors,$reason,StagingSecretStore::decode($raw),$canonical===null?null:StagingSecretStore::decode($canonical));
            $this->db->update($this->table,['status'=>$status->value,'quarantine_reason'=>$safe['quarantine_reason'],'validation_errors_json'=>StagingSecretStore::encode($safe['validation_errors']),'processed_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$id]);
        });
    }

    public function generateAccountingReport(string $batchId): StagingAccountingReport
    {
        $this->ensureTable();

        $rows = $this->db->select(
            sprintf('SELECT status, COUNT(*) as cnt FROM %s WHERE batch_id = :b GROUP BY status', $this->table),
            ['b' => $batchId]
        );

        $statusCounts = [];
        $total = 0;
        foreach ($rows as $r) {
            $st = (string) $r['status'];
            $cnt = (int) $r['cnt'];
            $statusCounts[$st] = $cnt;
            $total += $cnt;
        }

        $migrated = $statusCounts[StagingRecordStatus::MIGRATED->value] ?? 0;
        $quarantined = $statusCounts[StagingRecordStatus::QUARANTINED->value] ?? 0;
        $skipped = $statusCounts[StagingRecordStatus::SKIPPED_UNSUPPORTED->value] ?? 0;
        $failed = $statusCounts[StagingRecordStatus::FAILED->value] ?? 0;

        $inFlight = ($statusCounts[StagingRecordStatus::STAGED->value] ?? 0)
            + ($statusCounts[StagingRecordStatus::VALIDATED->value] ?? 0)
            + ($statusCounts[StagingRecordStatus::TRANSFORMED->value] ?? 0);

        return new StagingAccountingReport(
            batchId: $batchId,
            totalStagedRecords: $total,
            migratedCount: $migrated,
            quarantinedCount: $quarantined,
            skippedCount: $skipped,
            failedCount: $failed,
            inFlightCount: $inFlight,
            statusCounts: $statusCounts
        );
    }

    private function mapRowToRecord(array $row): StagingRecord
    {
        $raw=StagingSecretStore::decode($this->secrets()->read($row,'raw_payload_json'));$canonical=$this->secrets()->read($row,'canonical_payload_json');$canonical=$canonical===null?null:StagingSecretStore::decode($canonical);
        $errors=$row['validation_errors_json']===null?[]:StagingSecretStore::decode($row['validation_errors_json']);$safe=StagingSecretStore::diagnostics($errors,$row['quarantine_reason'],$raw,$canonical);
        return new StagingRecord(id:(int)$row['id'],batchId:$row['batch_id'],sourceSystem:$row['source_system'],sourceEntityType:$row['source_entity_type'],sourceEntityId:$row['source_entity_id'],rawPayload:$raw,
            status:StagingRecordStatus::from($row['status']),canonicalEntityType:$row['canonical_entity_type'],canonicalPayload:$canonical,validationErrors:$safe['validation_errors'],quarantineReason:$safe['quarantine_reason'],
            targetEntityId:$row['target_entity_id']===null?null:(int)$row['target_entity_id'],processedAt:$row['processed_at'],createdAt:$row['created_at']);
    }
}
