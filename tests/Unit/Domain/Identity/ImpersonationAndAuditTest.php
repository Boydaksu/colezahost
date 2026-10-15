<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Domain\Identity;
use Coleza\Foundation\Exceptions\ValidationException;
final class ImpersonationAndAuditTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\ImpersonationProofFixture;
    protected \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void{$this->db=new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'),'sqlite');}
    public function testStartAndStopImpersonation(): void
    {
        [$auth,$service,$login, ,$sessions,$audit]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);
        $started=$service->startImpersonation($login['session_id'],1,2,'Investigating billing discrepancy',stepUpToken:$proof);
        self::assertSame([],$sessions->read($login['session_id']));self::assertNotSame($login['session_id'],$started['session_id']);
        self::assertSame(['user_id'=>2,'impersonator_id'=>1],$service->effectiveUser($started['session_id']));
        self::assertSame(1,(int)$this->db->selectOne('SELECT user_id FROM sessions WHERE id=?',[$started['session_id']])['user_id']);
        $logs=$audit->getLogsForUser(2);self::assertSame('IMPERSONATION_STARTED',$logs[0]['event_type']);self::assertSame(1,(int)$logs[0]['impersonator_user_id']);
        $stopped=$service->stopImpersonation($started['session_id']);self::assertSame([],$sessions->read($started['session_id']));
        self::assertSame(['user_id'=>1,'impersonator_id'=>null],$service->effectiveUser($stopped['session_id']));
        self::assertSame('user:2',$this->db->selectOne("SELECT target_resource FROM security_audit_logs WHERE event_type='IMPERSONATION_STOPPED'")['target_resource']);
    }
    public function testUnauthorizedAdminCannotImpersonate(): void
    {
        [$auth,$service]=$this->administratorFixture();$login=$auth->beginLogin('bob@example.test','Original123!','127.0.0.1');
        $proof=$auth->beginStepUp($login['session_id'],'users.impersonate:2');$auth->proveStepUp($login['session_id'],'users.impersonate:2',$proof,'Original123!');
        $this->expectException(ValidationException::class);$service->startImpersonation($login['session_id'],3,2,'Investigate',stepUpToken:$proof);
    }
    public function testCannotImpersonateSelf(): void
    {
        [, $service,$login]=$this->administratorFixture();$this->expectException(ValidationException::class);$service->startImpersonation($login['session_id'],1,1,'Investigate',stepUpToken:'invalid');
    }
    public function testBlocksHighRiskOperationsDuringImpersonation(): void
    {
        [$auth,$service,$login]=$this->administratorFixture();$proof=$this->impersonationProof($auth,$login);$started=$service->startImpersonation($login['session_id'],1,2,'Investigate',stepUpToken:$proof);
        $service->guardOperation($started['session_id'],'invoices.view');
        $this->expectException(ValidationException::class);$service->guardOperation($started['session_id'],'password.change');
    }
}
