<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Updater;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService};
use Coleza\Domain\Updater\{ModuleCompatibilityChecker,PackageSignatureVerifier,StagedUpdateService,UpdatePackageManifest};
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SignedUpdateSafetyTest extends TestCase
{
    private string $root;
    private Connection $db;
    private array $keys;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/coleza_signed_update_'.bin2hex(random_bytes(12));
        mkdir($this->root.'/package/files',0700,true);mkdir($this->root.'/app');
        file_put_contents($this->root.'/package/files/version.txt','new');file_put_contents($this->root.'/app/version.txt','old');
        $this->db=new Connection(new PDO('sqlite::memory:'),'sqlite');
        (new \Coleza\Domain\Module\ModuleLifecycleService($this->db))->ensureTable();
        $this->keys=PackageSignatureVerifier::generateKeyPair();$this->sign();
    }
    protected function tearDown(): void {BackupFileTree::removeOwned($this->root);}
    private function sign(array $changes=[]): void
    {
        $data=(new UpdatePackageManifest('1.1.0','1.0.0','notes',['version.txt'=>hash('sha256','new')]))->toArray();
        $json=json_encode(array_replace($data,$changes),JSON_THROW_ON_ERROR);
        file_put_contents($this->root.'/package/manifest.json',$json);
        file_put_contents($this->root.'/package/manifest.sig',PackageSignatureVerifier::signPayload($json,$this->keys['private']));
    }
    private function updater(?PreUpdateBackupService $backup=null,?\Closure $checkpoint=null): StagedUpdateService
    {
        return new StagedUpdateService(new PackageSignatureVerifier($this->keys['public']),new ModuleCompatibilityChecker($this->db),'1.0.0',$this->db,$backup??new PreUpdateBackupService($this->root.'/backups',$this->db),$checkpoint);
    }
    public static function malformed(): array
    {
        return [
            'empty inventory'=>[['file_checksums'=>[]]],
            'wrong inventory type'=>[['file_checksums'=>'ignored']],
            'non-string hash'=>[['file_checksums'=>['version.txt'=>123]]],
            'unsafe version'=>[['version'=>'../1.1.0']],
            'downgrade'=>[['version'=>'0.9.0']],
            'same version'=>[['version'=>'1.0.0']],
            'prerequisite too new'=>[['min_current_version'=>'1.2.0']],
            'traversal'=>[['file_checksums'=>['../outside.txt'=>hash('sha256','outside')]]],
            'absolute path'=>[['file_checksums'=>['/version.txt'=>hash('sha256','new')]]],
            'backslash'=>[['file_checksums'=>['foo\\version.txt'=>hash('sha256','new')]]],
            'case collision'=>[['file_checksums'=>['version.txt'=>hash('sha256','new'),'VERSION.txt'=>hash('sha256','new')]]],
            'unhashed migration'=>[['migrations'=>['database/migrations/unlisted.php']]],
            'missing required module'=>[['required_modules'=>['absent'=>'1.0.0']]],
        ];
    }
    #[DataProvider('malformed')]
    public function testMalformedPackageCannotBeReady(array $changes): void
    {
        if(isset($changes['file_checksums']['../outside.txt'])){file_put_contents($this->root.'/package/outside.txt','outside');}
        $this->sign($changes);
        self::assertFalse($this->updater()->validateStagedPackage($this->root.'/package')->isReadyToApply());
    }
    public function testUnlistedPayloadIsRejected(): void
    {
        file_put_contents($this->root.'/package/files/extra.php','unlisted');
        self::assertFalse($this->updater()->validateStagedPackage($this->root.'/package')->isReadyToApply());
    }
    public function testDuplicateJsonKeysAreRejected(): void
    {
        $path=$this->root.'/package/manifest.json';$raw=file_get_contents($path);$raw=substr($raw,0,-1).',"version":"1.1.0"}';
        file_put_contents($path,$raw);file_put_contents($this->root.'/package/manifest.sig',PackageSignatureVerifier::signPayload($raw,$this->keys['private']));
        self::assertFalse($this->updater()->validateStagedPackage($this->root.'/package')->isReadyToApply());
    }
    public function testBackupCannotBeBypassed(): void
    {
        try{$this->updater()->applyValidatedUpdate($this->root.'/package',$this->root.'/app',false);self::fail('Backup bypass admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
    public function testBackupMustUseTheUpdaterDatabase(): void
    {
        $wrong=new Connection(new PDO('sqlite::memory:'),'sqlite');
        try{$this->updater(new PreUpdateBackupService($this->root.'/backups',$wrong))->applyValidatedUpdate($this->root.'/package',$this->root.'/app');self::fail('Wrong DB admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
    public function testBackupSourceVersionMustMatch(): void
    {
        try{$this->updater(new PreUpdateBackupService($this->root.'/backups',$this->db,'8.0.0'))->applyValidatedUpdate($this->root.'/package',$this->root.'/app');self::fail('Wrong version admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
    public function testAutomaticBackupIncludesExistingTargetTree(): void
    {
        mkdir($this->root.'/app/config');file_put_contents($this->root.'/app/config/private.php','old configuration');
        $result=$this->updater()->applyValidatedUpdate($this->root.'/package',$this->root.'/app');
        $data=json_decode(file_get_contents($this->root.'/backups/'.$result['backup_id'].'.manifest.json'),true);
        self::assertSame(hash('sha256','old'),$data['contained_files']['files/version.txt']??null);
        self::assertSame(hash('sha256','old configuration'),$data['contained_files']['files/config/private.php']??null);
    }
    public function testSourceMutationAfterFreezeCannotChangeAppliedBytesOrManifest(): void
    {
        $triggered=false;
        $updater=$this->updater(checkpoint:function(string $stage)use(&$triggered): void {
            if($stage==='package_frozen'){$triggered=true;file_put_contents($this->root.'/package/files/version.txt','malicious');$this->sign(['version'=>'9.9.9']);}
        });
        $result=$updater->applyValidatedUpdate($this->root.'/package',$this->root.'/app');
        self::assertTrue($triggered);self::assertSame('malicious',file_get_contents($this->root.'/package/files/version.txt'));
        self::assertSame('1.1.0',$result['target_version']);self::assertSame('new',file_get_contents($this->root.'/app/version.txt'));
    }
    public function testTargetChangeDuringBackupStopsBeforeWriting(): void
    {
        $updater=$this->updater(checkpoint:function(string $stage): void {if($stage==='backup_verified'){file_put_contents($this->root.'/app/version.txt','concurrent writer');}});
        try{$updater->applyValidatedUpdate($this->root.'/package',$this->root.'/app');self::fail('Stale backup admitted.');}
        catch(RuntimeException){self::assertSame('concurrent writer',file_get_contents($this->root.'/app/version.txt'));}
    }
    public function testBackupStorageInsideTargetIsRejected(): void
    {
        try{$this->updater(new PreUpdateBackupService($this->root.'/app/backups',$this->db))->applyValidatedUpdate($this->root.'/package',$this->root.'/app');self::fail('Unsafe backup root admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
    public function testMigrationCannotBeCountedWithoutExecution(): void
    {
        mkdir($this->root.'/package/files/database');file_put_contents($this->root.'/package/files/database/update.php','<?php throw new RuntimeException("not implemented");');
        $this->sign(['file_checksums'=>['version.txt'=>hash('sha256','new'),'database/update.php'=>hash_file('sha256',$this->root.'/package/files/database/update.php')],'migrations'=>['database/update.php']]);
        try{$this->updater()->applyValidatedUpdate($this->root.'/package',$this->root.'/app');self::fail('Fake migration success.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
}
