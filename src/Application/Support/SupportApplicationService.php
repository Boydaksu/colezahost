<?php
declare(strict_types=1);
namespace Coleza\Application\Support;
use Coleza\Application\Identity\{AuthenticationService,CommandAuthorizer};
use Coleza\Domain\Identity\Impersonation\ImpersonationService;
use Coleza\Domain\Identity\Session\SessionRevocationSchema;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Permissions\{SupportUserContext,TicketPermissionService};
use Coleza\Domain\Support\Tickets\{TicketService,Ticket};
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\{AuthorizationException,ResourceNotFoundException};
/** Current database context only: caller-supplied staff, department and organization roles are ignored. */
final class SupportApplicationService
{
    private TicketService $tickets;
    private DepartmentService $departments;
    private RbacService $rbac;
    private CommandAuthorizer $authorization;
    private TicketPermissionService $policy;
    public function __construct(private Connection $db,private AuthenticationService $authentication,private ImpersonationService $impersonation)
    {
        if(!$authentication->usesConnection($db)||!$impersonation->usesConnection($db)){throw new \InvalidArgumentException('Shared connection required.');}
        $this->departments=new DepartmentService($db);$this->tickets=new TicketService($db,$this->departments);
        $this->rbac=new RbacService($db);$this->authorization=new CommandAuthorizer($db);$this->policy=new TicketPermissionService();
    }
    public function prepare(): void{$this->authentication->prepare();$this->departments->ensureTables();$this->tickets->ensureTables();}
    public function show(string $session,int $ticketId): Ticket
    {
        return $this->execute($session,'tickets.view',function(int $actor)use($ticketId):Ticket{$ticket=$this->ticket($ticketId);$this->policy->assertCanView($ticket,$this->context($actor,$ticket));return $ticket;});
    }
    public function conversation(string $session,int $ticketId): array
    {
        return $this->execute($session,'tickets.view',function(int $actor)use($ticketId):array{$ticket=$this->ticket($ticketId);$context=$this->context($actor,$ticket);$this->policy->assertCanView($ticket,$context);return $this->tickets->getConversation($ticketId,$context->isStaff());});
    }
    public function list(string $session,array $filters=[]): array
    {
        return $this->execute($session,'tickets.view',function(int $actor)use($filters):array{
            $filters=array_intersect_key($filters,array_flip(['status','priority','department_id']));
            return array_values(array_filter($this->tickets->listTickets($filters),function(Ticket $ticket)use($actor):bool{
                try{return $this->policy->canView($ticket,$this->context($actor,$ticket));}catch(AuthorizationException){return false;}
            }));
        });
    }
    public function create(string $session,array $data,string $csrf): Ticket
    {
        return $this->execute($session,'tickets.create',function(int $actor)use($data):Ticket{
            $safe=array_intersect_key($data,array_flip(['department_id','organization_id','subject','message','priority']));$safe['user_id']=$actor;
            $department=$this->departments->requireDepartment((int)($safe['department_id']??0));
            if(!$department->isActive()||!$department->isPublic()){throw new AuthorizationException('Public active support department required.');}
            if(isset($safe['organization_id'])&&$this->authorization->membership($actor,(int)$safe['organization_id'])===null){throw new AuthorizationException('Current organization membership required.');}
            return $this->tickets->createTicket($safe);
        },$csrf);
    }
    public function reply(string $session,int $ticketId,string $message,string $csrf): mixed
    {
        return $this->execute($session,'tickets.reply',function(int $actor)use($ticketId,$message):mixed{$ticket=$this->ticket($ticketId);$context=$this->context($actor,$ticket);$this->policy->assertCanReply($ticket,$context);return $this->tickets->addReply($ticketId,$actor,$message,$context->isStaff());},$csrf);
    }
    public function internalNote(string $session,int $ticketId,string $message,string $csrf): mixed
    {
        return $this->execute($session,'tickets.internal_note',function(int $actor)use($ticketId,$message):mixed{$ticket=$this->ticket($ticketId);$context=$this->context($actor,$ticket);if(!$context->isStaff()) {throw new AuthorizationException('Internal notes require staff.');}$this->policy->assertCanReply($ticket,$context);return $this->tickets->addInternalNote($ticketId,$actor,$message);},$csrf);
    }
    public function changeStatus(string $session,int $ticketId,string $status,string $csrf): Ticket
    {
        return $this->execute($session,'tickets.status',function(int $actor)use($ticketId,$status):Ticket{$ticket=$this->ticket($ticketId);$this->policy->assertCanManage($ticket,$this->context($actor,$ticket));return $this->tickets->updateStatus($ticketId,$status,$actor);},$csrf);
    }
    public function assign(string $session,int $ticketId,?int $agent,string $csrf): Ticket
    {
        return $this->execute($session,'tickets.assign',function(int $actor)use($ticketId,$agent):Ticket{$ticket=$this->ticket($ticketId);$context=$this->context($actor,$ticket);if(!$context->isStaff()||!$context->hasPermission('tickets.manage')){throw new AuthorizationException('Staff assignment permission required.');}$this->policy->assertCanManage($ticket,$context);return $this->tickets->assignTicket($ticketId,$agent);},$csrf);
    }
    private function execute(string $session,string $operation,callable $callback,?string $csrf=null): mixed
    {
        $this->prepare();
        return SessionRevocationSchema::run($this->db,function()use($session,$operation,$callback,$csrf):mixed{
            if($csrf!==null){$this->authentication->verifyCsrf($session,$csrf);}
            $this->impersonation->guardOperation($session,$operation);$principal=$this->impersonation->effectiveUser($session);
            $result=$callback($principal['user_id']);
            if($csrf!==null){(new AuditLogger($this->db))->log($principal['user_id'],'command.'.$operation,impersonatorUserId:$principal['impersonator_id']);}
            return $result;
        });
    }
    private function ticket(int $id): Ticket
    {
        $row=$this->db->selectOne('SELECT * FROM support_tickets WHERE id=?'.$this->db->forUpdate(),[$id]);
        if($row===null){throw new ResourceNotFoundException('Ticket not found.');}return Ticket::fromArray($row);
    }
    private function context(int $actor,Ticket $ticket): SupportUserContext
    {
        $this->authorization->actor($actor);
        if($this->rbac->isSystemActor($actor)){
            if($this->rbac->hasPermission($actor,'*')){return SupportUserContext::superAdmin($actor);}
            $membership=$this->db->selectOne('SELECT a.department_id FROM support_department_agents a JOIN support_departments d ON d.id=a.department_id WHERE a.user_id=? AND a.department_id=? AND a.is_active=1 AND d.is_active=1'.$this->db->forUpdate(),[$actor,$ticket->getDepartmentId()]);
            if($membership===null){throw new AuthorizationException('Current department membership required.');}
            $permissions=[];foreach(['tickets.view','tickets.reply','tickets.manage']as$permission){if($this->rbac->hasPermission($actor,$permission)){$permissions[]=$permission;}}
            return SupportUserContext::supportAgent($actor,[$ticket->getDepartmentId()],$permissions);
        }
        $org=$ticket->getOrganizationId();if($org===null){return SupportUserContext::individual($actor);}
        $membership=$this->authorization->membership($actor,$org);if($membership===null){throw new AuthorizationException('Current organization membership required.');}
        return new SupportUserContext($actor,activeOrganizationId:$org,organizationRole:$membership['role'],permissions:$this->rbac->hasPermission($actor,'org.tickets.all',$org)?['org.tickets.all']:[]);
    }
}
