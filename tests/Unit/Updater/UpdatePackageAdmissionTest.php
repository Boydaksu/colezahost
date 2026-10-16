<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Updater;

use Coleza\Domain\Backup\{BackupFileTree,PreUpdateBackupService};
use Coleza\Domain\Release\ReleasePackagingService;
use Coleza\Domain\Updater\{ModuleCompatibilityChecker,PackageSignatureVerifier,StagedUpdateService};
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class UpdatePackageAdmissionTest extends TestCase
{
    private string $root;
    private Connection $db;
    private array $keys;
    protected function setUp(): void
    {
        $this->root=sys_get_temp_dir().'/coleza_update_admission_'.bin2hex(random_bytes(12));mkdir($this->root,0700);
        $this->db=new Connection(new PDO('sqlite::memory:'),'sqlite');(new \Coleza\Domain\Module\ModuleLifecycleService($this->db))->ensureTable();
        $this->keys=PackageSignatureVerifier::generateKeyPair();file_put_contents($this->root.'/source.txt','new');
        mkdir($this->root.'/app');file_put_contents($this->root.'/app/version.txt','old');
    }
    protected function tearDown(): void {BackupFileTree::removeOwned($this->root);}
    private function package(): string
    {
        return (new ReleasePackagingService($this->root.'/release'))->buildPackage(['version.txt'=>$this->root.'/source.txt'],'1.1.0','signed release notes',$this->keys['private'])->getArchivePath();
    }
    private function updater(?\Closure $checkpoint=null): StagedUpdateService
    {
        return new StagedUpdateService(new PackageSignatureVerifier($this->keys['public']),new ModuleCompatibilityChecker($this->db),'1.0.0',$this->db,new PreUpdateBackupService($this->root.'/backup',$this->db),$checkpoint);
    }
    public function testDistributionArchiveIsDirectlyApplicableAndBacksUpOldTarget(): void
    {
        $path=$this->package();self::assertTrue($this->updater()->validateStagedPackage($path)->isReadyToApply());
        $result=$this->updater()->applyValidatedUpdate($path,$this->root.'/app');
        self::assertSame('new',file_get_contents($this->root.'/app/version.txt'));self::assertSame(0,$result['migrations_run']);
        $zip=new ZipArchive();self::assertTrue($zip->open($this->root.'/backup/'.$result['backup_id'].'.zip'));
        self::assertSame('old',$zip->getFromName('files/version.txt'));$zip->close();
    }
    public static function attacks(): array
    {
        return [['../escaped.txt','escape',false],['files/VERSION.txt','case collision',false],['files/extra.php','unlisted',false],['files/link','symlink',true],['files/version.txt/child','collision',false],['manifest.json/child','collision',false],['RELEASE_NOTES.md','unsigned replacement',false],['manifest.sig',str_repeat('x',16385),false]];
    }
    #[DataProvider('attacks')]
    public function testHostileArchiveNeverMutatesTargetOrEscapesQuarantine(string $name,string $bytes,bool $symlink): void
    {
        $path=$this->package();$zip=new ZipArchive();self::assertTrue($zip->open($path));$zip->addFromString($name,$bytes);
        if($symlink){$zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,0120777<<16);}$zip->close();
        $report=$this->updater()->validateStagedPackage($path);self::assertFalse($report->isReadyToApply());
        self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));self::assertFileDoesNotExist($this->root.'/escaped.txt');
    }
    public function testDeclaredZipBombSizeRejectedBeforeStreaming(): void
    {
        $path=$this->package();$raw=file_get_contents($path);$offset=strpos($raw,"PK\x01\x02");self::assertNotFalse($offset);
        $raw=substr_replace($raw,pack('V',1073741825),$offset+24,4);file_put_contents($path,$raw);
        self::assertFalse($this->updater()->validateStagedPackage($path)->isReadyToApply());
    }
    public function testTamperedBackupIsRecheckedBeforeFirstWrite(): void
    {
        $path=$this->package();$updater=$this->updater(function(string $stage): void {
            if($stage==='backup_verified'){$files=glob($this->root.'/backup/*.zip');file_put_contents($files[0],'tamper',FILE_APPEND);}
        });
        try{$updater->applyValidatedUpdate($path,$this->root.'/app');self::fail('Changed backup admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
    public function testKnownDestinationCollisionStopsBeforeAnyFileWrite(): void
    {
        $path=$this->package();mkdir($this->root.'/app/version.txt.directory');rename($this->root.'/app/version.txt',$this->root.'/app/keep.txt');mkdir($this->root.'/app/version.txt');
        try{$this->updater()->applyValidatedUpdate($path,$this->root.'/app');self::fail('Destination collision admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/keep.txt'));self::assertDirectoryExists($this->root.'/app/version.txt');self::assertSame([],glob($this->root.'/backup/*.manifest.json'));}
    }
    public function testMalformedEnabledModuleCannotBeSilentlyIgnored(): void
    {
        $this->db->insert('installed_modules',['module_id'=>'broken','name'=>'Broken','version'=>'1.0.0','type'=>'integration','is_enabled'=>1,'manifest'=>'{invalid']);
        self::assertFalse($this->updater()->validateStagedPackage($this->package())->isReadyToApply());
    }
    public function testDisabledRequiredModuleIsNotAvailable(): void
    {
        $this->db->insert('installed_modules',['module_id'=>'disabled','name'=>'Disabled','version'=>'1.0.0','type'=>'integration','is_enabled'=>0,'manifest'=>json_encode(['min_core_version'=>'1.0.0'])]);
        $path=(new ReleasePackagingService($this->root.'/release'))->buildPackage(['version.txt'=>$this->root.'/source.txt'],'1.1.0','notes',$this->keys['private'],requiredModules:['disabled'=>'1.0.0'])->getArchivePath();
        self::assertFalse($this->updater()->validateStagedPackage($path)->isReadyToApply());
    }
    public function testUnknownSignatureSchemeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);new PackageSignatureVerifier($this->keys['public'],'unknown');
    }
    public function testExtraSourcesCannotReplaceAutomaticApplicationSnapshot(): void
    {
        $result=$this->updater()->applyValidatedUpdate($this->package(),$this->root.'/app',backupExtraFiles:['version.txt'=>$this->root.'/source.txt']);
        $zip=new ZipArchive();$zip->open($this->root.'/backup/'.$result['backup_id'].'.zip');self::assertSame('old',$zip->getFromName('files/version.txt'));self::assertSame('new',$zip->getFromName('files/_update_extra/version.txt'));$zip->close();
    }
    public function testBase64Ed25519KeysDoNotTriggerHexDecoderWarnings(): void
    {
        $public=base64_encode(hex2bin($this->keys['public']));$private=base64_encode(hex2bin($this->keys['private']));
        $sig=PackageSignatureVerifier::signPayload('payload',$private);
        self::assertTrue((new PackageSignatureVerifier($public))->verify('payload',$sig));
    }
    public function testRsaRoundTripAndTamperRejection(): void
    {
        $config=$this->root.'/openssl.cnf';file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n");
        $key=openssl_pkey_new(['config'=>$config,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);self::assertNotFalse($key);
        $private='';self::assertTrue(openssl_pkey_export($key,$private,null,['config'=>$config]));$verifier=new PackageSignatureVerifier(openssl_pkey_get_details($key)['key'],'rsa-sha256');
        $sig=PackageSignatureVerifier::signPayload('payload',$private,'rsa-sha256');
        self::assertTrue($verifier->verify('payload',$sig));self::assertFalse($verifier->verify('changed',$sig));
    }
    public function testRsaSchemeCannotSilentlyAcceptEcKeySignatures(): void
    {
        $config=$this->root.'/openssl.cnf';file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n");
        $key=openssl_pkey_new(['config'=>$config,'private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);self::assertNotFalse($key);
        $public=openssl_pkey_get_details($key)['key'];$signature='';openssl_sign('payload',$signature,$key,OPENSSL_ALGO_SHA256);
        self::assertFalse((new PackageSignatureVerifier($public,'rsa-sha256'))->verify('payload',base64_encode($signature)));
    }

    public function testNewIncompatibleModuleAfterBackupStopsApplication(): void
    {
        $updater=$this->updater(function(string $stage): void {
            if($stage==='backup_verified'){$this->db->insert('installed_modules',['module_id'=>'late','name'=>'Late','version'=>'1.0.0','type'=>'integration','is_enabled'=>1,'manifest'=>json_encode(['max_core_version'=>'1.0.0'])]);}
        });
        try{$updater->applyValidatedUpdate($this->package(),$this->root.'/app');self::fail('New incompatible module admitted.');}
        catch(RuntimeException){self::assertSame('old',file_get_contents($this->root.'/app/version.txt'));}
    }
    public static function invalidOptionalFields(): array {return [['required_modules'],['migrations'],['release_notes'],['released_at']];}
    #[DataProvider('invalidOptionalFields')]
    public function testExplicitNullCannotEraseConstraints(string $field): void
    {
        $data=['version'=>'1.1.0','min_current_version'=>'1.0.0','file_checksums'=>['version.txt'=>hash('sha256','new')],$field=>null];
        $this->expectException(RuntimeException::class);\Coleza\Domain\Updater\UpdatePackageManifest::fromArray($data);
    }
    public function testCaseCollidingParentNamesCannotProduceARelease(): void
    {
        $this->expectException(RuntimeException::class);
        (new ReleasePackagingService($this->root.'/release'))->buildPackage(['A/one.txt'=>$this->root.'/source.txt','a/two.txt'=>$this->root.'/source.txt'],'1.1.0','notes',$this->keys['private']);
    }

}
