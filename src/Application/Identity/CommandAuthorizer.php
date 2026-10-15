<?php
declare(strict_types=1);
namespace Coleza\Application\Identity;
use Coleza\Domain\Identity\{IdentityPersistence};
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Vault\KeyBinding;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\AuthorizationException;
/** Actor IDs come from authenticated API/application context; caller role flags are never authority. */
final class CommandAuthorizer
{
    private RbacService $rbac;
    public function __construct(private Connection $db) { $this->rbac=new RbacService($db); }
    public function run(?int $actor, callable $operation, ?string $event=null): mixed
    {
        // Read preflight before any schema setup and again under the mutation lock.
        IdentityPersistence::installed($this->db,['users'=>['id','email','is_active']],installation:false);
        $this->actor($actor);
        $audit=new AuditLogger($this->db);
        if ($event!==null) {
            $audit->ensureTable();
            IdentityPersistence::installed($this->db,['security_audit_logs'=>['id','actor_user_id','event_type']],installation:false);
        }
        return IdentityPersistence::run($this->db,function()use($actor,$operation,$audit,$event):mixed{
            $this->actor($actor);$result=$operation();
            if($event!==null){$audit->log($actor,$event);}
            return $result;
        });
    }
    public function actor(?int $id): array
    {
        if($id===null || $id<1 || !KeyBinding::tableExists($this->db,'users')){$this->deny();}
        $user=$this->db->selectOne('SELECT * FROM users WHERE id=?'.$this->db->forUpdate(),[$id]);
        if($user===null || (int)$user['is_active']!==1 || (array_key_exists('status',$user)&&$user['status']!=='active')){$this->deny();}
        return $user;
    }
    public function hasSystemPermission(int $actor,string $permission): bool
    {
        $this->actor($actor);
        if(!KeyBinding::tableExists($this->db,'roles')){return false;}
        return $this->rbac->hasPermission($actor,$permission);
    }
    public function requireSystem(int $actor,string $permission): void {if(!$this->hasSystemPermission($actor,$permission)){$this->deny();}}
    public function resource(int $actor,int $owner,?int $org,string $systemPermission,string $organizationPermission): void
    {
        $this->actor($actor);
        if($this->hasSystemPermission($actor,$systemPermission)){return;}
        if($org!==null){
            $member=$this->membership($actor,$org);
            if($member===null){$this->deny();}
            if($actor===$owner){return;}
            if(in_array($member['role'],['owner','admin'],true)||($member['role']==='billing' && str_starts_with($organizationPermission,'org.billing.'))||(KeyBinding::tableExists($this->db,'roles')&&$this->rbac->hasPermission($actor,$organizationPermission,$org))){return;}
        }elseif($actor===$owner){return;}
        $this->deny();
    }
    public function membership(int $actor,int $org): ?array
    {
        if(!KeyBinding::tableExists($this->db,'organizations')||!KeyBinding::tableExists($this->db,'organization_members')){return null;}
        (new \Coleza\Domain\Identity\Organization\OrganizationService($this->db))->ensureTables(installation:false);
        $row=$this->db->selectOne('SELECT o.*,m.role FROM organizations o JOIN organization_members m ON m.organization_id=o.id WHERE o.id=? AND m.user_id=?'.$this->db->forUpdate(),[$org,$actor]);
        if($row===null || (array_key_exists('status',$row)&&$row['status']!=='active')){return null;}
        if($row['role']==='owner' ? (int)$row['owner_user_id']!==$actor : (int)$row['owner_user_id']===$actor || !in_array($row['role'],['admin','member','billing','technical'],true)){return null;}
        return $row;
    }
    private function deny(): never {throw new AuthorizationException('Current authenticated actor and required administrative staff permission are required.');}
}
