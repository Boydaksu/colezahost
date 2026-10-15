<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity\Impersonation;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Identity\IdentityPersistence;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Session\{DatabaseSessionHandler,SessionRevocationSchema,AuthenticatedSessionVerifier};
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Security\SecretRedactor;
/** Real session owner remains the administrator. Effective customer identity is separately scoped. */
final class ImpersonationService
{
    public const BLOCKED_HIGH_RISK_OPERATIONS=['password.change','two_factor.disable','api_key.create','payment_method.delete','account.delete','vault.read_secret'];
    private const ALLOWED_OPERATIONS=['invoices.view','orders.view','quotes.view','services.view','tickets.view','tickets.reply','tickets.create'];
    public function __construct(private DatabaseSessionHandler $sessionHandler,private RbacService $rbac,private AuditLogger $auditLogger,private ?AuthenticatedSessionVerifier $authentication=null)
    {
        $db=$sessionHandler->connection();
        if(!$rbac->usesConnection($db)||$auditLogger->connection()!==$db||($authentication!==null&&!$authentication->usesConnection($db))){throw new \InvalidArgumentException('Impersonation dependencies must share a connection.');}
    }
    public function usesConnection(\Coleza\Foundation\Database\Connection $db): bool { return $this->sessionHandler->usesConnection($db); }
    public function startImpersonation(string $sessionId,int $adminUserId,int $targetUserId,?string $reason=null,?string $ip=null,?string $userAgent=null,?string $stepUpToken=null): array
    {
        if($adminUserId===$targetUserId||$targetUserId<1||trim((string)$reason)===''||strlen((string)$reason)>500||$stepUpToken===null){$this->deny();}
        $this->prepare();
        return SessionRevocationSchema::run($this->sessionHandler->connection(),function()use($sessionId,$adminUserId,$targetUserId,$reason,$ip,$userAgent,$stepUpToken):array{
            $admin=$this->authentication->sessionUser($sessionId);
            if((int)$admin['id']!==$adminUserId||!$this->rbac->isSystemActor($adminUserId)){$this->deny();}
            $this->rbac->authorize($adminUserId,'users.impersonate');
            $payload=$this->sessionHandler->read($sessionId);
            if(isset($payload['impersonation'])||isset($payload['impersonator_id'])||isset($payload['original_user_id'])){$this->deny();}
            $this->target($targetUserId);
            if($this->rbac->isSystemActor($targetUserId)){$this->deny();}
            $this->authentication->consumeStepUp($sessionId,'users.impersonate:'.$targetUserId,$stepUpToken);
            $payload=$this->sessionHandler->read($sessionId);
            $payload['impersonation']=['admin_id'=>$adminUserId,'target_user_id'=>$targetUserId,'expires_at'=>time()+900,'reason'=>SecretRedactor::text((string)$reason)];
            $payload['impersonator_id']=$adminUserId;$payload['original_user_id']=$adminUserId;
            unset($payload['active_organization_id'],$payload['active_organization_role'],$payload['step_up']);
            $result=$this->rotate($sessionId,$adminUserId,$payload);
            $this->auditLogger->log($targetUserId,'IMPERSONATION_STARTED','user:'.$targetUserId,$adminUserId,$ip,$userAgent,['reason'=>$reason]);
            return $result;
        });
    }
    public function stopImpersonation(string $sessionId,?string $ip=null,?string $userAgent=null): array
    {
        $this->prepare();
        return SessionRevocationSchema::run($this->sessionHandler->connection(),function()use($sessionId,$ip,$userAgent):array{
            $admin=$this->authentication->sessionUser($sessionId);$payload=$this->sessionHandler->read($sessionId);
            $state=$payload['impersonation']??null;
            if(!is_array($state)||($state['admin_id']??null)!==(int)$admin['id']){$this->deny();}
            $target=(int)($state['target_user_id']??0);
            unset($payload['impersonation'],$payload['impersonator_id'],$payload['original_user_id'],$payload['active_organization_id'],$payload['active_organization_role'],$payload['step_up']);
            $result=$this->rotate($sessionId,(int)$admin['id'],$payload);
            $this->auditLogger->log((int)$admin['id'],'IMPERSONATION_STOPPED','user:'.$target,(int)$admin['id'],$ip,$userAgent);
            return $result;
        });
    }
    /** Revalidate real actor, effective customer and current permission on every command. */
    public function effectiveUser(string $sessionId): array
    {
        $this->prepare();
        return SessionRevocationSchema::run($this->sessionHandler->connection(),function()use($sessionId):array{
            $admin=$this->authentication->sessionUser($sessionId);$payload=$this->sessionHandler->read($sessionId);
            if(!isset($payload['impersonation'])){
                if(isset($payload['impersonator_id'])||isset($payload['original_user_id'])){$this->deny();}
                return ['user_id'=>(int)$admin['id'],'impersonator_id'=>null];
            }
            $state=$payload['impersonation'];
            if(!is_array($state)||($state['admin_id']??null)!==(int)$admin['id']||($state['expires_at']??0)<=time()||($payload['impersonator_id']??null)!==(int)$admin['id']||($payload['original_user_id']??null)!==(int)$admin['id']){$this->deny();}
            $this->rbac->authorize((int)$admin['id'],'users.impersonate');
            if(!$this->rbac->isSystemActor((int)$admin['id'])){$this->deny();}
            $target=$this->target((int)($state['target_user_id']??0));
            if($this->rbac->isSystemActor((int)$target['id'])){$this->deny();}
            return ['user_id'=>(int)$target['id'],'impersonator_id'=>(int)$admin['id']];
        });
    }
    public function guardOperation(string $sessionId,string $operation): void
    {
        $principal=$this->effectiveUser($sessionId);
        if($principal['impersonator_id']!==null&&!in_array($operation,self::ALLOWED_OPERATIONS,true)){
            $this->auditLogger->log($principal['user_id'],'IMPERSONATION_OPERATION_BLOCKED',$operation,$principal['impersonator_id'],payload:['attempted_operation'=>$operation]);
            $this->deny();
        }
    }
    private function rotate(string $old,int $owner,array $payload): array
    {
        $id=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));$payload['authentication']['csrf_hash']=hash('sha256',$csrf);
        $this->sessionHandler->destroy($old);$this->sessionHandler->write($id,$payload,$owner);
        return ['session_id'=>$id,'csrf_token'=>$csrf];
    }
    private function prepare(): void
    {
        if($this->authentication===null){$this->deny();}
        $this->authentication->prepare();$this->rbac->prepare();$this->auditLogger->ensureTable();
        IdentityPersistence::installed($this->sessionHandler->connection(),['security_audit_logs'=>['id','actor_user_id','event_type']],installation:false);
    }
    private function target(int $id): array
    {
        $db=$this->sessionHandler->connection();$row=$db->selectOne('SELECT * FROM users WHERE id=?'.$db->forUpdate(),[$id]);
        if($row===null||(int)$row['is_active']!==1||(array_key_exists('status',$row)&&$row['status']!=='active')){$this->deny();}
        return $row;
    }
    private function deny(): never{throw new ValidationException(['impersonation'=>['Current administrator session, target and step-up proof required.']]);}
}
