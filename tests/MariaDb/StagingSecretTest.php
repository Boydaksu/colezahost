<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class StagingSecretTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\StagingSecretCases;
    private function stagingPeer(): \Coleza\Foundation\Database\Connection
    {
        $pdo=new \PDO((string)getenv('COLEZA_TEST_MARIADB_DSN'),getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec('USE `'.$this->database.'`');return new \Coleza\Foundation\Database\Connection($pdo);
    }
    public function testNonTransactionalOwnerCannotCreateAnyVaultPayload(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$this->db->statement('ALTER TABLE migration_staging_records ENGINE=MyISAM');$this->assertStagingRejected(fn()=>$repo->save($this->stagingRecord()),$this->stagingRows());
    }
    public function testFourDuplicateWritersShareOnePristineSourceWithoutOrphanSecrets(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$results=$this->runParallel('stagingDuplicate',0);
        self::assertCount(4,array_filter($results,fn($r)=>$r['status']==='ok'));self::assertCount(1,array_unique(array_column($results,'id')));
        self::assertCount(1,$this->db->select('SELECT * FROM migration_staging_records'));self::assertCount(2,$this->db->select("SELECT * FROM vault_secrets WHERE namespace='migration-staging'"));
        $record=$repo->find((int)$results[0]['id']);self::assertStringStartsWith('synthetic-worker-',$record->getRawPayload()['password']);self::assertSame(1.0,$record->getRawPayload()['amount']);self::assertStringStartsWith('synthetic-canonical-',$record->getCanonicalPayload()['token']);
    }
    public function testOldParentSnapshotReadsCurrentOwnerAndCanonicalCipherTogether(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$record->markTransformed('client',['token'=>'old-canonical']);$repo->save($record);
        $peerRepo=new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($this->stagingPeer(),\Coleza\Tests\Support\SecretFixtureKey::encryptor());$peerRecord=$peerRepo->find($record->getId());
        $this->db->beginTransaction();try {
            $this->db->select('SELECT * FROM migration_staging_records');$peerRecord->markTransformed('contact',['token'=>'new-canonical']);$peerRepo->save($peerRecord);
            $fresh=$repo->find($record->getId());self::assertSame('contact',$fresh->getCanonicalEntityType());self::assertSame(['token'=>'new-canonical'],$fresh->getCanonicalPayload());self::assertSame($record->getRawPayload(),$fresh->getRawPayload());self::assertTrue($this->db->inTransaction());
        }finally { $this->db->rollBack(); }
    }
    public function testOldParentSnapshotCannotAuthorizeAStaleKeyWriterOrReader(): void
    {
        $repo=$this->stagingSecrets();$record=$this->stagingRecord();$repo->save($record);$before=$this->stagingRows();
        $this->db->statement('CREATE TABLE system_settings (id INT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(128) UNIQUE,setting_value TEXT,is_encrypted INT) ENGINE=InnoDB');
        $binding=\Coleza\Domain\Vault\KeyBinding::class;$this->db->insert('system_settings',['setting_key'=>$binding::SETTING,'setting_value'=>(new \Coleza\Domain\Vault\SecretCipher(\Coleza\Tests\Support\SecretFixtureKey::encryptor()))->seal($binding::PROBE,$binding::SCOPE),'is_encrypted'=>1]);$peer=$this->stagingPeer();
        $this->db->beginTransaction();try {
            $this->db->select('SELECT * FROM system_settings');$newKey=\Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate());$peer->statement('UPDATE system_settings SET setting_value=?',[(new \Coleza\Domain\Vault\SecretCipher($newKey))->seal($binding::PROBE,$binding::SCOPE)]);
            $this->assertStagingRejected(fn()=>$repo->find($record->getId()),$before);$this->assertStagingRejected(fn()=>$repo->updateStatus($record->getId(),\Coleza\Domain\Migration\Staging\StagingRecordStatus::FAILED),$before);self::assertTrue($this->db->inTransaction());
        }finally { $this->db->rollBack(); }
    }
    public function testMaintenanceCliAdmissionIdempotenceAndRestart(): void
    {
        $repo=$this->stagingSecrets();$repo->prepareStorage();$id=$this->legacyStaging('7','{"password":"synthetic-cli-staging","amount":1.0}');
        self::assertSame(1,preg_match('/host=([^;]+);port=([0-9]+)/',(string)getenv('COLEZA_TEST_MARIADB_DSN'),$matches));
        $env=array_merge(getenv(),['DB_HOST'=>$matches[1],'DB_PORT'=>$matches[2],'DB_DATABASE'=>$this->database,'DB_USERNAME'=>getenv('COLEZA_TEST_MARIADB_USER'),'DB_PASSWORD'=>getenv('COLEZA_TEST_MARIADB_PASSWORD'),'APP_KEY'=>\Coleza\Tests\Support\SecretFixtureKey::configured(),'APP_KEY_FILE'=>'']);
        $run=function(array $args) use($env): array {
            $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),dirname(__DIR__,2).'/tools/staging-secret-maintenance.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2),$env);
            self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$err];
        };
        [$code,$out,$err]=$run(['--maintenance']);self::assertSame(1,$code);self::assertSame('',$out);self::assertStringNotContainsString('synthetic-cli-staging',$err);
        foreach ([1,0] as $count) { [$code,$out,$err]=$run(['--maintenance','--confirmed-legacy-json']);self::assertSame(0,$code,$err);self::assertSame('',$err);self::assertSame(['upgraded_records'=>$count],json_decode($out,true,flags:JSON_THROW_ON_ERROR)); }
        self::assertSame(['password'=>'synthetic-cli-staging','amount'=>1.0],$this->stagingSecrets()->find($id)->getRawPayload());
    }
}
