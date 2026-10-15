<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AuthenticationBoundaryTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AuthenticationBoundaryCases;
    public function testFourProcessesIssueOnlyOneSessionForAChallenge(): void
    {
        [$service, , , ,$two,$id]=$this->interactive();[, $codes]=$this->enableFactor($two,$id);
        $login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        $results=$this->runParallel('authenticationComplete',$id,json_encode(['challenge'=>$login['challenge'],'code'=>$codes[0]],JSON_THROW_ON_ERROR));
        self::assertCount(1,array_filter($results,static fn(array $r):bool=>($r['accepted']??false)===true));
        foreach($results as$result){self::assertContains($result['status'],['ok','rejected']);}
        self::assertCount(1,$this->db->select('SELECT * FROM sessions WHERE user_id = ?',[$id]));
        self::assertCount(1,$this->db->select('SELECT * FROM user_recovery_codes WHERE used_at IS NOT NULL'));
    }
    public function testFourProcessesConsumeOneStepUp(): void
    {
        [$service, , , , ,$id]=$this->interactive();$login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        $token=$service->beginStepUp($login['session_id'],'account.delete');$service->proveStepUp($login['session_id'],'account.delete',$token,'Original123!');
        $results=$this->runParallel('authenticationStepUp',$id,json_encode(['session'=>$login['session_id'],'token'=>$token],JSON_THROW_ON_ERROR));
        self::assertCount(1,array_filter($results,static fn(array $r):bool=>($r['accepted']??false)===true));
        foreach($results as$result){self::assertContains($result['status'],['ok','rejected']);}
    }
    public function testCurrentSessionRejectsPeerPasswordResetDespiteOldSnapshot(): void
    {
        [$service, , , , ,$id]=$this->interactive();$login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        $peer=new \Coleza\Foundation\Database\Connection(new \PDO((string)getenv('COLEZA_TEST_MARIADB_DSN').';dbname='.$this->database,getenv('COLEZA_TEST_MARIADB_USER'),getenv('COLEZA_TEST_MARIADB_PASSWORD')));
        $auth=new \Coleza\Domain\Identity\Auth\AuthService($peer,new \Coleza\Domain\Identity\Security\PasswordHasher(),new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($peer));
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertNotNull($this->db->selectOne('SELECT id FROM sessions WHERE id = ?',[$login['session_id']]));
            $token=$auth->createPasswordResetToken('security@example.test');$auth->resetPassword('security@example.test',$token,'Replacement123!');
            try{$service->sessionUser($login['session_id']);self::fail('Revoked session authenticated');}catch(\Coleza\Foundation\Exceptions\ValidationException){self::assertTrue($this->db->getPdo()->inTransaction());}
        }finally{$this->db->getPdo()->rollBack();}
    }
}
