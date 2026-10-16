<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Backup\{LogicalDatabaseDump,RestoredDatabaseVerifier};
use Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier;
use Coleza\Domain\Vault\{ApplicationKey,Encryptor,KeyLifecycleService};

/** Local release admission: real SQL/FK/integrity, key and privacy verification, no fake callback success. */
final class UpdateHealthGate
{
    public function __construct(private Connection $db,private ?Encryptor $encryptor=null,private ?PrivacyIntegrityVerifier $privacy=null) {}
    public static function assertPayloadSyntax(UpdatePackageSnapshot $package): void
    {
        foreach($package->manifest->getFileChecksums() as $relative=>$_){
            if(strtolower(pathinfo($relative,PATHINFO_EXTENSION))!=='php'){continue;}
            $path=$package->directory().'/files/'.$relative;
            if(filesize($path)>4*1024*1024){throw new \RuntimeException('PHP update source exceeds the bounded syntax admission limit.');}
            try{token_get_all((string)file_get_contents($path),TOKEN_PARSE);}catch(\ParseError $error){throw new \RuntimeException('Update PHP syntax admission failed.',0,$error);}
        }
    }
    public function verify(Connection $db): void
    {
        if($db->getPdo()!==$this->db->getPdo()||$db->inTransaction()){throw new \RuntimeException('Update health requires the bound standalone database.');}
        (new LogicalDatabaseDump())->assertSupportedObjects($db);
        $tables=[];foreach((new LogicalDatabaseDump())->tables($db) as $table){$tables[$table]=['rows'=>(int)$db->getPdo()->query('SELECT COUNT(*) FROM '.LogicalDatabaseDump::identifier($table))->fetchColumn()];}
        (new RestoredDatabaseVerifier())->verify($db,['format'=>2,'driver'=>$db->getDriverName(),'tables'=>$tables]);
        $privacy=$this->privacy?->forDatabase($db)??new PrivacyIntegrityVerifier($db);
        if($privacy->required()){$privacy->verify();}
        if(KeyLifecycleService::requiresVerification($db)){
            $key=$this->encryptor??ApplicationKey::encryptor(ApplicationKey::configured());(new KeyLifecycleService($db,$key,$privacy))->verify();
        }
    }
}
