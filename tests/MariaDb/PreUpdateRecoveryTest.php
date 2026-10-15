<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RestoreWizardService};
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Foundation\Database\Connection;
use PDO;
use RuntimeException;

final class PreUpdateRecoveryTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    private string $directory;
    private ?string $targetDatabase = null;
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory=sys_get_temp_dir().'/coleza_native_preupdate_'.bin2hex(random_bytes(12));mkdir($this->directory,0700);
    }
    protected function tearDown(): void
    {
        if($this->targetDatabase!==null){$this->root->exec('DROP DATABASE `'.$this->targetDatabase.'`');}
        if(isset($this->directory)){BackupFileTree::removeOwned($this->directory);}parent::tearDown();
    }
    public function testPreUpdateArchiveRestoresRealMariaDbRowsSchemaAndFiles(): void
    {
        $this->db->statement('CREATE TABLE update_records (id INT PRIMARY KEY, amount DECIMAL(19,4), payload LONGBLOB, body LONGTEXT, created_at TIMESTAMP NULL, doubled DECIMAL(20,4) AS (amount*2) STORED) ENGINE=InnoDB');
        $this->db->statement("SET SESSION time_zone='+03:00'");
        $this->db->insert('update_records',['id'=>7,'amount'=>'1234567890123.4567','payload'=>"\0\xff'\\;",'body'=>"İstanbul 🌍 ' ; \\ \n",'created_at'=>'2026-10-15 12:00:00']);
        mkdir($this->directory.'/source/empty',0700,true);file_put_contents($this->directory.'/source/version.php','old version');
        $service=new PreUpdateBackupService($this->directory.'/backups',$this->db,'1.0.0');
        $backup=$service->createVerifiedPreUpdateBackup('1.1.0',['application'=>$this->directory.'/source']);
        self::assertTrue($backup['verification']->isValid());
        self::assertSame('pre_update',$backup['manifest']->getType());
        self::assertSame('+00:00',$backup['manifest']->getMetadata()['database']['time_zone']);
        self::assertSame(1,$backup['manifest']->getMetadata()['database']['tables']['update_records']['rows']);
        self::assertSame('+03:00',$this->db->getPdo()->query('SELECT @@time_zone')->fetchColumn());
        $this->targetDatabase='colezahost_d02_test_'.bin2hex(random_bytes(8));
        $this->root->exec('CREATE DATABASE `'.$this->targetDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $target=new Connection(new PDO(getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->targetDatabase,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));
        $restore=(new RestoreWizardService($this->directory.'/restore'))->executeRestore($backup['manifest']->getBackupId(),new LocalBackupStorageAdapter($this->directory.'/backups'),$target,$this->directory.'/target');
        self::assertTrue($restore->isSuccess(),$restore->getErrorMessage()??'');
        $this->db->statement("SET SESSION time_zone='+00:00'");$target->statement("SET SESSION time_zone='+00:00'");
        self::assertSame($this->db->select('SELECT * FROM update_records'),$target->select('SELECT * FROM update_records'));
        self::assertSame('old version',file_get_contents($this->directory.'/target/application/version.php'));
        self::assertDirectoryExists($this->directory.'/target/application/empty');
    }
    public function testUnsupportedTableCannotProduceSuccessfulPreUpdateBackup(): void
    {
        $this->db->statement('CREATE TABLE unsafe_update_source (id INT PRIMARY KEY) ENGINE=MyISAM');
        try{(new PreUpdateBackupService($this->directory.'/backups',$this->db))->createVerifiedPreUpdateBackup('1.1.0');self::fail('Partial snapshot was admitted.');}
        catch(RuntimeException $error){self::assertStringContainsString('InnoDB',$error->getMessage());self::assertSame([],glob($this->directory.'/backups/*.manifest.json'));}
    }
    public function testCallerTransactionRemainsOwnedAndUncommitted(): void
    {
        $this->db->statement('CREATE TABLE update_marker(id INT PRIMARY KEY) ENGINE=InnoDB');$pdo=$this->db->getPdo();$pdo->beginTransaction();$this->db->insert('update_marker',['id'=>1]);
        try{
            try{(new PreUpdateBackupService($this->directory.'/backups',$this->db))->createVerifiedPreUpdateBackup('1.1.0');self::fail('Caller transaction accepted.');}
            catch(RuntimeException $error){self::assertStringContainsString('consistent snapshot',$error->getMessage());self::assertTrue($pdo->inTransaction());self::assertSame([],glob($this->directory.'/backups/*.manifest.json'));}
        }finally{$pdo->rollBack();}
        self::assertSame([],$this->db->select('SELECT * FROM update_marker'));
    }
}
