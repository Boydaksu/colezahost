<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
use Coleza\Domain\Backup\{BackupScope,EnterpriseBackupService};
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
final class BackupAdmissionTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    private string $backupRoot;
    protected function setUp(): void
    {
        parent::setUp();$this->backupRoot=sys_get_temp_dir().'/coleza_backup_admission_'.bin2hex(random_bytes(8));mkdir($this->backupRoot,0700,true);file_put_contents($this->backupRoot.'/source.txt','Native MariaDB source');
    }
    protected function tearDown(): void
    {
        if(isset($this->backupRoot)){$this->removeBackupFiles($this->backupRoot);}parent::tearDown();
    }
    private function removeBackupFiles(string $path): void
    {
        if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path)){return;}foreach(scandir($path)as$name){if($name!=='.'&&$name!=='..'){$this->removeBackupFiles($path.'/'.$name);}}rmdir($path);
    }
    public static function databaseScopes(): array {return [[BackupScope::DATABASE],[BackupScope::FULL]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('databaseScopes')]
    public function testMariaDbScopePublishesActualRowsInsteadOfCommentOnlyDump(BackupScope $scope): void
    {
        $this->db->statement('CREATE TABLE backup_source (id INT PRIMARY KEY,value TEXT)');$this->db->insert('backup_source',['id'=>1,'value'=>"O'Reilly İstanbul"]);
        $result=(new EnterpriseBackupService($this->backupRoot.'/work',$this->db))->createBackup($scope,new LocalBackupStorageAdapter($this->backupRoot.'/store'),[$this->backupRoot.'/source.txt']);
        self::assertTrue($result['verified']);self::assertSame(['rows'=>1],$result['manifest']->getMetadata()['database']['tables']['backup_source']);$zip=new \ZipArchive();self::assertTrue($zip->open($this->backupRoot.'/work/'.$result['manifest']->getBackupId().'.zip'));$dump=$zip->getFromName('database.sql');$zip->close();self::assertStringContainsString(bin2hex("O'Reilly İstanbul"),$dump);self::assertStringContainsString('INSERT INTO `backup_source`',$dump);self::assertSame("O'Reilly İstanbul",$this->db->selectOne('SELECT value FROM backup_source')['value']);
    }
    public function testSnapshotAdmissionPreservesCallerTransactionAndDoesNoDatabaseWork(): void
    {
        $this->db->statement('CREATE TABLE backup_marker (id INT PRIMARY KEY)');$this->db->getPdo()->beginTransaction();
        try{$this->db->insert('backup_marker',['id'=>1]);$this->db->enableQueryLog();$this->db->flushQueryLog();
            try{(new EnterpriseBackupService($this->backupRoot.'/work',$this->db))->createBackup(BackupScope::DATABASE,new LocalBackupStorageAdapter($this->backupRoot.'/store'));self::fail('Unsupported dump admitted');}catch(\RuntimeException){self::assertTrue($this->db->getPdo()->inTransaction());self::assertCount(0,$this->db->getQueryLog());}
        }finally{$this->db->getPdo()->rollBack();}self::assertCount(0,$this->db->select('SELECT * FROM backup_marker'));
    }
    public function testFilesOnlyScopeDoesNotMisrepresentMariaDbAsIncluded(): void
    {
        $store=new LocalBackupStorageAdapter($this->backupRoot.'/store');$result=(new EnterpriseBackupService($this->backupRoot.'/work',$this->db))->createBackup(BackupScope::FILES,$store,[$this->backupRoot.'/source.txt']);self::assertTrue($result['verified']);self::assertSame(['files/source.txt'],array_keys($result['manifest']->getContainedFiles()));$id=$result['manifest']->getBackupId();self::assertTrue($store->retrieve($id,$this->backupRoot.'/readback'));$zip=new \ZipArchive();self::assertTrue($zip->open($this->backupRoot.'/readback/'.$id.'.zip',\ZipArchive::RDONLY));self::assertFalse($zip->getFromName('database.sql'));$zip->close();
    }
}
