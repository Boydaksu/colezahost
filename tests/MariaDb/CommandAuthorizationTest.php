<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
use Coleza\Foundation\Exceptions\AuthorizationException;
final class CommandAuthorizationTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\CommandAuthorizationCases;
    public function testFourProcessesConsumeOneImpersonationProofAndRotateOnce(): void
    {
        $this->commandFixture();[$auth, ,$login]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);
        $results=$this->runParallel('commandImpersonate',1,json_encode(['session'=>$login['session_id'],'proof'=>$proof],JSON_THROW_ON_ERROR));
        self::assertCount(1,array_filter($results,static fn(array $r):bool=>($r['accepted']??false)===true));
        foreach($results as$result){self::assertContains($result['status'],['ok','rejected']);}
        self::assertCount(1,$this->db->select("SELECT * FROM security_audit_logs WHERE event_type='IMPERSONATION_STARTED'"));
        self::assertCount(1,$this->db->select('SELECT * FROM sessions WHERE user_id=1'));
    }
    private function peerCommandConnection(): \Coleza\Foundation\Database\Connection
    {
        return new \Coleza\Foundation\Database\Connection(new \PDO((string)getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD')));
    }
    public function testOldSnapshotCannotReuseRevokedFinancialPermission(): void
    {
        [, $rbac, , ,$invoices,$invoice]=$this->commandFixture();$role=$rbac->findOrCreateRole('billing');$rbac->grantPermission($role,'invoices.view');$rbac->assignRole(1,'billing');$peer=$this->peerCommandConnection();
        $this->db->getPdo()->beginTransaction();
        try{
            self::assertCount(1,$this->db->select('SELECT * FROM role_permissions'));
            $peer->statement("DELETE FROM role_permissions WHERE permission_name='invoices.view'");
            try{(new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices))->getInvoice($invoice->getId(),1);self::fail('Old permission authorized');}catch(AuthorizationException){self::assertTrue($this->db->getPdo()->inTransaction());}
        }finally{$this->db->getPdo()->rollBack();}
    }
    public function testInstalledRoleMutationAndAuthorizationExecuteNoDdlInCaller(): void
    {
        [, $rbac]=$this->commandFixture();$rbac->findOrCreateRole('readonly');$this->db->getPdo()->beginTransaction();$this->db->enableQueryLog();$this->db->flushQueryLog();
        try{
            $rbac->assignRole(2,'readonly');$rbac->grantPermission($rbac->findOrCreateRole('readonly'),'invoices.view');self::assertTrue($rbac->hasPermission(2,'invoices.view'));
            self::assertTrue($this->db->getPdo()->inTransaction());foreach($this->db->getQueryLog()as$query){self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i',$query['query']);}
        }finally{$this->db->getPdo()->rollBack();}
        self::assertFalse($rbac->hasPermission(2,'invoices.view'));
    }
    public function testMyisamAuthorizationMappingRefusesBeforeCommerceWrite(): void
    {
        [, $rbac, , ,$invoices]=$this->commandFixture();$rbac->assignRole(2,'customer');$this->db->statement('ALTER TABLE role_permissions ENGINE=MyISAM');$before=$this->db->select('SELECT * FROM invoices');
        try{(new \Coleza\Application\Commerce\Invoices\InvoiceApplicationService($invoices))->createInvoice(new \Coleza\Application\Commerce\Invoices\CreateInvoiceCommand(2,items:[['description'=>'New','unit_amount_minor'=>100]]),2);self::fail('Nontransactional authorization accepted');}catch(\LogicException){self::assertSame($before,$this->db->select('SELECT * FROM invoices'));}
    }
}
