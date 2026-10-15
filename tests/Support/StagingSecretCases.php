<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait StagingSecretCases
{
    private function stagingSecrets(): \Coleza\Domain\Migration\Staging\DatabaseStagingRepository { return new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($this->db, SecretFixtureKey::encryptor()); }
    public function testRawAndCanonicalSecretsAreEncryptedAndRestartExactly(): void
    {
        $repo=$this->stagingSecrets(); $raw=['password'=>'synthetic-staging-password','opaque'=>'synthetic-staging-opaque','amount'=>1.0,'bytes'=>"a\0b"];
        $record=\Coleza\Domain\Migration\Staging\StagingRecord::createNew('batch','csv','client','7',$raw);
        $record->markTransformed('client',['token'=>'synthetic-canonical-token','amount'=>1.0]); $repo->save($record);
        $stored=json_encode($this->db->select('SELECT * FROM migration_staging_records'),JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-staging-opaque',$stored);
        self::assertStringNotContainsString('synthetic-canonical-token',$stored);
        $fresh=$this->stagingSecrets()->find($record->getId());self::assertSame($raw,$fresh->getRawPayload());self::assertSame($record->getCanonicalPayload(),$fresh->getCanonicalPayload());
    }
    public function testValidationAndQuarantineDiagnosticsDoNotEchoPayloadSecrets(): void
    {
        $repo=$this->stagingSecrets();$record=\Coleza\Domain\Migration\Staging\StagingRecord::createNew('batch','csv','client','7',['password'=>'synthetic-staging-echo']);$repo->save($record);
        $repo->updateStatus($record->getId(),\Coleza\Domain\Migration\Staging\StagingRecordStatus::QUARANTINED,'Echoed synthetic-staging-echo',['Remote said synthetic-staging-echo']);
        $row=$this->db->selectOne('SELECT validation_errors_json,quarantine_reason FROM migration_staging_records');self::assertStringNotContainsString('synthetic-staging-echo',json_encode($row,JSON_THROW_ON_ERROR));
    }

    private function stagingRecord(string $source='7',array $raw=['password'=>'synthetic-staging-secret']): \Coleza\Domain\Migration\Staging\StagingRecord
    {
        return \Coleza\Domain\Migration\Staging\StagingRecord::createNew('batch','csv','client',$source,$raw);
    }
    private function stagingRows(): array
    {
        $rows=[];foreach (['migration_staging_records','vault_secrets','security_audit_logs'] as $table) { $rows[$table]=$this->db->select("SELECT * FROM {$table} ORDER BY id"); }return $rows;
    }
    private function assertStagingRejected(callable $action,array $before): void
    {
        $rejected=false;try { $action(); }catch (\RuntimeException|\LogicException|\JsonException) { $rejected=true; }
        self::assertTrue($rejected,'Unsafe staging action was accepted');self::assertSame($before,$this->stagingRows());
    }
    private function stagingAuditFailure(string $event): void
    {
        $this->db->statement($this->db->getDriverName()==='mysql'
            ?"CREATE TRIGGER staging_audit_fail BEFORE INSERT ON security_audit_logs FOR EACH ROW BEGIN IF NEW.event_type='$event' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit failure'; END IF; END"
            :"CREATE TRIGGER staging_audit_fail BEFORE INSERT ON security_audit_logs WHEN NEW.event_type='$event' BEGIN SELECT RAISE(ABORT,'audit failure'); END");
    }
    public function testDuplicateSourcePreservesPristineRawAndChangesCanonicalScope(): void
    {
        $repo=$this->stagingSecrets();$first=$this->stagingRecord();$first->markTransformed('client',['token'=>'old-token']);$repo->save($first);
        $before=$this->db->selectOne('SELECT * FROM migration_staging_records');
        $again=$this->stagingRecord(raw:['password'=>'replacement-input']);$again->markTransformed('contact',['token'=>'new-token']);$repo->save($again);
        self::assertSame($first->getId(),$again->getId());$row=$this->db->selectOne('SELECT * FROM migration_staging_records');
        self::assertSame($before['raw_payload_json'],$row['raw_payload_json']);self::assertNotSame($before['canonical_payload_json'],$row['canonical_payload_json']);
        $fresh=$repo->find($first->getId());self::assertSame($first->getRawPayload(),$fresh->getRawPayload());self::assertSame(['token'=>'new-token'],$fresh->getCanonicalPayload());
        self::assertCount(2,$this->db->select("SELECT * FROM vault_secrets WHERE namespace='migration-staging'"));
    }
    public function testMetadataSavePreservesCipherAndExplicitCanonicalClearDeletesOnlyItsSecret(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$record->markTransformed('client',['token'=>'synthetic-clear-token']);$repo->save($record);
        $before=$this->db->select('SELECT * FROM vault_secrets');$record->markValidated();$repo->save($record);self::assertSame($before,$this->db->select('SELECT * FROM vault_secrets'));
        $clear=new \Coleza\Domain\Migration\Staging\StagingRecord($record->getId(),'batch','csv','client','7',[]);$repo->save($clear);
        self::assertNull($repo->find($record->getId())->getCanonicalPayload());self::assertSame($record->getRawPayload(),$repo->find($record->getId())->getRawPayload());
        self::assertCount(1,$this->db->select("SELECT * FROM vault_secrets WHERE namespace='migration-staging'"));
    }
    public function testImmutableSourceAndCrossRecordReferencesAreRejectedWithoutEffects(): void
    {
        $repo=$this->stagingSecrets();$a=$this->stagingRecord();$repo->save($a);$b=$this->stagingRecord('8');$repo->save($b);
        $changed=new \Coleza\Domain\Migration\Staging\StagingRecord($a->getId(),'batch','other','client','7',[]);
        $this->assertStagingRejected(fn()=>$repo->save($changed),$this->stagingRows());
        $this->db->statement('UPDATE migration_staging_records SET raw_payload_json=(SELECT value FROM (SELECT raw_payload_json value FROM migration_staging_records WHERE id=?) source_ref) WHERE id=?',[$a->getId(),$b->getId()]);
        $this->assertStagingRejected(fn()=>$repo->find($b->getId()),$this->stagingRows());
    }
    public function testCanonicalTypeTamperingAndWrongKeyCannotReadOrMutateOwner(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$record->markTransformed('client',['token'=>'synthetic-owner-token']);$repo->save($record);
        $wrong=new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($this->db,\Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate()));
        foreach ([fn()=>$wrong->find($record->getId()),fn()=>$wrong->getBatchRecords('batch'),fn()=>$wrong->save($record),fn()=>$wrong->updateStatus($record->getId(),\Coleza\Domain\Migration\Staging\StagingRecordStatus::FAILED)] as $action) { $this->assertStagingRejected($action,$this->stagingRows()); }
        $this->db->statement("UPDATE migration_staging_records SET canonical_entity_type='tampered'");$this->assertStagingRejected(fn()=>$repo->find($record->getId()),$this->stagingRows());
    }
    public function testMissingKeyFailsBeforeSchemaAndAccountingIsMetadataOnly(): void
    {
        $saved=[];foreach (['APP_KEY','APP_KEY_FILE'] as $name) { $saved[$name]=[getenv($name),$_ENV[$name]??null,$_SERVER[$name]??null];putenv($name);unset($_ENV[$name],$_SERVER[$name]); }
        try {
            $repo=new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($this->db);$rejected=false;
            try { $repo->save($this->stagingRecord()); }catch (\RuntimeException|\InvalidArgumentException) { $rejected=true; }self::assertTrue($rejected);
            foreach (['migration_staging_records','vault_secrets','security_audit_logs'] as $table) { self::assertFalse(\Coleza\Domain\Vault\KeyBinding::tableExists($this->db,$table)); }
            self::assertSame(0,$repo->generateAccountingReport('batch')->getTotalStagedRecords());
        } finally { foreach ($saved as $name=>[$env,$arrayEnv,$serverEnv]) { $env===false?putenv($name):putenv($name.'='.$env);if ($arrayEnv!==null) { $_ENV[$name]=$arrayEnv; }if ($serverEnv!==null) { $_SERVER[$name]=$serverEnv; } } }
    }
    public static function stagingCallers(): array { return [['raw'],['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('stagingCallers')]
    public function testCallerRollbackIncludesSourceCanonicalAndAllAudits(string $caller): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$before=$this->stagingRows();$caller==='raw'?$this->db->getPdo()->beginTransaction():$this->db->beginTransaction();
        try {
            $record=$this->stagingRecord();$fresh=$this->stagingSecrets();$fresh->save($record);$record->markTransformed('client',['token'=>'synthetic-parent-token']);$fresh->save($record);
            $fresh->updateStatus($record->getId(),\Coleza\Domain\Migration\Staging\StagingRecordStatus::QUARANTINED,'password=synthetic-parent-token');
            self::assertSame(1,count($fresh->getBatchRecords('batch')));self::assertTrue($this->db->inTransaction());
        } finally { $caller==='raw'?$this->db->getPdo()->rollBack():$this->db->rollBack(); }self::assertSame($before,$this->stagingRows());
    }
    public function testNewOwnerAuditFailureCannotLeavePlaceholdersOrOrphans(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$this->stagingAuditFailure('VAULT_SECRET_CREATED');$this->assertStagingRejected(fn()=>$repo->save($this->stagingRecord()),$this->stagingRows());
    }
    public function testLateCanonicalRotationOrDeleteFailurePreservesOriginalOwnerAndCipher(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$record->markTransformed('client',['token'=>'synthetic-original-token']);$repo->save($record);
        $this->stagingAuditFailure('VAULT_SECRET_ROTATED');$record->markTransformed('client',['token'=>'synthetic-replacement-token']);$this->assertStagingRejected(fn()=>$repo->save($record),$this->stagingRows());
        $this->db->statement('DROP TRIGGER staging_audit_fail');$this->stagingAuditFailure('VAULT_SECRET_DELETED');
        $clear=new \Coleza\Domain\Migration\Staging\StagingRecord($record->getId(),'batch','csv','client','7',[]);$this->assertStagingRejected(fn()=>$repo->save($clear),$this->stagingRows());
    }
    public function testReadAuditFailureDoesNotReturnPayloadOrChangeAnyRows(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$repo->save($record);$this->stagingAuditFailure('VAULT_SECRET_ACCESSED');$this->assertStagingRejected(fn()=>$repo->find($record->getId()),$this->stagingRows());
    }
    private function legacyStaging(string $source,string $raw,?string $canonical=null): int
    {
        return (int)$this->db->insert('migration_staging_records',['batch_id'=>'batch','source_system'=>'csv','source_entity_type'=>'client','source_entity_id'=>$source,'raw_payload_json'=>$raw,'canonical_entity_type'=>$canonical===null?null:'client','canonical_payload_json'=>$canonical,'validation_errors_json'=>'[]']);
    }
    public function testExplicitLegacyUpgradePreservesRawJsonBytesAndScrubsDiagnostics(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$raw="{ \"password\" : \"synthetic-legacy-staging\", \"amount\":1.0 }";$canonical='{"token":"synthetic-legacy-canonical"}';$id=$this->legacyStaging('7',$raw,$canonical);
        $this->db->statement('UPDATE migration_staging_records SET quarantine_reason=?,validation_errors_json=? WHERE id=?',['Echoed synthetic-legacy-staging','["Echoed synthetic-legacy-canonical"]',$id]);
        $store=new \Coleza\Domain\Migration\Staging\StagingSecretStore($this->db,SecretFixtureKey::encryptor());self::assertSame(1,$store->upgrade(true));
        $row=$this->db->selectOne('SELECT * FROM migration_staging_records WHERE id=?',[$id]);self::assertSame($raw,$store->read($row,'raw_payload_json'));self::assertSame($canonical,$store->read($row,'canonical_payload_json'));
        self::assertSame(0,$store->upgrade(true));self::assertStringNotContainsString('synthetic-legacy-',json_encode($this->db->select('SELECT * FROM migration_staging_records'),JSON_THROW_ON_ERROR));
        self::assertSame(1.0,$repo->find($id)->getRawPayload()['amount']);
    }
    public function testMalformedLegacyBatchNoConfirmationAndParentCannotPartiallyUpgrade(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$id=$this->legacyStaging('7','{"password":"synthetic-legacy-good"}');$bad=$this->legacyStaging('8','{broken');$store=new \Coleza\Domain\Migration\Staging\StagingSecretStore($this->db,SecretFixtureKey::encryptor());
        $before=$this->stagingRows();$this->assertStagingRejected(fn()=>$store->upgrade(),$before);$this->assertStagingRejected(fn()=>$store->upgrade(true),$before);$this->assertStagingRejected(fn()=>$repo->find($id),$before);
        $this->db->beginTransaction();try { $this->assertStagingRejected(fn()=>$store->upgrade(true),$before);self::assertTrue($this->db->inTransaction()); }finally { $this->db->rollBack(); }
        $this->db->statement('DELETE FROM migration_staging_records WHERE id=?',[$bad]);self::assertSame(1,$store->upgrade(true));
    }
    public function testConflictingVaultOwnerAndExtraUniqueIndexAreRejected(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$id=$this->legacyStaging('7','{}');$row=$this->db->selectOne('SELECT * FROM migration_staging_records WHERE id=?',[$id]);
        (new \Coleza\Domain\Vault\VaultReferenceStore($this->db,SecretFixtureKey::encryptor()))->write(\Coleza\Domain\Migration\Staging\StagingSecretStore::NAMESPACE,\Coleza\Domain\Migration\Staging\StagingSecretStore::key($row,'raw_payload_json'),'{}');
        $store=new \Coleza\Domain\Migration\Staging\StagingSecretStore($this->db,SecretFixtureKey::encryptor());$this->assertStagingRejected(fn()=>$store->upgrade(true),$this->stagingRows());
        $this->db->statement('CREATE UNIQUE INDEX staging_unexpected ON migration_staging_records (source_system)');$this->assertStagingRejected(fn()=>$repo->save($this->stagingRecord('8')),$this->stagingRows());
    }
    public function testLegacyDiagnosticReadIsSafeWithoutRewritingEvidenceAndCanBeExplicitlyScrubbed(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$repo->save($record);$this->db->statement('UPDATE migration_staging_records SET quarantine_reason=?',['Echoed synthetic-staging-secret']);
        $before=$this->db->select('SELECT * FROM migration_staging_records');self::assertStringNotContainsString('synthetic-staging-secret',$repo->find($record->getId())->getQuarantineReason());self::assertSame($before,$this->db->select('SELECT * FROM migration_staging_records'));
        self::assertSame(1,(new \Coleza\Domain\Migration\Staging\StagingSecretStore($this->db,SecretFixtureKey::encryptor()))->upgrade(true));
    }
    public function testMalformedDiagnosticJsonIsNotSilentlyReplacedWithAnEmptyList(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$repo->save($record);$this->db->statement("UPDATE migration_staging_records SET validation_errors_json='{broken'");$this->assertStagingRejected(fn()=>$repo->find($record->getId()),$this->stagingRows());
    }

    public function testDefaultDiagnosticSerializationHidesEntirePayloadButOperationalGettersRemainExact(): void
    {
        $record=$this->stagingRecord(raw:['password'=>'synthetic-dto-secret','opaque'=>'synthetic-opaque-dto']);$record->markTransformed('client',['token'=>'synthetic-dto-token']);$record->markQuarantined('Echoed synthetic-dto-secret');
        $safe=json_encode([$record,$record->__debugInfo()],JSON_THROW_ON_ERROR);
        foreach (['synthetic-dto-secret','synthetic-opaque-dto','synthetic-dto-token'] as $secret) { self::assertStringNotContainsString($secret,$safe); }
        self::assertSame('synthetic-opaque-dto',$record->getRawPayload()['opaque']);self::assertSame($record->getRawPayload(),$record->toArray()['raw_payload']);
    }

    public function testRawCardDataIsRejectedBeforeAnyOwnerOrVaultWrite(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();
        foreach ([['card_number'=>'4111111111111111'],['nested'=>['cvv'=>'123']],['note'=>'4111 1111 1111 1111']] as $raw) { $this->assertStagingRejected(fn()=>$repo->save($this->stagingRecord(raw:$raw)),$this->stagingRows()); }
        $canonical=$this->stagingRecord(raw:[]);$canonical->markTransformed('payment',['cvc'=>'123']);$this->assertStagingRejected(fn()=>$repo->save($canonical),$this->stagingRows());
        $safe=$this->stagingRecord(raw:['card_last4'=>'4242','card_brand'=>'visa','payment_method_token'=>'synthetic-tokenized-method']);$repo->save($safe);self::assertSame($safe->getRawPayload(),$repo->find($safe->getId())->getRawPayload());
    }
    public function testLegacyRawCardBatchIsRejectedInsteadOfEncryptingOrSilentlyDroppingIt(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$this->legacyStaging('7','{"card_number":"4111111111111111"}');
        $store=new \Coleza\Domain\Migration\Staging\StagingSecretStore($this->db,SecretFixtureKey::encryptor());$this->assertStagingRejected(fn()=>$store->upgrade(true),$this->stagingRows());
    }
}
