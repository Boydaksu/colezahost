<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Backup\{BackupScope,EnterpriseBackupService,LogicalDatabaseDump,RestoreWizardService};
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier;
use Coleza\Domain\Privacy\Tombstone\FileTombstoneStore;
use Coleza\Foundation\Database\Connection;
use PDO;

final class BackupRecoveryTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    private string $directory;
    private ?string $targetDatabase=null;
    protected function setUp(): void {parent::setUp();$this->directory=sys_get_temp_dir().'/coleza_native_recovery_'.bin2hex(random_bytes(8));mkdir($this->directory,0700);}
    protected function tearDown(): void
    {
        if($this->targetDatabase!==null){$this->root->exec('DROP DATABASE `'.$this->targetDatabase.'`');}
        if(isset($this->directory)){\Coleza\Domain\Backup\BackupFileTree::removeOwned($this->directory);}parent::tearDown();
    }
    private function independentConnection(string $database): Connection
    {
        $dsn=getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$database;
        return new Connection(new PDO($dsn,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));
    }
    private function target(): Connection
    {
        $this->targetDatabase='colezahost_d02_test_'.bin2hex(random_bytes(8));$this->root->exec('CREATE DATABASE `'.$this->targetDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');return $this->independentConnection($this->targetDatabase);
    }
    public function testNativeTypesForeignKeysAndGeneratedColumnsRoundTrip(): void
    {
        $this->db->statement('CREATE TABLE parents (id BIGINT UNSIGNED PRIMARY KEY,label VARCHAR(200)) ENGINE=InnoDB');
        $this->db->statement('CREATE TABLE children (id INT AUTO_INCREMENT PRIMARY KEY,parent_id BIGINT UNSIGNED,amount DECIMAL(19,4),payload LONGBLOB,body LONGTEXT,optional_value TEXT NULL,doubled DECIMAL(20,4) AS (amount*2) STORED,CONSTRAINT native_backup_parent FOREIGN KEY(parent_id) REFERENCES parents(id)) ENGINE=InnoDB');
        $this->db->insert('parents',['id'=>'18446744073709551614','label'=>'İstanbul 🌍']);
        $this->db->insert('children',['parent_id'=>'18446744073709551614','amount'=>'1234567890123.4567','payload'=>"\x00\xff\x01'\\;",'body'=>"line;\n-- not a comment\nDPO's \\ İstanbul\0end",'optional_value'=>null]);
        $this->db->statement("SET SESSION sql_mode=CONCAT(@@sql_mode,',NO_AUTO_VALUE_ON_ZERO')");$this->db->insert('children',['id'=>0,'parent_id'=>'18446744073709551614','amount'=>'0.0001','payload'=>'','body'=>'zero identity','optional_value'=>null]);
        $store=new LocalBackupStorageAdapter($this->directory.'/store');$result=(new EnterpriseBackupService($this->directory.'/work',$this->db))->createBackup(BackupScope::DATABASE,$store);$target=$this->target();
        $restore=(new RestoreWizardService($this->directory.'/stage'))->executeRestore($result['manifest']->getBackupId(),$store,$target,$this->directory.'/app');
        self::assertTrue($restore->isSuccess(),$restore->getErrorMessage()??'');
        self::assertSame($this->db->select('SELECT * FROM parents ORDER BY id'),$target->select('SELECT * FROM parents ORDER BY id'));self::assertSame($this->db->select('SELECT * FROM children ORDER BY id'),$target->select('SELECT * FROM children ORDER BY id'));
        self::assertSame(1,(int)$target->getPdo()->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());
    }
    public function testConcurrentCommitCannotMixTwoTableVersions(): void
    {
        foreach(['a_snapshot','b_snapshot']as$table){$this->db->statement('CREATE TABLE '.$table.' (id INT PRIMARY KEY, version INT) ENGINE=InnoDB');$this->db->insert($table,['id'=>1,'version'=>1]);}
        $writer=$this->independentConnection($this->database);$committed=false;
        $inventory=(new LogicalDatabaseDump())->export($this->db,$this->directory.'/snapshot.sql',function(string $table)use($writer,&$committed):void{
            if($table==='a_snapshot'){$writer->transaction(function()use($writer):void{$writer->statement('UPDATE a_snapshot SET version=2');$writer->statement('UPDATE b_snapshot SET version=2');});$committed=true;}
        });
        self::assertTrue($committed);$target=$this->target();
        foreach(\Coleza\Domain\Backup\SqlStatementReader::read(file_get_contents($this->directory.'/snapshot.sql'))as$sql){$target->statement($sql);}
        self::assertSame(1,(int)$target->selectOne('SELECT version FROM a_snapshot')['version']);self::assertSame(1,(int)$target->selectOne('SELECT version FROM b_snapshot')['version']);self::assertSame(2,(int)$this->db->selectOne('SELECT version FROM b_snapshot')['version']);self::assertSame(['a_snapshot','b_snapshot'],array_keys($inventory['tables']));
    }
    public function testNativePrivacyFailureCannotCommitHalfScrub(): void
    {
        $this->db->statement('CREATE TABLE users(id INT PRIMARY KEY,name VARCHAR(100),email VARCHAR(100),status VARCHAR(30),phone VARCHAR(30),password_hash VARCHAR(100)) ENGINE=InnoDB');$this->db->insert('users',['id'=>7,'name'=>'Private','email'=>'erase@example.test','status'=>'active','phone'=>'private','password_hash'=>'hash']);
        $this->db->statement('CREATE TABLE payment_methods(id INT PRIMARY KEY,user_id INT,token VARCHAR(100)) ENGINE=InnoDB');$this->db->insert('payment_methods',['id'=>1,'user_id'=>7,'token'=>'private-token']);
        $this->db->statement("CREATE TRIGGER refuse_privacy_update BEFORE UPDATE ON payment_methods FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='refused'");
        $service=new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db,secretKey:\Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(),salt:\Coleza\Tests\Support\PrivacyFixtureKeys::email());$service->recordTombstone(7,'erase@example.test');$failed=false;
        try{(new \Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService($this->db,$service))->reconcileAfterBackupRestore();}catch(\RuntimeException){$failed=true;}
        self::assertTrue($failed);self::assertSame('active',$this->db->selectOne('SELECT status FROM users')['status']);self::assertSame('private-token',$this->db->selectOne('SELECT token FROM payment_methods')['token']);self::assertFalse($this->db->getPdo()->inTransaction());
    }
    public function testNonTransactionalTableIsRejectedWithoutPublishing(): void
    {
        $this->db->statement('CREATE TABLE unsupported_backup (id INT PRIMARY KEY) ENGINE=MyISAM');$store=new LocalBackupStorageAdapter($this->directory.'/store');
        try{(new EnterpriseBackupService($this->directory.'/work',$this->db))->createBackup(BackupScope::DATABASE,$store);self::fail('Nontransactional backup published');}
        catch(\RuntimeException){self::assertSame([],$store->listBackups());self::assertFalse($this->db->getPdo()->inTransaction());}
    }
    public function testTimestampEpochDoesNotShiftAcrossHostTimezones(): void
    {
        $this->db->statement("SET SESSION time_zone='+03:00'");$this->db->statement('CREATE TABLE times(id INT PRIMARY KEY,occurred_at TIMESTAMP(6),wall DATETIME(6)) ENGINE=InnoDB');$this->db->statement("INSERT INTO times VALUES(1,'2026-10-15 12:00:00.123456','2026-10-15 12:00:00.123456')");
        $expected=$this->db->selectOne('SELECT UNIX_TIMESTAMP(occurred_at) AS epoch,wall FROM times');$store=new LocalBackupStorageAdapter($this->directory.'/store');$backup=(new EnterpriseBackupService($this->directory.'/work',$this->db))->createBackup(BackupScope::DATABASE,$store);self::assertSame('+03:00',$this->db->getPdo()->query('SELECT @@time_zone')->fetchColumn());
        $target=$this->target();$target->statement("SET SESSION time_zone='-05:00'");$result=(new RestoreWizardService($this->directory.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$target,$this->directory.'/app');
        self::assertTrue($result->isSuccess(),$result->getErrorMessage()??'');self::assertSame($expected,$target->selectOne('SELECT UNIX_TIMESTAMP(occurred_at) AS epoch,wall FROM times'));self::assertSame('-05:00',$target->getPdo()->query('SELECT @@time_zone')->fetchColumn());
    }
    public function testConnectionCharsetAndNoBackslashModeArePreserved(): void
    {
        $this->db->statement("CREATE TABLE exact_text(id INT PRIMARY KEY,body TEXT DEFAULT 'DPO''s \\\\ path') ENGINE=InnoDB");$this->db->insert('exact_text',['id'=>1,'body'=>'İstanbul 🌍']);
        $this->db->statement("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES'");$this->db->statement('SET NAMES latin1');
        $before=$this->db->selectOne('SELECT @@character_set_results AS charset,@@sql_mode AS mode');$store=new LocalBackupStorageAdapter($this->directory.'/store');$backup=(new EnterpriseBackupService($this->directory.'/work',$this->db))->createBackup(BackupScope::DATABASE,$store);
        self::assertSame($before,$this->db->selectOne('SELECT @@character_set_results AS charset,@@sql_mode AS mode'));$target=$this->target();$target->statement('SET NAMES latin1');$original=$target->selectOne('SELECT @@character_set_results AS charset,@@sql_mode AS mode');
        $result=(new RestoreWizardService($this->directory.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$target,$this->directory.'/app');self::assertTrue($result->isSuccess(),$result->getErrorMessage()??'');self::assertSame($original,$target->selectOne('SELECT @@character_set_results AS charset,@@sql_mode AS mode'));
        $target->statement('SET NAMES utf8mb4');self::assertSame('İstanbul 🌍',$target->selectOne('SELECT body FROM exact_text')['body']);
    }
    public function testOrphanForeignKeyCannotPassRestoreHealthGate(): void
    {
        $this->db->statement('CREATE TABLE parents(id INT PRIMARY KEY) ENGINE=InnoDB');$this->db->statement('CREATE TABLE children(id INT PRIMARY KEY,parent_id INT,FOREIGN KEY(parent_id) REFERENCES parents(id)) ENGINE=InnoDB');$this->db->statement('SET FOREIGN_KEY_CHECKS=0');$this->db->statement('INSERT INTO children VALUES(1,999)');
        $store=new LocalBackupStorageAdapter($this->directory.'/store');$backup=(new EnterpriseBackupService($this->directory.'/work',$this->db))->createBackup(BackupScope::DATABASE,$store);$target=$this->target();
        mkdir($this->directory.'/app');file_put_contents($this->directory.'/app/live.txt','untouched');$result=(new RestoreWizardService($this->directory.'/stage'))->executeRestore($backup['manifest']->getBackupId(),$store,$target,$this->directory.'/app');
        self::assertFalse($result->isSuccess());self::assertStringContainsString('orphan',$result->getErrorMessage());self::assertSame(0,$result->getFilesRestored());self::assertSame('untouched',file_get_contents($this->directory.'/app/live.txt'));self::assertSame(1,(int)$target->getPdo()->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());
    }
    public function testCanonicalInstallRestoresIntoFreshDatabaseInRecoveryMode(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $this->db->insert('users',['id'=>7,'email'=>'erase@example.test','password_hash'=>'synthetic-hash','name'=>'Private Name','first_name'=>'Private','last_name'=>'Name','is_active'=>1]);
        $store=new LocalBackupStorageAdapter($this->directory.'/store');$backup=(new EnterpriseBackupService($this->directory.'/work',$this->db))->createBackup(BackupScope::FULL,$store);$target=$this->target();
        $path=$this->directory.'/authoritative-empty.json';file_put_contents($path,'[]');$keys=['tombstone'=>\Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(),'email'=>\Coleza\Tests\Support\PrivacyFixtureKeys::email()];
        (new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db,new FileTombstoneStore($path,$keys['tombstone']),secretKey:$keys['tombstone'],salt:$keys['email']))->recordTombstone(7,'erase@example.test');
        $privacy=new PrivacyIntegrityVerifier($target,$keys,new FileTombstoneStore($path,$keys['tombstone']));
        $restore=(new RestoreWizardService($this->directory.'/stage',privacyVerifier:$privacy))->executeRestore($backup['manifest']->getBackupId(),$store,$target,$this->directory.'/app');
        self::assertTrue($restore->isSuccess(),$restore->getErrorMessage()??'');self::assertSame(26,(int)$target->getPdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertSame('recovery',(new \Coleza\Domain\OperationalMode\OperationalModeManager($target))->getCurrentMode()->value);self::assertTrue($restore->getMetadata()['recovery_required']);
        $user=$target->selectOne('SELECT * FROM users WHERE id=7');self::assertSame('erased',$user['status']);self::assertSame(0,(int)$user['is_active']);self::assertNull($user['first_name']);self::assertNull($user['last_name']);self::assertSame('ERASED',$user['password_hash']);self::assertSame([7],$restore->getReconciliationReport()->getScrubbedUserIds());
    }
}
