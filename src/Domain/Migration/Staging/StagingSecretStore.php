<?php
declare(strict_types=1);
namespace Coleza\Domain\Migration\Staging;
use Coleza\Domain\Vault\{ApplicationKey,Encryptor,KeyBinding,SecretStorageUpgrade,VaultReferenceStore,VaultService};
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Security\SecretRedactor;

final class StagingSecretStore
{
    public const TABLE='migration_staging_records';
    public const NAMESPACE='migration-staging';
    public function __construct(private Connection $db,private ?Encryptor $encryptor=null) {}
    public function resolveKey(): Encryptor { return $this->encryptor??=ApplicationKey::encryptor(ApplicationKey::configured()); }
    public static function requirements(): array
    {
        return [self::TABLE=>['id','batch_id','source_system','source_entity_type','source_entity_id','raw_payload_json','status','canonical_entity_type','canonical_payload_json','validation_errors_json','quarantine_reason','target_entity_id','processed_at','created_at']];
    }
    public static function assertOwnerSchema(Connection $db): void
    {
        SecretStorageUpgrade::assertStorage($db,self::requirements());
        \Coleza\Domain\Identity\IdentityPersistence::installed($db,self::requirements(),installation:false);
        $groups=[];
        if ($db->getDriverName()==='mysql') {
            foreach ($db->select('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[self::TABLE]) as $index) {
                if ((int)$index['NON_UNIQUE']===0) { if ($index['SUB_PART']!==null) { throw new \LogicException('Staging requires full unique identities.'); } $groups[$index['INDEX_NAME']][]=$index['COLUMN_NAME']; }
            }
        } else {
            foreach ($db->select('PRAGMA index_list(migration_staging_records)') as $index) {
                if ((int)$index['unique']!==1) { continue; }
                if ((int)$index['partial']!==0) { throw new \LogicException('Staging requires full unique identities.'); }
                $name=str_replace("'","''",$index['name']);$groups[$name]=array_column($db->select("PRAGMA index_info('$name')"),'name');
            }
        }
        $identity=['batch_id','source_entity_type','source_entity_id'];
        if (!in_array($identity,$groups,true)) { throw new \LogicException('Staging requires its canonical source identity.'); }
        foreach ($groups as $columns) { if ($columns!==['id'] && $columns!==$identity) { throw new \LogicException('Unexpected unique identity can redirect staging reservation.'); } }
    }
    public function prepare(): void { $this->resolveKey();VaultReferenceStore::ensureSchema($this->db);self::assertOwnerSchema($this->db); }
    public function guard(): void { KeyBinding::guard($this->db,$this->resolveKey()); }
    public static function key(array $owner,string $column): string
    {
        if ((int)($owner['id']??0)<1 || !in_array($column,['raw_payload_json','canonical_payload_json'],true)) { throw new \InvalidArgumentException('Invalid staging secret owner.'); }
        return hash('sha256',json_encode([self::TABLE,(int)$owner['id'],$owner['batch_id'],$owner['source_system'],$owner['source_entity_type'],$owner['source_entity_id'],$column,$column==='canonical_payload_json'?$owner['canonical_entity_type']:null],JSON_THROW_ON_ERROR));
    }
    public static function decode(#[\SensitiveParameter] string $json): array
    {
        $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \UnexpectedValueException('Staging data must be a JSON array or object.'); } self::assertNoRawCardData($data);return $data;
    }
    public static function encode(#[\SensitiveParameter] array $data): string
    {
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);self::assertNoRawCardData($data);return $json;
    }
    /** Original Security Constitution: raw card data cannot enter migration storage, even encrypted. */
    private static function assertNoRawCardData(array $data): void
    {
        $pending=[$data];
        $forbidden=['pan','cardnumber','creditcardnumber','ccnumber','cardnum','fullcardnumber','primaryaccountnumber','cvv','cvc','cvv2','cvc2','securitycode','cardsecuritycode','cardverificationvalue'];
        while ($pending!==[]) {
            foreach (array_pop($pending) as $name=>$value) {
                $name=strtolower(preg_replace('/[^a-zA-Z0-9]/','',(string)$name)??'');
                if (in_array($name,$forbidden,true) && $value!==null && $value!=='' && $value!==[]) { throw new \RuntimeException('Raw card data is prohibited in migration staging.'); }
                if (is_array($value)) { $pending[]=$value;continue; }
                if (!is_string($value) && !is_int($value)) { continue; }
                if (preg_match_all('/(?<![a-zA-Z0-9])(?:[0-9][ -]?){12,18}[0-9](?![a-zA-Z0-9])/',(string)$value,$matches)) {
                    foreach ($matches[0] as $candidate) {
                        $digits=preg_replace('/[^0-9]/','',$candidate);$sum=0;$double=false;
                        for ($i=strlen($digits)-1;$i>=0;$i--) { $digit=(int)$digits[$i];if ($double) { $digit*=2;if ($digit>9) { $digit-=9; } }$sum+=$digit;$double=!$double; }
                        if ($sum%10===0 && count(array_unique(str_split($digits)))>1) { throw new \RuntimeException('Raw card data is prohibited in migration staging.'); }
                    }
                }
            }
        }
    }
    public static function diagnostics(array $errors,?string $reason,array $raw,?array $canonical,array $additional=[]): array
    {
        return SecretRedactor::diagnostic(['validation_errors'=>$errors,'quarantine_reason'=>$reason],['source'=>$raw,'canonical'=>$canonical,'submitted'=>$additional]);
    }
    public static function validateDiagnostics(array $owner,array $raw,?array $canonical): void
    {
        $errors=$owner['validation_errors_json']===null?[]:self::decode($owner['validation_errors_json']);
        $safe=self::diagnostics($errors,$owner['quarantine_reason'],$raw,$canonical);
        if ($errors!==$safe['validation_errors'] || $owner['quarantine_reason']!==$safe['quarantine_reason']) { throw new \RuntimeException('Staging diagnostics require explicit legacy scrub.'); }
    }
    public function read(array $owner,string $column): ?string
    {
        if ($column==='canonical_payload_json' && $owner[$column]===null) { return null; }
        $plain=(new VaultReferenceStore($this->db,$this->resolveKey()))->read(self::NAMESPACE,self::key($owner,$column),(string)$owner[$column]);self::decode($plain);return $plain;
    }
    public function writeNew(array $owner,string $column,#[\SensitiveParameter] string $plain): string
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Staging owner and Vault require one transaction.'); }
        self::decode($plain);$key=self::key($owner,$column);
        if ($this->db->selectOne('SELECT id FROM vault_secrets WHERE namespace=? AND secret_key=?',[self::NAMESPACE,$key])!==null) { throw new \LogicException('Staging has a conflicting Vault owner; reconcile explicitly.'); }
        return (new VaultReferenceStore($this->db,$this->resolveKey()))->write(self::NAMESPACE,$key,$plain);
    }
    public function replace(array $owner,string $column,#[\SensitiveParameter] string $plain): string
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Staging owner and Vault require one transaction.'); }
        $this->read($owner,$column);self::decode($plain);return (new VaultReferenceStore($this->db,$this->resolveKey()))->write(self::NAMESPACE,self::key($owner,$column),$plain);
    }
    public function delete(array $owner,string $column): void
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Staging owner and Vault require one transaction.'); }
        (new VaultReferenceStore($this->db,$this->resolveKey()))->delete(self::NAMESPACE,self::key($owner,$column));
    }
    public function upgrade(bool $trustedLegacyConfirmed=false): int
    {
        if (!$trustedLegacyConfirmed || $this->db->inTransaction()) { throw new \LogicException('Confirmed standalone maintenance with stopped writers is required.'); }
        $this->resolveKey();self::assertOwnerSchema($this->db);
        SecretStorageUpgrade::assertStorage($this->db,VaultService::schemaRequirements()+['security_audit_logs'=>['id','actor_user_id','event_type','target_resource','payload']]);
        return $this->db->transaction(function(): int {
            $this->guard();$plans=[];
            foreach ($this->db->select('SELECT * FROM migration_staging_records ORDER BY id'.$this->db->forUpdate()) as $row) {
                $plain=[];$columns=[];
                foreach (['raw_payload_json','canonical_payload_json'] as $column) {
                    $value=$row[$column];if ($column==='canonical_payload_json' && $value===null) { $plain[$column]=null;continue; }
                    if (str_starts_with($value,'vault:')) { $plain[$column]=$this->read($row,$column); }
                    else { self::decode($value);$plain[$column]=$value;$columns[]=$column; }
                }
                $errors=$row['validation_errors_json']===null?[]:self::decode($row['validation_errors_json']);
                $safe=self::diagnostics($errors,$row['quarantine_reason'],self::decode($plain['raw_payload_json']),$plain['canonical_payload_json']===null?null:self::decode($plain['canonical_payload_json']));
                $data=['validation_errors_json'=>self::encode($safe['validation_errors']),'quarantine_reason'=>$safe['quarantine_reason']];
                if ($columns!==[] || $data['validation_errors_json']!==$row['validation_errors_json'] || $data['quarantine_reason']!==$row['quarantine_reason']) { $plans[]=[$row,$plain,$columns,$data]; }
            }
            foreach ($plans as [$row,$plain,$columns,$data]) {
                foreach ($columns as $column) { $data[$column]=$this->writeNew($row,$column,$plain[$column]); }
                $this->db->update(self::TABLE,$data,'id=:id',['id'=>$row['id']]);
            }
            return count($plans);
        });
    }
}
