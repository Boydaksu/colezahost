<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Application\Provisioning\HostingApplicationService;
use Coleza\Domain\Commerce\Services\{ServiceService,ProviderServiceEntitlement};
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Workflows\{HostingProvisioningWorkflow,HostingLifecycleWorkflow};
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Fraud\Risk\{RiskContext,RiskScoreService};
use Coleza\Domain\Fraud\Review\RiskReviewService;
use PHPUnit\Framework\Attributes\DataProvider;
trait HostingCommandCases
{
    use HostingCommandFixture;
    public function testPaidAuthorizedReviewedCommandsCompleteEntireLifecycle():void
    {
        $f=$this->commandFixture();$id=$f['service']->getId();$this->provisionCommand($f);
        self::assertSame('completed',$f['app']->suspend($id,3,'Fixture suspension')->getStatus());self::assertSame('suspended',$f['services']->findServiceById($id)->getStatus());
        self::assertSame('completed',$f['app']->unsuspend($id,3,'Fixture restoration')->getStatus());self::assertSame('active',$f['services']->findServiceById($id)->getStatus());
        self::assertSame('completed',$f['app']->terminate($id,3,'Fixture termination')->getStatus());self::assertSame('terminated',$f['services']->findServiceById($id)->getStatus());self::assertNull($f['capacity']->findActiveReservationForService($id));
        self::assertCount(4,$this->commandPosts($f));self::assertCount(8,$this->db->select("SELECT * FROM security_audit_logs WHERE event_type LIKE 'hosting.%'"));
    }
    #[DataProvider('deniedFacts')]
    public function testInvalidAdmissionSendsNoProviderRequest(string $fact):void
    {
        $f=$this->commandFixture();$actor=3;
        match($fact){
            'customer'=>$actor=2,
            'inactive_actor'=>$this->db->statement('UPDATE users SET is_active=0 WHERE id=3'),
            'inactive_owner'=>$this->db->statement('UPDATE users SET is_active=0 WHERE id=2'),
            'unpaid'=>$this->db->statement("UPDATE invoices SET status='unpaid',paid_amount_minor=0"),
            'partial'=>$this->db->statement('UPDATE invoices SET paid_amount_minor=1'),
            'currency'=>$this->db->statement("UPDATE invoices SET currency_code='USD'"),
            'owner'=>$this->db->statement('UPDATE invoices SET user_id=4'),
            'item'=>$this->db->statement('UPDATE services SET product_id=999'),
            'domain'=>$f['services']->updateService($f['service']->getId(),['domain'=>'other.test']),
            'catalog'=>$this->db->statement("UPDATE products SET metadata_json='{}'"),
            'risk_missing'=>$this->db->statement('DELETE FROM fraud_risk_evaluations'),
            'risk_reject'=>$this->evaluateCommandRisk($f,'reject'),
            'risk_review'=>$this->evaluateCommandRisk($f,'review'),
            'risk_stale'=>$this->db->statement("UPDATE order_items SET product_name='Changed product'"),
        };
        try{$f['app']->provision($f['service']->getId(),$actor);}catch(\RuntimeException|\Coleza\Foundation\Exceptions\AuthorizationException){}
        self::assertSame([],$f['transport']->memory->getRecordedRequests());self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
    public static function deniedFacts():array{return array_map(fn($x)=>[$x],['customer','inactive_actor','inactive_owner','unpaid','partial','currency','owner','item','domain','catalog','risk_missing','risk_reject','risk_review','risk_stale']);}
    public function testApprovedManualRiskReviewCanAdmitCreate():void
    {
        $f=$this->commandFixture();$evaluation=$this->evaluateCommandRisk($f,'review');$review=$f['reviews']->createReview($evaluation);$f['reviews']->approveReview($review->getId(),3,'Verified fixture identity');$this->provisionCommand($f);
    }
    public function testTamperedManualReviewNeverAdmitsCreate():void
    {
        $f=$this->commandFixture();$review=$f['reviews']->createReview($this->evaluateCommandRisk($f,'review'));$f['reviews']->approveReview($review->getId(),3,'Fixture');$this->db->statement("UPDATE fraud_risk_review_decisions SET snapshot_checksum='tampered'");
        try{$f['app']->provision($f['service']->getId(),3);}catch(\RuntimeException){}self::assertSame([],$f['transport']->memory->getRecordedRequests());
    }
    public function testRoleRevokedDuringSecondPackageLookupPreventsCreatePost():void
    {
        $f=$this->commandFixture();$reads=0;$f['transport']->after=function($url)use(&$reads):void{if(str_contains($url,'/listpkgs')&&++$reads===2){$this->db->statement('UPDATE users SET is_active=0 WHERE id=3');}};
        self::assertFalse($f['app']->provision($f['service']->getId(),3)->isSuccess());self::assertSame([],$this->commandPosts($f));self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testRevokedActorAfterCreateHoldsThenOtherStaffRecoversWithoutPost():void
    {
        $f=$this->commandFixture();$f['transport']->after=function($url):void{if(str_contains($url,'/createacct')){$this->db->statement('UPDATE users SET is_active=0 WHERE id=3');}};
        $result=$f['app']->provision($f['service']->getId(),3);self::assertFalse($result->isSuccess());$f['transport']->after=null;
        $probe=new \Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService($f['servers'],$f['registry'],$f['services'],$f['ops']);
        $count=count($f['transport']->memory->getRecordedRequests());self::assertTrue($probe->reconcile($f['ops']->findOperationByUuid($result->getOperationUuid()))->isUnknown());self::assertCount($count,$f['transport']->memory->getRecordedRequests());
        self::assertTrue($f['app']->reconcile($result->getOperationUuid(),4)->isExists());self::assertCount(1,$this->commandPosts($f));self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
    public function testFailedPostconditionHoldsLifecycleAndBlocksEveryNewCommand():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$f['transport']->after=null;$id=$f['service']->getId();$op=$f['app']->suspend($id,3,'Fixture');self::assertSame('reconciliation_required',$op->getStatus());self::assertSame('active',$f['services']->findServiceById($id)->getStatus());
        try{$f['app']->terminate($id,3,'Fixture');self::fail('Second lifecycle sent.');}catch(\RuntimeException){}self::assertFalse($f['app']->provision($id,3)->isSuccess());self::assertCount(2,$this->commandPosts($f));
        $this->commandSummary($f['transport'],1);self::assertSame('completed',$f['app']->reconcile($op->getOperationUuid(),4)->getStatus());self::assertSame('suspended',$f['services']->findServiceById($id)->getStatus());self::assertCount(2,$this->commandPosts($f));
    }
    public function testSuspensionAndTerminationRemainPossibleForUnpaidRejectedInactiveOwner():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$id=$f['service']->getId();$this->db->statement("UPDATE invoices SET status='unpaid',paid_amount_minor=0");$this->evaluateCommandRisk($f,'reject');$this->db->statement('UPDATE users SET is_active=0 WHERE id=2');
        self::assertSame('completed',$f['app']->suspend($id,3,'Contain fixture')->getStatus());
        try{$f['app']->unsuspend($id,3,'Blocked restoration');self::fail('Unpaid owner restored.');}catch(\RuntimeException|\Coleza\Foundation\Exceptions\AuthorizationException){}
        self::assertSame('completed',$f['app']->terminate($id,3,'Remove fixture')->getStatus());self::assertCount(3,$this->commandPosts($f));
    }
    public function testApiErrorCannotProveDeletionOrReleaseAllocation():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$f['transport']->after=function($url)use($f):void{if(str_contains($url,'/removeacct')){$f['transport']->memory->stageResponse('listaccts',200,['metadata'=>['result'=>0,'reason'=>'Denied']]);}};
        $op=$f['app']->terminate($f['service']->getId(),3,'Fixture');self::assertSame('reconciliation_required',$op->getStatus());self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testLateSecurityAuditFailureRollsBackTerminationAndCapacity():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER deny_host_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW BEGIN IF NEW.event_type='hosting.terminate_account.completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'; END IF; END":"CREATE TRIGGER deny_host_audit BEFORE INSERT ON security_audit_logs WHEN NEW.event_type='hosting.terminate_account.completed' BEGIN SELECT RAISE(ABORT,'fixture'); END");
        $op=$f['app']->terminate($f['service']->getId(),3,'Fixture');self::assertSame('reconciliation_required',$op->getStatus());self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertSame('placed',$f['services']->getPlacementForService($f['service']->getId())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    #[DataProvider('lateChanges')]
    public function testChangedCommercialDecisionAfterRemoteCreateCannotActivate(string $change):void
    {
        $f=$this->commandFixture();$f['transport']->after=function($url)use($f,$change):void{
            if(!str_contains($url,'/createacct')){return;}
            match($change){
                'payment'=>$this->db->statement("UPDATE invoices SET status='unpaid',paid_amount_minor=0"),
                'risk'=>$this->evaluateCommandRisk($f,'reject'),
                'catalog'=>$this->db->statement("UPDATE products SET metadata_json='{}'"),
                'permission'=>$this->db->statement("DELETE FROM role_permissions WHERE permission_name='services.provision'"),
            };
        };
        $result=$f['app']->provision($f['service']->getId(),3);self::assertFalse($result->isSuccess());self::assertSame('pending',$f['services']->findServiceById($f['service']->getId())->getStatus());
        self::assertSame('reconciliation_required',$f['ops']->findOperationByUuid($result->getOperationUuid())->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());self::assertCount(1,$this->commandPosts($f));
    }
    public static function lateChanges():array{return [['payment'],['risk'],['catalog'],['permission']];}
    public function testCustomerMetadataCannotSelectProviderPackageOrQuota():void
    {
        $f=$this->commandFixture();$f['services']->updateService($f['service']->getId(),['metadata'=>['commerce_order_unit'=>1,'package_identifier'=>'foreign','hosting'=>['package'=>'foreign','disk_mb'=>999999]]]);$this->provisionCommand($f);
        $post=$this->commandPosts($f)[0];self::assertSame('starter',$post['body_params']['plan']);self::assertSame(10,$post['body_params']['quota']);
    }
    public function testLifecyclePermissionIsCheckedSeparatelyFromProvisionPermission():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$this->db->statement("DELETE FROM role_permissions WHERE permission_name='services.terminate'");$count=count($f['transport']->memory->getRecordedRequests());
        try{$f['app']->terminate($f['service']->getId(),3,'Fixture');self::fail('Missing specific permission accepted.');}catch(\Coleza\Foundation\Exceptions\AuthorizationException){}
        self::assertCount($count,$f['transport']->memory->getRecordedRequests());self::assertSame('completed',$f['app']->suspend($f['service']->getId(),3,'Fixture')->getStatus());
    }
    public function testAuthorityRevokedDuringLifecyclePreflightPreventsPost():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$f['transport']->after=function($url):void{if(str_contains($url,'/accountsummary')){$this->db->statement('UPDATE users SET is_active=0 WHERE id=3');}};
        try{$f['app']->suspend($f['service']->getId(),3,'Fixture');self::fail('Revoked authority accepted.');}catch(\Coleza\Foundation\Exceptions\AuthorizationException){}
        self::assertCount(1,$this->commandPosts($f));self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());
    }
    public function testAuthorityRevokedAfterSuspensionHoldsUntilAuthorizedReadOnlyRecovery():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$original=$f['transport']->after;$f['transport']->after=function($url)use($original):void{$original($url);if(str_contains($url,'/suspendacct')){$this->db->statement('UPDATE users SET is_active=0 WHERE id=3');}};
        $op=$f['app']->suspend($f['service']->getId(),3,'Fixture');self::assertSame('reconciliation_required',$op->getStatus());self::assertSame('active',$f['services']->findServiceById($f['service']->getId())->getStatus());$f['transport']->after=null;
        self::assertSame('completed',$f['app']->reconcile($op->getOperationUuid(),4)->getStatus());self::assertSame('suspended',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertCount(2,$this->commandPosts($f));
    }
    public function testCallerTransactionPreventsApplicationRemoteEffects():void
    {
        $f=$this->commandFixture();$this->db->getPdo()->beginTransaction();try{
            foreach(['provision','suspend','unsuspend','terminate']as$action){try{$action==='provision'?$f['app']->provision($f['service']->getId(),3):$f['app']->$action($f['service']->getId(),3,'Fixture');self::fail('Caller transaction accepted.');}catch(\LogicException){}}
            self::assertSame([],$f['transport']->memory->getRecordedRequests());
        }finally{$this->db->getPdo()->rollBack();}
    }
    public function testPendingManualReviewBlocksEvenAutomatedAccept():void
    {
        $f=$this->commandFixture();$f['reviews']->createReview($this->evaluateCommandRisk($f));try{$f['app']->provision($f['service']->getId(),3);}catch(\RuntimeException){}self::assertSame([],$f['transport']->memory->getRecordedRequests());
    }
    public function testUnprivilegedManualReviewerCannotOverrideRisk():void
    {
        $f=$this->commandFixture();$review=$f['reviews']->createReview($this->evaluateCommandRisk($f,'review'));$f['reviews']->approveReview($review->getId(),2,'Fixture');
        try{$f['app']->provision($f['service']->getId(),3);}catch(\Coleza\Foundation\Exceptions\AuthorizationException){}self::assertSame([],$f['transport']->memory->getRecordedRequests());
    }
    public function testMalformedListingCannotProveDeletion():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$f['transport']->after=function($url)use($f):void{if(str_contains($url,'/removeacct')){$f['transport']->memory->stageResponse('listaccts',200,['metadata'=>['result'=>1],'data'=>['acct'=>[['user'=>'bad user']]]]);}};
        self::assertSame('reconciliation_required',$f['app']->terminate($f['service']->getId(),3,'Fixture')->getStatus());self::assertTrue($f['capacity']->findActiveReservationForService($f['service']->getId())->isCommitted());
    }
    public function testResellerListingCannotAuthorizeDeletion():void
    {
        $f=$this->commandFixture();$this->db->statement('UPDATE servers SET metadata_json=?',[json_encode(['whm_username'=>'reseller'])]);$this->provisionCommand($f);
        try{$f['app']->terminate($f['service']->getId(),3,'Fixture');self::fail('Reseller absence treated as root evidence.');}catch(\RuntimeException){}self::assertCount(1,$this->commandPosts($f));
    }
    public function testHeldTerminationCanRecoverWithoutRepeatingRemove():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$original=$f['transport']->after;$f['transport']->after=function($url)use($original):void{$original($url);if(str_contains($url,'/removeacct')){throw new \RuntimeException('Fixture response lost.');}};
        $op=$f['app']->terminate($f['service']->getId(),3,'Fixture');self::assertSame('reconciliation_required',$op->getStatus());$f['transport']->after=null;
        self::assertSame('completed',$f['app']->reconcile($op->getOperationUuid(),4)->getStatus());self::assertSame('terminated',$f['services']->findServiceById($f['service']->getId())->getStatus());self::assertNull($f['capacity']->findActiveReservationForService($f['service']->getId()));self::assertCount(2,$this->commandPosts($f));
    }

    public function testRefundedPaidInvoiceBlocksProvision():void
    {
        $f=$this->commandFixture();$f['payments']->recordRefund(['payment_id'=>$f['payment']->getId(),'amount_minor'=>100,'reason'=>'Fixture partial refund']);
        try{$f['app']->provision($f['service']->getId(),3);}catch(\RuntimeException){}self::assertSame([],$f['transport']->memory->getRecordedRequests());
    }
    public function testLegacyRiskWithoutIntegerOrderBindingCannotAuthorizeCreate():void
    {
        $f=$this->commandFixture();$f['risk']->evaluate(new RiskContext('order',$f['order']->getId(),2,email:'owner2@example.test',currency:'TRY',isNewCustomer:false));
        try{$f['app']->provision($f['service']->getId(),3);}catch(\RuntimeException){}self::assertSame([],$f['transport']->memory->getRecordedRequests());
    }
    public function testOutstandingRenewalPreventsUnsuspend():void
    {
        $f=$this->commandFixture();$this->provisionCommand($f);$id=$f['service']->getId();self::assertSame('completed',$f['app']->suspend($id,3,'Fixture')->getStatus());
        (new \Coleza\Domain\Commerce\Invoices\InvoiceService($this->db))->createInvoice(['user_id'=>2,'currency_code'=>'TRY'],[['description'=>'Fixture renewal','unit_amount_minor'=>1000,'service_id'=>$id]]);
        $count=count($this->commandPosts($f));try{$f['app']->unsuspend($id,3,'Fixture');self::fail('Unpaid renewal restored.');}catch(\RuntimeException){}self::assertCount($count,$this->commandPosts($f));
    }
    public function testEveryProviderCallRunsOutsideLocalDatabaseTransaction():void
    {
        $f=$this->commandFixture();$original=$f['transport']->after;$f['transport']->after=function($url)use($original):void{self::assertFalse($this->db->getPdo()->inTransaction());$original($url);};$id=$f['service']->getId();$this->provisionCommand($f);
        self::assertSame('completed',$f['app']->suspend($id,3,'Fixture')->getStatus());self::assertSame('completed',$f['app']->unsuspend($id,3,'Fixture')->getStatus());self::assertSame('completed',$f['app']->terminate($id,3,'Fixture')->getStatus());
    }
    public function testOrganizationMembershipMustStillExistAtCommandTime():void
    {
        $f=$this->commandFixture();$orgs=new \Coleza\Domain\Identity\Organization\OrganizationService($this->db);$orgs->ensureTables();$org=$orgs->createOrganization(2,'Fixture organization','fixture-org');
        foreach(['orders','invoices','services']as$table){$this->db->statement('UPDATE '.$table.' SET organization_id=?',[$org]);}$this->evaluateCommandRisk($f);
        $this->db->statement('DELETE FROM organization_members WHERE user_id=2');
        try{$f['app']->provision($f['service']->getId(),3);}catch(\RuntimeException|\Coleza\Foundation\Exceptions\ValidationException){}self::assertSame([],$f['transport']->memory->getRecordedRequests());
    }

}
