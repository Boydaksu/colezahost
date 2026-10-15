<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Identity\Auth\AuthService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\TwoFactor\{TwoFactorService,TotpEngine};
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Foundation\Exceptions\ValidationException;
trait AuthenticationBoundaryCases
{
    private function securityFixture(): array
    {
        $sessions = new DatabaseSessionHandler($this->db); $hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $auth = new AuthService($this->db, $hasher, $sessions);
        $id = $auth->register('security@example.test', 'Original123!', 'Security');
        $rbac = new RbacService($this->db); $rbac->ensureTables();
        $two = new TwoFactorService($this->db, new TotpEngine(), $hasher, SecretFixtureKey::encryptor()); $two->ensureTables();
        return [$auth,$sessions,$rbac,$two,$id];
    }
    public function testDisabledTwoFactorCannotSupplySuccessfulProof(): void
    {
        [, , , $two,$id] = $this->securityFixture(); self::assertFalse($two->verifyLoginCode($id, '123456'));
    }
    public function testDeviceTrustCannotBeMintedWithoutFreshSecondFactor(): void
    {
        [, , , $two,$id] = $this->securityFixture(); $setup=$two->beginSetup($id,'security@example.test'); $two->confirmSetup($id,(new TotpEngine())->generateCode($setup['secret']));
        $this->expectException(ValidationException::class); $two->trustDevice($id);
    }
    public function testAdminPolicyCannotBeDisabledByCallerBoolean(): void
    {
        [, ,$rbac,$two,$id]=$this->securityFixture();$rbac->assignRole($id,'support_agent');
        $this->expectException(ValidationException::class);$two->enforceAdmin2FaPolicy($id,false);
    }

    private function interactive(): array
    {
        [$auth,$sessions,$rbac,$two,$id]=$this->securityFixture();
        $service=new \Coleza\Application\Identity\AuthenticationService($this->db,SecretFixtureKey::encryptor()); $service->prepare();
        return [$service,$auth,$sessions,$rbac,$two,$id];
    }
    private function enableFactor(TwoFactorService $two,int $id): array
    {
        $setup=$two->beginSetup($id,'security@example.test');$code=(new TotpEngine())->generateCode($setup['secret']);
        return [$setup['secret'],$two->confirmSetup($id,$code)];
    }
    public function testPasswordLoginIssuesFreshOwnedSessionAndLogoutRevokesIt(): void
    {
        [$service, ,$sessions, , ,$id]=$this->interactive();
        $result=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        self::assertSame('authenticated',$result['status']);self::assertSame($id,$service->sessionUser($result['session_id'])['id']);
        self::assertSame($id,(int)$this->db->selectOne('SELECT user_id FROM sessions WHERE id = ?',[$result['session_id']])['user_id']);
        self::assertArrayNotHasKey('password_hash',$result);self::assertArrayNotHasKey('csrf_token',$sessions->read($result['session_id']));
        $service->logout($result['session_id']);self::assertSame([],$sessions->read($result['session_id']));
        $this->expectException(ValidationException::class);$service->sessionUser($result['session_id']);
    }
    public function testPasswordProofAloneDoesNotIssueAdminSessionAndEnrollmentIsBound(): void
    {
        [$service, , ,$rbac, ,$id]=$this->interactive();$rbac->assignRole($id,'support_agent');
        $login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');self::assertSame('enrollment_required',$login['status']);
        try { $service->sessionUser($login['challenge']);self::fail('Pending login authenticated'); } catch (ValidationException) { self::assertNull($this->db->selectOne('SELECT user_id FROM sessions WHERE id = ?',[$login['challenge']])['user_id']); }
        $setup=$service->beginEnrollment($login['challenge']);$result=$service->confirmEnrollment($login['challenge'],(new TotpEngine())->generateCode($setup['secret']));
        self::assertCount(8,$result['recovery_codes']);self::assertSame($id,$service->sessionUser($result['session_id'])['id']);
        self::assertNotSame($login['challenge'],$result['session_id']);
        $this->expectException(ValidationException::class);$service->completeLogin($login['challenge'],'123456');
    }
    public function testSecondFactorAndRecoveryRequireCurrentPasswordBoundChallenge(): void
    {
        [$service, , , ,$two,$id]=$this->interactive();[, $codes]=$this->enableFactor($two,$id);
        $login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');self::assertSame('second_factor_required',$login['status']);
        $result=$service->completeLogin($login['challenge'],$codes[0],true);self::assertSame($id,$service->sessionUser($result['session_id'])['id']);
        try { $service->completeLogin($login['challenge'],$codes[1],true);self::fail('Challenge reused'); } catch (ValidationException) { self::assertCount(1,$this->db->select('SELECT * FROM user_recovery_codes WHERE used_at IS NOT NULL')); }
    }
    public function testExpiredChallengeAndChangedPasswordCannotIssueSession(): void
    {
        [$service,$auth, , ,$two,$id]=$this->interactive();[, $codes]=$this->enableFactor($two,$id);
        $login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        $token=$auth->createPasswordResetToken('security@example.test');$auth->resetPassword('security@example.test',$token,'Replacement123!');
        $this->expectException(ValidationException::class);$service->completeLogin($login['challenge'],$codes[0],true);
    }
    public function testFiveWrongProofsRevokeChallengeAndRateBudgetPersists(): void
    {
        [$service, , , ,$two,$id]=$this->interactive();$this->enableFactor($two,$id);$login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        for($i=0;$i<5;$i++) { try { $service->completeLogin($login['challenge'],'invalid');self::fail('Bad proof accepted'); } catch(ValidationException) {} }
        self::assertNotNull($this->db->selectOne('SELECT session_id FROM session_revocations WHERE session_id = ?',[$login['challenge']]));
        self::assertSame(5,(int)$this->db->selectOne("SELECT hits FROM api_rate_limits WHERE rate_key LIKE 'factor-account:%'")['hits']);
    }
    public function testLoginRateAdmissionCannotBeRolledBackByCaller(): void
    {
        [$service]=$this->interactive();$this->db->getPdo()->beginTransaction();
        try { try { $service->beginLogin('security@example.test','Original123!','127.0.0.1');self::fail('Admission in caller accepted'); } catch(\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); } }
        finally { $this->db->getPdo()->rollBack(); }
        self::assertSame([],$this->db->select('SELECT * FROM sessions'));
    }
    public function testTrustedDeviceCannotBypassAdministratorSecondFactor(): void
    {
        [$service, , ,$rbac,$two,$id]=$this->interactive();[$secret, $codes]=$this->enableFactor($two,$id);
        $login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');$result=$service->completeLogin($login['challenge'],$codes[0],true);
        $device=$service->trustDevice($result['session_id'],(new TotpEngine())->generateCode($secret));
        $trusted=$service->beginLogin('security@example.test','Original123!','127.0.0.1',$device);self::assertSame('authenticated',$trusted['status']);
        $rbac->assignRole($id,'billing_admin');
        try { $service->sessionUser($trusted['session_id']);self::fail('Trusted session elevated to admin'); } catch(ValidationException) { self::assertTrue($two->isEnabled($id)); }
        self::assertSame('second_factor_required',$service->beginLogin('security@example.test','Original123!','127.0.0.1',$device)['status']);
    }
    public function testStepUpIsBoundToSessionOperationAndSingleUse(): void
    {
        [$service]=$this->interactive();$login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');$id=$login['session_id'];
        $token=$service->beginStepUp($id,'payments.refund:42');
        try { $service->consumeStepUp($id,'payments.refund:42',$token);self::fail('Unproved challenge consumed'); } catch(ValidationException) {}
        $service->proveStepUp($id,'payments.refund:42',$token,'Original123!');
        try { $service->consumeStepUp($id,'payments.refund:43',$token);self::fail('Other operation accepted'); } catch(ValidationException) {}
        $other=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        try { $service->consumeStepUp($other['session_id'],'payments.refund:42',$token);self::fail('Other session accepted'); } catch(ValidationException) {}
        $service->consumeStepUp($id,'payments.refund:42',$token);
        $this->expectException(ValidationException::class);$service->consumeStepUp($id,'payments.refund:42',$token);
    }
    public function testStepUpRollbackPreservesGrantAndAuditFailurePreservesSession(): void
    {
        [$service]=$this->interactive();$login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');$id=$login['session_id'];$token=$service->beginStepUp($id,'account.delete');$service->proveStepUp($id,'account.delete',$token,'Original123!');
        $this->db->getPdo()->beginTransaction();try { $service->consumeStepUp($id,'account.delete',$token); }finally{$this->db->getPdo()->rollBack();}
        $service->consumeStepUp($id,'account.delete',$token);
        $this->db->statement($this->db->getDriverName()==='mysql'?"CREATE TRIGGER reject_login_audit BEFORE INSERT ON security_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'":"CREATE TRIGGER reject_login_audit BEFORE INSERT ON security_audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try { $service->logout($id);self::fail('Audit failure swallowed'); }catch(\PDOException){ self::assertSame($login['user_id'],$service->sessionUser($id)['id']); }
    }
    public function testExpiredAndSuspendedSessionRejectedWithoutRelyingOnFlags(): void
    {
        [$service, ,$sessions, , ,$id]=$this->interactive();$login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');
        $payload=$sessions->read($login['session_id']);$payload['authentication']['expires_at']=time();$sessions->write($login['session_id'],$payload,$id);
        try{$service->sessionUser($login['session_id']);self::fail('Expired session accepted');}catch(ValidationException){}
        $login=$service->beginLogin('security@example.test','Original123!','127.0.0.1');$this->db->statement('UPDATE users SET is_active = 0 WHERE id = ?',[$id]);
        $this->expectException(ValidationException::class);$service->sessionUser($login['session_id']);
    }
    public function testCookiePolicyCannotBeDowngradedOrInjected(): void
    {
        $cookie=\Coleza\Foundation\Http\SessionCookie::issue(str_repeat('a',64));
        foreach(['__Host-','Path=/','Secure','HttpOnly','SameSite=Lax']as$part){self::assertStringContainsString($part,$cookie);}
        self::assertStringNotContainsString('Domain=',$cookie);self::assertStringContainsString('Max-Age=0',\Coleza\Foundation\Http\SessionCookie::expire());
        $this->expectException(\InvalidArgumentException::class);\Coleza\Foundation\Http\SessionCookie::issue("x; SameSite=None");
    }
}
