<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class UpdatePublicationTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\UpdatePublicationCases;
    protected function setUp(): void {parent::setUp();$this->preparePublicationFixture();}
    protected function tearDown(): void {if(isset($this->publicationRoot)){\Coleza\Domain\Backup\BackupFileTree::removeOwned($this->publicationRoot);}parent::tearDown();}
    private function canonicalUpdateSecurityFixture(): array
    {
        $this->db->statement("SET SESSION time_zone='+03:00'");
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $path=$this->publicationRoot.'/authoritative.json';file_put_contents($path,'[]');
        $keys=['tombstone'=>\Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(),'email'=>\Coleza\Tests\Support\PrivacyFixtureKeys::email()];
        $store=new \Coleza\Domain\Privacy\Tombstone\FileTombstoneStore($path,$keys['tombstone']);
        $privacy=new \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier($this->db,$keys,$store);$key=\Coleza\Tests\Support\SecretFixtureKey::encryptor();
        (new \Coleza\Domain\Vault\VaultService($this->db,$key,new \Coleza\Domain\Identity\Audit\AuditLogger($this->db)))->setSecret('update:fixture','token','synthetic-update-secret');
        (new \Coleza\Domain\Vault\KeyLifecycleService($this->db,$key,$privacy))->initialize(true);
        $updater=new \Coleza\Domain\Updater\StagedUpdateService(new \Coleza\Domain\Updater\PackageSignatureVerifier($this->publicationKeys['public']),new \Coleza\Domain\Updater\ModuleCompatibilityChecker($this->db),'1.0.0',$this->db,new \Coleza\Domain\Backup\PreUpdateBackupService($this->publicationRoot.'/backup',$this->db),health:new \Coleza\Domain\Updater\UpdateHealthGate($this->db,$key,$privacy));
        return [$updater,$key,$privacy,$store,$keys];
    }
    public function testWrongApplicationKeyStopsUpdateHealthBeforeAnyFilePublication(): void
    {
        [,,$privacy]=$this->canonicalUpdateSecurityFixture();$wrong=new \Coleza\Domain\Vault\Encryptor(hash('sha256','synthetic-wrong-update-key',true));
        $updater=new \Coleza\Domain\Updater\StagedUpdateService(new \Coleza\Domain\Updater\PackageSignatureVerifier($this->publicationKeys['public']),new \Coleza\Domain\Updater\ModuleCompatibilityChecker($this->db),'1.0.0',$this->db,new \Coleza\Domain\Backup\PreUpdateBackupService($this->publicationRoot.'/backup',$this->db),health:new \Coleza\Domain\Updater\UpdateHealthGate($this->db,$wrong,$privacy));
        try{$updater->applyValidatedUpdate($this->publicationRoot.'/package',$this->publicationRoot.'/app');self::fail('Wrong application key admitted.');}catch(\RuntimeException){self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));}
        self::assertSame([],glob($this->publicationRoot.'/.app.previous_*'));
    }
    public function testRollbackReappliesLaterErasureAndCannotBypassApplicationKey(): void
    {
        [$updater,$key,$privacy,$store,$keys]=$this->canonicalUpdateSecurityFixture();
        $this->db->insert('users',['id'=>7,'email'=>'erase-update@example.test','password_hash'=>'synthetic','name'=>'Private','first_name'=>'Private','last_name'=>'User','is_active'=>1]);
        $updater->applyValidatedUpdate($this->publicationRoot.'/package',$this->publicationRoot.'/app');
        (new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db,$store,secretKey:$keys['tombstone'],salt:$keys['email']))->recordTombstone(7,'erase-update@example.test');
        $wrong=new \Coleza\Domain\Vault\Encryptor(hash('sha256','synthetic-wrong-rollback-key',true));
        try{$updater->recoverFailedUpdate($this->publicationRoot.'/app',new \Coleza\Domain\Backup\RestoreWizardService($this->publicationRoot.'/restore',applicationEncryptor:$wrong,privacyVerifier:$privacy));self::fail('Wrong rollback key admitted.');}
        catch(\RuntimeException $error){self::assertStringContainsString('rollback',$error->getMessage());self::assertSame('new',file_get_contents($this->publicationRoot.'/app/version.txt'));}
        self::assertTrue($updater->recoverFailedUpdate($this->publicationRoot.'/app',new \Coleza\Domain\Backup\RestoreWizardService($this->publicationRoot.'/restore',applicationEncryptor:$key,privacyVerifier:$privacy))['success']);
        $user=$this->db->selectOne('SELECT * FROM users WHERE id=7');self::assertSame('erased',$user['status']);self::assertNull($user['first_name']);self::assertSame('ERASED',$user['password_hash']);
        self::assertSame('synthetic-update-secret',(new \Coleza\Domain\Vault\VaultService($this->db,$key))->getSecret('update:fixture','token'));self::assertSame('old',file_get_contents($this->publicationRoot.'/app/version.txt'));
        self::assertSame('+03:00',$this->db->getPdo()->query('SELECT @@time_zone')->fetchColumn());self::assertSame(1,$privacy->verify()['counts']['tombstones']);
    }
}
