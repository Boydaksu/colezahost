<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait ImpersonationProofFixture
{
    private function administratorFixture(): array
    {
        $sessions=new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db);
        $auth=new \Coleza\Domain\Identity\Auth\AuthService($this->db,new \Coleza\Domain\Identity\Security\PasswordHasher(),$sessions);$auth->ensureTables();
        foreach([1=>'admin',2=>'alice',3=>'bob']as$id=>$name){if($this->db->selectOne('SELECT id FROM users WHERE id=?',[$id])===null){$this->db->insert('users',['id'=>$id,'email'=>$name.'@example.test','password_hash'=>password_hash('Original123!',PASSWORD_BCRYPT,['cost'=>4]),'name'=>$name,'is_active'=>1]);}}
        $rbac=new \Coleza\Domain\Identity\Rbac\RbacService($this->db);$role=$rbac->findOrCreateRole('operator');
        foreach(['users.impersonate','roles.manage','invoices.view','payments.refund','tickets.view','tickets.reply','tickets.manage']as$permission){$rbac->grantPermission($role,$permission);}$rbac->assignRole(1,'operator');
        $audit=new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);
        $interactive=new \Coleza\Application\Identity\AuthenticationService($this->db,SecretFixtureKey::encryptor());
        $pending=$interactive->beginLogin('admin@example.test','Original123!','127.0.0.1');$setup=$interactive->beginEnrollment($pending['challenge']);
        $login=$interactive->confirmEnrollment($pending['challenge'],(new \Coleza\Domain\Identity\TwoFactor\TotpEngine())->generateCode($setup['secret']));
        $impersonation=new \Coleza\Domain\Identity\Impersonation\ImpersonationService($sessions,$rbac,$audit,$interactive);
        return [$interactive,$impersonation,$login,$rbac,$sessions,$audit];
    }
    private function impersonationProof($interactive,array $login,int $target=2,int $code=0): string
    {
        $operation='users.impersonate:'.$target;$proof=$interactive->beginStepUp($login['session_id'],$operation);
        $interactive->proveStepUp($login['session_id'],$operation,$proof,'Original123!',$login['recovery_codes'][$code],true);return $proof;
    }
}
