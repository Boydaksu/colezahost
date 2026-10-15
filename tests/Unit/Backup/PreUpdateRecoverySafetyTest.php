<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService,RestoreWizardService};
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class PreUpdateRecoverySafetyTest extends TestCase
{
    private string $directory;
    private Connection $db;
    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/coleza_preupdate_'.bin2hex(random_bytes(12));
        mkdir($this->directory,0700);
        $this->db=new Connection(new PDO('sqlite::memory:'),'sqlite');
        $this->db->statement('CREATE TABLE settings(id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT, payload BLOB)');
        $this->db->statement('CREATE UNIQUE INDEX setting_value ON settings(value)');
        $this->db->statement("INSERT INTO settings(value,payload) VALUES (?,?)",["İstanbul's ; \\ 🌍", "\0\xff'\\"]);
    }
    protected function tearDown(): void {BackupFileTree::removeOwned($this->directory);}
    private function service(): PreUpdateBackupService {return new PreUpdateBackupService($this->directory.'/backups',$this->db);}

    public function testDatabaseIsMandatory(): void
    {
        file_put_contents($this->directory.'/config.php','configuration');
        $this->expectException(RuntimeException::class);
        (new PreUpdateBackupService($this->directory.'/backups'))->createVerifiedPreUpdateBackup('1.1.0',[$this->directory.'/config.php']);
    }
    public function testVersionCannotBecomeAPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->createVerifiedPreUpdateBackup('../escape');
    }
    public function testMissingRequestedFileStopsPublication(): void
    {
        try {$this->service()->createVerifiedPreUpdateBackup('1.1.0',[$this->directory.'/missing']);self::fail('Missing source was silently omitted.');}
        catch(RuntimeException) {self::assertSame([],glob($this->directory.'/backups/*.manifest.json'));}
    }
    public function testDuplicateBasenamesCannotOverwriteEachOther(): void
    {
        foreach(['a','b']as$name){mkdir($this->directory.'/'.$name);file_put_contents($this->directory.'/'.$name.'/config.php',$name);}
        $this->expectException(RuntimeException::class);
        $this->service()->createVerifiedPreUpdateBackup('1.1.0',[$this->directory.'/a/config.php',$this->directory.'/b/config.php']);
    }
    public function testRapidBackupsNeverReplaceAnExistingPair(): void
    {
        $first=$this->service()->createVerifiedPreUpdateBackup('1.1.0');$hash=hash_file('sha256',$first['archive_path']);
        $second=$this->service()->createVerifiedPreUpdateBackup('1.1.0');
        self::assertNotSame($first['manifest']->getBackupId(),$second['manifest']->getBackupId());
        self::assertSame($hash,hash_file('sha256',$first['archive_path']));
        self::assertTrue($this->service()->verifyBackup($first['manifest']->getBackupId())->isValid());
    }
    public function testRecursiveFilesAndDatabaseActuallyRestore(): void
    {
        mkdir($this->directory.'/source/empty',0700,true);file_put_contents($this->directory.'/source/config.php',"\0İstanbul'\\");
        $result=$this->service()->createVerifiedPreUpdateBackup('1.1.0',['application'=>$this->directory.'/source']);
        $manifest=$result['manifest'];self::assertSame('pre_update',$manifest->getType());
        self::assertSame(2,$manifest->getMetadata()['database']['format']??null);
        self::assertSame(1,$manifest->getMetadata()['database']['tables']['settings']['rows']??null);
        $target=new Connection(new PDO('sqlite::memory:'),'sqlite');
        $restore=(new RestoreWizardService($this->directory.'/restore'))->executeRestore($manifest->getBackupId(),new LocalBackupStorageAdapter($this->directory.'/backups'),$target,$this->directory.'/target');
        self::assertTrue($restore->isSuccess(),$restore->getErrorMessage()??'');
        self::assertSame($this->db->select('SELECT * FROM settings'),$target->select('SELECT * FROM settings'));
        self::assertSame("\0İstanbul'\\",file_get_contents($this->directory.'/target/application/config.php'));
        self::assertDirectoryExists($this->directory.'/target/application/empty');
        self::assertNotNull($target->selectOne("SELECT name FROM sqlite_master WHERE name='setting_value'"));
    }
    public function testInnerChecksumCannotBeIgnoredEvenWhenOuterHashMatches(): void
    {
        $result=$this->service()->createVerifiedPreUpdateBackup('1.1.0');$id=$result['manifest']->getBackupId();
        $path=$this->directory.'/backups/'.$id.'.manifest.json';$data=json_decode(file_get_contents($path),true);
        $data['contained_files']['database.sql']=str_repeat('0',64);file_put_contents($path,json_encode($data));
        $report=$this->service()->verifyBackup($id);self::assertFalse($report->isValid());self::assertFalse($report->isContentsReadable());
    }
    public function testLegacyCommentOnlyDatabaseIsNotRecoveryEvidence(): void
    {
        $result=$this->service()->createVerifiedPreUpdateBackup('1.1.0');$id=$result['manifest']->getBackupId();
        $zip=new ZipArchive();$zip->open($result['archive_path']);$zip->addFromString('database.sql',"-- Driver: mysql\n");$zip->close();
        $path=$this->directory.'/backups/'.$id.'.manifest.json';$data=json_decode(file_get_contents($path),true);
        $data['sha256_checksum']=hash_file('sha256',$result['archive_path']);clearstatcache();$data['file_size_bytes']=filesize($result['archive_path']);
        $data['contained_files']['database.sql']=hash('sha256',"-- Driver: mysql\n");unset($data['metadata']['database']);file_put_contents($path,json_encode($data));
        self::assertFalse($this->service()->verifyBackup($id)->isValid());
    }
    public function testLookupRejectsTraversalBeforeReadingOutsideStorage(): void
    {
        $report=$this->service()->verifyBackup('../outside');self::assertFalse($report->isValid());self::assertNotEmpty($report->getErrors());
    }
    public function testCommentOnlyDumpCannotBorrowAValidInventory(): void
    {
        $result=$this->service()->createVerifiedPreUpdateBackup('1.1.0');$id=$result['manifest']->getBackupId();
        $zip=new ZipArchive();$zip->open($result['archive_path']);$zip->addFromString('database.sql',"-- empty replacement\n");$zip->close();
        $path=$this->directory.'/backups/'.$id.'.manifest.json';$data=json_decode(file_get_contents($path),true);
        clearstatcache();$data['sha256_checksum']=hash_file('sha256',$result['archive_path']);$data['file_size_bytes']=filesize($result['archive_path']);
        $data['contained_files']['database.sql']=hash('sha256',"-- empty replacement\n");file_put_contents($path,json_encode($data));
        $report=$this->service()->verifyBackup($id);self::assertFalse($report->isValid());self::assertStringContainsString('table/row inventory',implode(' ',$report->getErrors()));
    }
}
