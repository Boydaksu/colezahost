<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService};
use Coleza\Domain\Release\ReleasePackagingService;
use Coleza\Domain\Updater\{ModuleCompatibilityChecker,PackageSignatureVerifier,StagedUpdateService};
use Coleza\Foundation\Database\{Connection,Migrator};
use PDO;
use RuntimeException;

final class SignedUpdateAdmissionTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    private string $rootDir;
    private array $keys;
    protected function setUp(): void
    {
        parent::setUp();$this->rootDir=sys_get_temp_dir().'/coleza_native_signed_update_'.bin2hex(random_bytes(12));mkdir($this->rootDir,0700);
        (new \Coleza\Domain\Module\ModuleLifecycleService($this->db))->ensureTable(true);
        $this->keys=PackageSignatureVerifier::generateKeyPair();mkdir($this->rootDir.'/app');file_put_contents($this->rootDir.'/app/version.txt','old');file_put_contents($this->rootDir.'/source','new');
    }
    protected function tearDown(): void {if(isset($this->rootDir)){BackupFileTree::removeOwned($this->rootDir);}parent::tearDown();}
    private function package(array $required=[]): string
    {
        return (new ReleasePackagingService($this->rootDir.'/release'))->buildPackage(['version.txt'=>$this->rootDir.'/source'],'1.1.0','native update',$this->keys['private'],requiredModules:$required)->getArchivePath();
    }
    private function updater(?PreUpdateBackupService $backup=null): StagedUpdateService
    {
        return new StagedUpdateService(new PackageSignatureVerifier($this->keys['public']),new ModuleCompatibilityChecker($this->db),'1.0.0',$this->db,$backup??new PreUpdateBackupService($this->rootDir.'/backup',$this->db));
    }
    public function testCanonicalDatabaseAndTargetAreReallyBackedUpBeforeApplyingDistributionArchive(): void
    {
        $migrator=new Migrator($this->db);$migrator->migrate(dirname(__DIR__,2).'/database/migrations');
        self::assertCount(26,$migrator->getRanMigrations());
        $result=$this->updater()->applyValidatedUpdate($this->package(),$this->rootDir.'/app');
        self::assertTrue($result['success']);self::assertSame('new',file_get_contents($this->rootDir.'/app/version.txt'));
        $data=json_decode(file_get_contents($this->rootDir.'/backup/'.$result['backup_id'].'.manifest.json'),true);
        self::assertSame('mysql',$data['metadata']['database']['driver']);self::assertSame(26,$data['metadata']['database']['tables']['migrations']['rows']);
        self::assertSame(hash('sha256','old'),$data['contained_files']['files/version.txt']);
    }
    public function testMissingRequiredModuleCannotReachBackupOrCopy(): void
    {
        $path=$this->package(['absent'=>'1.0.0']);self::assertFalse($this->updater()->validateStagedPackage($path)->isReadyToApply());
        try{$this->updater()->applyValidatedUpdate($path,$this->rootDir.'/app');self::fail('Missing module admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->rootDir.'/app/version.txt'));self::assertDirectoryDoesNotExist($this->rootDir.'/backup');}
    }
    public function testAnotherDatabaseConnectionCannotSupplyMandatoryBackup(): void
    {
        $wrong=new Connection(new PDO('sqlite::memory:'),'sqlite');
        try{$this->updater(new PreUpdateBackupService($this->rootDir.'/backup',$wrong))->applyValidatedUpdate($this->package(),$this->rootDir.'/app');self::fail('Wrong database admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->rootDir.'/app/version.txt'));self::assertDirectoryDoesNotExist($this->rootDir.'/backup');}
    }
    public function testCallerTransactionSurvivesRejectedUpdate(): void
    {
        $path=$this->package();$pdo=$this->db->getPdo();$pdo->beginTransaction();
        try{
            try{$this->updater()->applyValidatedUpdate($path,$this->rootDir.'/app');self::fail('Update accepted caller transaction.');}
            catch(RuntimeException){self::assertTrue($pdo->inTransaction());self::assertSame('old',file_get_contents($this->rootDir.'/app/version.txt'));}
        }finally{$pdo->rollBack();}
    }
}
