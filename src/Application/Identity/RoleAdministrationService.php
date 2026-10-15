<?php
declare(strict_types=1);
namespace Coleza\Application\Identity;
use Coleza\Domain\Identity\Session\SessionRevocationSchema;
use Coleza\Domain\Identity\Impersonation\ImpersonationService;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\AuthorizationException;
final class RoleAdministrationService
{
    public function __construct(private Connection $db,private AuthenticationService $authentication,private ImpersonationService $impersonation){if(!$authentication->usesConnection($db)||!$impersonation->usesConnection($db)){throw new \InvalidArgumentException('Shared connection required.');}}
    public function assignRole(string $session,int $targetUser,string $role,?int $organizationId,string $proof): void
    {
        $this->change($session,$targetUser,$role,$organizationId,$proof,true);
    }
    public function revokeRole(string $session,int $targetUser,string $role,?int $organizationId,string $proof): void
    {
        $this->change($session,$targetUser,$role,$organizationId,$proof,false);
    }
    private function change(string $session,int $targetUser,string $role,?int $organizationId,string $proof,bool $assign): void
    {
        $this->authentication->prepare();
        SessionRevocationSchema::run($this->db,function()use($session,$targetUser,$role,$organizationId,$proof,$assign):void{
            $this->impersonation->guardOperation($session,$assign?'roles.assign':'roles.revoke');$actor=(int)$this->authentication->sessionUser($session)['id'];
            $authorization=new CommandAuthorizer($this->db);$authorization->requireSystem($actor,'roles.manage');$authorization->actor($targetUser);
            $rbac=new RbacService($this->db);
            $row=$this->db->selectOne('SELECT id,scope FROM roles WHERE name=?'.$this->db->forUpdate(),[$role]);
            if($row===null||($organizationId===null?$row['scope']!=='system':$row['scope']!=='organization'||($assign&&$authorization->membership($targetUser,$organizationId)===null))){throw new AuthorizationException('Existing role and current target scope required.');}
            $operation=($assign?'roles.assign:':'roles.revoke:').hash('sha256',json_encode([$targetUser,$role,$organizationId],JSON_THROW_ON_ERROR));
            $this->authentication->consumeStepUp($session,$operation,$proof);
            if($assign){$rbac->assignRole($targetUser,$role,$organizationId);}
            else{$this->db->delete('user_roles','user_id=:user AND role_id=:role AND organization_id=:org',['user'=>$targetUser,'role'=>$row['id'],'org'=>$organizationId??0]);}
            (new AuditLogger($this->db))->log($actor,$assign?'command.role_assigned':'command.role_revoked','user:'.$targetUser,payload:['role'=>$role,'organization_id'=>$organizationId]);
        });
    }
}
