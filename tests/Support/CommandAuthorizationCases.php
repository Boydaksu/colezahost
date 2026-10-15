<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Foundation\Exceptions\{AuthorizationException,ValidationException};
trait CommandAuthorizationCases
{
    use ImpersonationProofFixture;
    private function commandFixture(): array
    {
        $sessions=new DatabaseSessionHandler($this->db);
        $auth=new \Coleza\Domain\Identity\Auth\AuthService($this->db,new \Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT,['cost'=>4]),$sessions);
        foreach(['admin','alice','bob']as$name){$auth->register($name.'@example.test','Original123!',$name);}
        $rbac=new RbacService($this->db);$rbac->ensureTables();$audit=new AuditLogger($this->db);$audit->ensureTable();
        $orders=new \Coleza\Domain\Commerce\Orders\OrderService($this->db);$orders->ensureTables();
        $invoices=new \Coleza\Domain\Commerce\Invoices\InvoiceService($this->db);$invoices->ensureTables();
        $invoice=$invoices->createInvoice(['user_id'=>2,'currency_code'=>'TRY'],[['description'=>'Fixture','unit_amount_minor'=>1000]]);
        return [$sessions,$rbac,$audit,$orders,$invoices,$invoice];
    }
    public function testAnonymousInvoiceReadIsDenied(): void
    {
        [, , , ,$invoices,$invoice]=$this->commandFixture();
        $this->expectException(AuthorizationException::class);
        (new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices))->getInvoice($invoice->getId());
    }
    public function testCallerAdminFlagDoesNotAuthorizeForeignInvoice(): void
    {
        [, , , ,$invoices,$invoice]=$this->commandFixture();
        $this->expectException(AuthorizationException::class);
        (new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices))->getInvoice($invoice->getId(),3,true);
    }
    public function testOrganizationWildcardNeverGrantsSystemFinancialPermission(): void
    {
        [, $rbac]=$this->commandFixture();$role=$rbac->findOrCreateRole('unsafe_org','organization');$rbac->grantPermission($role,'*');$rbac->assignRole(2,'unsafe_org',42);
        self::assertFalse($rbac->hasPermission(2,'invoices.manage',42));
    }
    public function testPermissionWildcardRequiresNamespaceBoundary(): void
    {
        $context=new \Coleza\Domain\Support\Permissions\SupportUserContext(2,permissions:['org.tickets.*']);
        self::assertFalse($context->hasPermission('org.ticketsevil.view'));
    }
    public function testImpersonationCannotReassignForeignSession(): void
    {
        [$sessions,$rbac,$audit]=$this->commandFixture();$role=$rbac->findOrCreateRole('superadmin');$rbac->grantPermission($role,'users.impersonate');$rbac->assignRole(1,'superadmin');
        $sessions->write('foreign-session',['marker'=>'preserved'],3);
        $service=new \Coleza\Domain\Identity\Impersonation\ImpersonationService($sessions,$rbac,$audit);
        $this->expectException(ValidationException::class);$service->startImpersonation('foreign-session',1,2,reason:'Investigate');
    }

    public function testCurrentOrganizationMembershipControlsOwnAndColleagueInvoices(): void
    {
        [, , , ,$invoices]=$this->commandFixture();$this->db->statement("UPDATE users SET email_verified_at='2026-01-01 00:00:00'");
        $orgs=new \Coleza\Domain\Identity\Organization\OrganizationService($this->db);$org=$orgs->createOrganization(2,'Alice Org','alice');
        $orgs->acceptInvitation(3,'bob@example.test',$orgs->inviteMember(2,$org,'bob@example.test'));
        $invoice=$invoices->createInvoice(['user_id'=>3,'organization_id'=>$org,'currency_code'=>'TRY'],[['description'=>'Org','unit_amount_minor'=>100]]);
        $app=new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices);self::assertSame($invoice->getId(),$app->getInvoice($invoice->getId(),2)->getId());
        $orgs->removeMember(2,$org,3);
        $this->expectException(AuthorizationException::class);$app->getInvoice($invoice->getId(),3);
    }
    public function testImpersonatedCustomerNeverInheritsAdministrativeInvoicePermission(): void
    {
        [, , , ,$invoices]=$this->commandFixture();[$auth,$impersonation,$login]=$this->administratorFixture();
        $foreign=$invoices->createInvoice(['user_id'=>3,'currency_code'=>'TRY'],[['description'=>'Bob','unit_amount_minor'=>500]]);
        $proof=$this->impersonationProof($auth,$login);$started=$impersonation->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);
        $portal=new \Coleza\Application\Identity\SessionCommerceService($this->db,$auth,$impersonation);
        $this->expectException(AuthorizationException::class);$portal->invoice($started['session_id'],$foreign->getId());
    }
    public function testNestedAndAdministratorTargetsAreDenied(): void
    {
        $this->commandFixture();[$auth,$impersonation,$login,$rbac]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);
        $started=$impersonation->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);
        try{$impersonation->startImpersonation($started['session_id'],1,3,'Nested',stepUpToken:$proof);self::fail('Nested impersonation accepted');}catch(ValidationException){}
        $restored=$impersonation->stopImpersonation($started['session_id']);$login['session_id']=$restored['session_id'];
        $rbac->assignRole(3,'another_operator');$proof=$this->impersonationProof($auth,$login,3,1);
        $this->expectException(ValidationException::class);$impersonation->startImpersonation($login['session_id'],1,3,'Staff target',stepUpToken:$proof);
    }
    public function testAuditFailureRollsBackImpersonationStartAndStopAndPreservesProof(): void
    {
        $this->commandFixture();[$auth,$impersonation,$login, ,$sessions]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);
        foreach(['IMPERSONATION_STARTED','IMPERSONATION_STOPPED']as$event){
            if($event==='IMPERSONATION_STOPPED'){$started=$impersonation->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);}
            $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_impersonation_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW BEGIN IF NEW.event_type = '{$event}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'; END IF; END":"CREATE TRIGGER reject_impersonation_audit BEFORE INSERT ON security_audit_logs WHEN NEW.event_type = '{$event}' BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
            $before=$this->db->select('SELECT * FROM sessions ORDER BY id');$revocations=$this->db->select('SELECT * FROM session_revocations ORDER BY session_id');
            try{if($event==='IMPERSONATION_STARTED'){$impersonation->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);}else{$impersonation->stopImpersonation($started['session_id']);}self::fail('Audit failure swallowed');}catch(\PDOException){self::assertSame($before,$this->db->select('SELECT * FROM sessions ORDER BY id'));self::assertSame($revocations,$this->db->select('SELECT * FROM session_revocations ORDER BY session_id'));}
            $this->db->statement('DROP TRIGGER reject_impersonation_audit');
        }
    }
    public function testImpersonationRespectsRawAndManagedParentRollback(): void
    {
        $this->commandFixture();[$auth,$impersonation,$login]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);
        foreach(['raw','managed']as$mode){
            $before=$this->db->select('SELECT * FROM sessions ORDER BY id');
            $operation=function()use($impersonation,$login,$proof):void{$impersonation->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);self::assertTrue($this->db->getPdo()->inTransaction());throw new \RuntimeException('rollback');};
            if($mode==='raw'){$this->db->getPdo()->beginTransaction();try{$operation();}catch(\RuntimeException $e){self::assertSame('rollback',$e->getMessage());}finally{$this->db->getPdo()->rollBack();}}
            else{try{$this->db->transaction($operation);}catch(\RuntimeException $e){self::assertSame('rollback',$e->getMessage());}}
            self::assertSame($before,$this->db->select('SELECT * FROM sessions ORDER BY id'));
        }
    }
    public function testRevokedImpersonationPermissionAndExpiredTargetAreRechecked(): void
    {
        $this->commandFixture();[$auth,$impersonation,$login]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);
        $started=$impersonation->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);
        $this->db->statement("DELETE FROM role_permissions WHERE permission_name='users.impersonate'");
        $this->expectException(ValidationException::class);$impersonation->effectiveUser($started['session_id']);
    }
    public function testSupportUsesCurrentDepartmentAndHidesInternalNotesFromCustomer(): void
    {
        $this->commandFixture();[$auth,$impersonation,$admin]=$this->administratorFixture();
        $support=new \Coleza\Application\Support\SupportApplicationService($this->db,$auth,$impersonation);$support->prepare();
        $departments=new \Coleza\Domain\Support\Departments\DepartmentService($this->db);$dept=$departments->createDepartment(['name'=>'Technical','is_public'=>true]);
        $alice=$auth->beginLogin('alice@example.test','Original123!','127.0.0.1');$bob=$auth->beginLogin('bob@example.test','Original123!','127.0.0.1');
        $ticket=$support->create($alice['session_id'],['department_id'=>$dept->getId(),'subject'=>'Private','message'=>'Customer message','user_id'=>3,'is_staff'=>true],$alice['csrf_token']);self::assertSame(2,$ticket->getUserId());
        try{$support->show($bob['session_id'],$ticket->getId());self::fail('Other customer ticket visible');}catch(ValidationException){}
        try{$support->show($admin['session_id'],$ticket->getId());self::fail('Unassigned department visible');}catch(AuthorizationException){}
        $departments->assignAgent($dept->getId(),1);$support->internalNote($admin['session_id'],$ticket->getId(),'Staff-only note',$admin['csrf_token']);
        self::assertCount(1,$support->conversation($alice['session_id'],$ticket->getId()));self::assertCount(2,$support->conversation($admin['session_id'],$ticket->getId()));
        $departments->removeAgent($dept->getId(),1);
        $this->expectException(AuthorizationException::class);$support->reply($admin['session_id'],$ticket->getId(),'Must fail',$admin['csrf_token']);
    }
    public function testCustomerCannotWriteInternalNoteOrBypassCsrf(): void
    {
        $this->commandFixture();[$auth,$impersonation]=$this->administratorFixture();$support=new \Coleza\Application\Support\SupportApplicationService($this->db,$auth,$impersonation);$support->prepare();
        $departments=new \Coleza\Domain\Support\Departments\DepartmentService($this->db);$dept=$departments->createDepartment(['name'=>'Public']);$alice=$auth->beginLogin('alice@example.test','Original123!','127.0.0.1');
        $ticket=$support->create($alice['session_id'],['department_id'=>$dept->getId(),'subject'=>'Private','message'=>'Message'],$alice['csrf_token']);
        try{$support->internalNote($alice['session_id'],$ticket->getId(),'Secret',$alice['csrf_token']);self::fail('Customer wrote staff note');}catch(AuthorizationException){}
        $before=$this->db->select('SELECT * FROM support_ticket_messages');
        try{$support->reply($alice['session_id'],$ticket->getId(),'Invalid','bad-csrf');self::fail('CSRF ignored');}catch(ValidationException){self::assertSame($before,$this->db->select('SELECT * FROM support_ticket_messages'));}
    }
    public function testFinanceMutationAuditFailureLeavesNoPartialRows(): void
    {
        [, $rbac, , ,$invoices]=$this->commandFixture();$role=$rbac->findOrCreateRole('billing');$rbac->grantPermission($role,'invoices.manage');$rbac->assignRole(2,'billing');$app=new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices);
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_command_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'":"CREATE TRIGGER reject_command_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        $before=$this->db->select('SELECT * FROM invoices');
        try{$app->createInvoice(new \Coleza\Application\Commerce\Invoices\CreateInvoiceCommand(2,items:[['description'=>'New','unit_amount_minor'=>100]]),2);self::fail('Audit failure swallowed');}catch(\PDOException){self::assertSame($before,$this->db->select('SELECT * FROM invoices'));}
    }
    public function testRoleAdministrationRequiresScopeBoundStepUpAndCurrentPermission(): void
    {
        $this->commandFixture();[$auth,$impersonation,$login,$rbac]=$this->administratorFixture();$rbac->findOrCreateRole('readonly');
        $admin=new \Coleza\Application\Identity\RoleAdministrationService($this->db,$auth,$impersonation);
        $operation='roles.assign:'.hash('sha256',json_encode([2,'readonly',null],JSON_THROW_ON_ERROR));$proof=$auth->beginStepUp($login['session_id'],$operation);$auth->proveStepUp($login['session_id'],$operation,$proof,'Original123!',$login['recovery_codes'][0],true);
        try{$admin->assignRole($login['session_id'],3,'readonly',null,$proof);self::fail('Role proof moved to other target');}catch(ValidationException){}
        $admin->assignRole($login['session_id'],2,'readonly',null,$proof);self::assertTrue($rbac->isSystemActor(2));
        $operation='roles.revoke:'.hash('sha256',json_encode([2,'readonly',null],JSON_THROW_ON_ERROR));$revoke=$auth->beginStepUp($login['session_id'],$operation);$auth->proveStepUp($login['session_id'],$operation,$revoke,'Original123!',$login['recovery_codes'][1],true);
        $admin->revokeRole($login['session_id'],2,'readonly',null,$revoke);self::assertFalse($rbac->isSystemActor(2));
        $this->expectException(ValidationException::class);$admin->assignRole($login['session_id'],3,'readonly',null,$proof);
    }

    public function testSupportReplyAuditFailurePreservesRawAndManagedCaller(): void
    {
        $this->commandFixture();[$auth,$impersonation]=$this->administratorFixture();$support=new \Coleza\Application\Support\SupportApplicationService($this->db,$auth,$impersonation);$support->prepare();
        $departments=new \Coleza\Domain\Support\Departments\DepartmentService($this->db);$dept=$departments->createDepartment(['name'=>'Public']);$alice=$auth->beginLogin('alice@example.test','Original123!','127.0.0.1');
        $ticket=$support->create($alice['session_id'],['department_id'=>$dept->getId(),'subject'=>'Private','message'=>'Message'],$alice['csrf_token']);
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_support_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'":"CREATE TRIGGER reject_support_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        foreach(['raw','managed']as$mode){
            $before=$this->db->select('SELECT * FROM support_tickets');$messages=$this->db->select('SELECT * FROM support_ticket_messages');
            $operation=function()use($support,$alice,$ticket):void{try{$support->reply($alice['session_id'],$ticket->getId(),'Reply',$alice['csrf_token']);self::fail('Audit failure swallowed');}catch(\PDOException){self::assertTrue($this->db->getPdo()->inTransaction());}};
            if($mode==='raw'){$this->db->getPdo()->beginTransaction();try{$operation();}finally{$this->db->getPdo()->rollBack();}}
            else{$this->db->transaction($operation);}
            self::assertSame($before,$this->db->select('SELECT * FROM support_tickets'));self::assertSame($messages,$this->db->select('SELECT * FROM support_ticket_messages'));
        }
    }
}
