<?php
declare(strict_types=1);
namespace Coleza\Application\Identity;
use Coleza\Domain\Identity\Auth\AuthService;
use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Identity\IdentityPersistence;
use Coleza\Domain\Identity\Rbac\RbacService;
use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\Session\{DatabaseSessionHandler,SessionRevocationSchema};
use Coleza\Domain\Identity\TwoFactor\{TwoFactorService,TwoFactorLoginSchema,TotpEngine};
use Coleza\Domain\Vault\{Encryptor,KeyBinding};
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Security\RequestBudget;

/** The only interactive session issuer: password checks alone never authenticate a session. */
final class AuthenticationService implements \Coleza\Domain\Identity\Session\AuthenticatedSessionVerifier
{
    private AuthService $credentials;
    private DatabaseSessionHandler $sessions;
    private TwoFactorService $two;
    private RbacService $rbac;
    private AuditLogger $audit;
    private RequestBudget $budget;
    public function __construct(private Connection $db, private Encryptor $encryptor)
    {
        $this->sessions = new DatabaseSessionHandler($db);
        $hasher = new PasswordHasher();
        $this->credentials = new AuthService($db, $hasher, $this->sessions);
        $this->two = new TwoFactorService($db, new TotpEngine(), $hasher, $encryptor);
        $this->rbac = new RbacService($db); $this->audit = new AuditLogger($db); $this->budget = new RequestBudget($db);
    }
    public function usesConnection(Connection $db): bool { return $this->db === $db; }
    public function prepare(): void
    {
        $this->credentials->ensureTables(installation: false); $this->two->ensureTables(installation: false);
        TwoFactorLoginSchema::prepare($this->db); $this->sessions->prepareLifecycle(); $this->rbac->prepare();
        $this->audit->ensureTable();
        IdentityPersistence::installed($this->db, ['security_audit_logs' => ['id','actor_user_id','event_type']], installation: false);
        $this->budget->ensureTable(installation: false);
    }
    public function beginLogin(string $email, string $password, string $clientIp, ?string $deviceToken = null): array
    {
        $this->prepare();
        $this->budget->hit('login-ip:' . hash('sha256', $clientIp), 30, 300);
        $this->budget->hit('login-account:' . hash('sha256', strtolower(trim($email))), 10, 300);
        return $this->run(function () use ($email,$password,$deviceToken): array {
            $user = $this->credentials->authenticate($email,$password); $id = (int) $user['id'];
            $admin = $this->rbac->isSystemActor($id); $enabled = $this->two->isEnabled($id);
            if (!$admin && (!$enabled || ($deviceToken !== null && $this->two->isDeviceTrusted($id,$deviceToken)))) {
                return $this->issue($user, $enabled ? 'trusted_device' : 'password', $deviceToken);
            }
            $challenge = bin2hex(random_bytes(32));
            $this->sessions->write($challenge, ['login' => ['user_id' => $id, 'credential_version' => $this->version($user), 'expires_at' => time()+300, 'attempts' => 0]], null);
            $this->audit->log($id,'authentication.challenge_created');
            return ['status' => $enabled ? 'second_factor_required' : 'enrollment_required', 'challenge' => $challenge];
        });
    }
    public function beginEnrollment(string $challenge): array
    {
        $this->prepare(); $this->admitChallenge($challenge,'enroll');
        return $this->run(function () use ($challenge): array {
            [, $user] = $this->pending($challenge);
            $result = $this->two->beginSetup((int)$user['id'], (string)$user['email']);
            $this->audit->log((int)$user['id'],'authentication.enrollment_started');
            return $result;
        });
    }
    public function confirmEnrollment(string $challenge, string $code): array
    {
        $this->prepare(); $this->admitChallenge($challenge,'factor');
        return $this->run(function () use ($challenge,$code): array {
            [, $user] = $this->pending($challenge); $id=(int)$user['id'];
            $codes = $this->two->confirmSetup($id,$code);
            if (!$this->two->verifyLoginCode($id,$code)) { $this->deny(); }
            $this->sessions->destroy($challenge); $result=$this->issue($user,'totp'); $result['recovery_codes']=$codes;
            return $result;
        });
    }
    public function completeLogin(string $challenge, string $code, bool $recovery = false): array
    {
        $this->prepare(); $this->admitChallenge($challenge,'factor');
        $result = $this->run(function () use ($challenge,$code,$recovery): ?array {
            [$pending,$user] = $this->pending($challenge); $id=(int)$user['id'];
            $valid=$recovery ? $this->two->redeemRecoveryCode($id,$code) : $this->two->verifyLoginCode($id,$code);
            if (!$valid) {
                $pending['attempts']++;
                if ($pending['attempts'] >= 5) { $this->sessions->destroy($challenge); }
                else { $this->sessions->write($challenge,['login'=>$pending]); }
                $this->audit->log($id,'authentication.factor_rejected'); return null;
            }
            $this->sessions->destroy($challenge); return $this->issue($user,$recovery ? 'recovery' : 'totp');
        });
        if ($result === null) { $this->deny(); }
        return $result;
    }
    /** Current session identity, independent of caller flags and stale payload roles. */
    public function sessionUser(string $sessionId): array
    {
        $this->prepare();
        return $this->run(fn(): array => $this->authenticated($sessionId)[0]);
    }
    public function beginStepUp(string $sessionId, string $operation): string
    {
        $this->operation($operation); $this->prepare();
        return $this->run(function () use ($sessionId,$operation): string {
            [$user,$payload] = $this->authenticated($sessionId);
            if (isset($payload['impersonator_id'])) { $this->deny(); }
            $token=bin2hex(random_bytes(32));
            $payload['step_up']=['hash'=>hash('sha256',$token),'operation'=>$operation,'expires_at'=>time()+300,'proved'=>false];
            $this->sessions->write($sessionId,$payload,(int)$user['id']);
            return $token;
        });
    }
    public function proveStepUp(string $sessionId, string $operation, string $token, string $password, ?string $code = null, bool $recovery = false): void
    {
        $this->operation($operation); $this->prepare(); $this->budget->hit('step-up:' . hash('sha256',$sessionId),5,300);
        $this->run(function () use ($sessionId,$operation,$token,$password,$code,$recovery): void {
            [$user,$payload] = $this->authenticated($sessionId); $this->challenge($payload,$operation,$token,false);
            $this->credentials->authenticate((string)$user['email'],$password); $id=(int)$user['id'];
            if ($this->two->isEnabled($id) && ($code === null || !($recovery ? $this->two->redeemRecoveryCode($id,$code) : $this->two->verifyLoginCode($id,$code)))) { $this->deny(); }
            $payload['step_up']['proved']=true; $this->sessions->write($sessionId,$payload,$id);
            $this->audit->log($id,'authentication.step_up_verified',targetResource:$operation);
        });
    }
    /** Called inside the protected local mutation transaction so failed mutations restore the proof. */
    public function consumeStepUp(string $sessionId, string $operation, string $token): void
    {
        $this->operation($operation); $this->prepare();
        $this->run(function () use ($sessionId,$operation,$token): void {
            [$user,$payload] = $this->authenticated($sessionId); $this->challenge($payload,$operation,$token,true);
            unset($payload['step_up']); $this->sessions->write($sessionId,$payload,(int)$user['id']);
            $this->audit->log((int)$user['id'],'authentication.step_up_consumed',targetResource:$operation);
        });
    }
    public function trustDevice(string $sessionId, string $code, ?string $ip = null, ?string $userAgent = null): string
    {
        $this->prepare(); $this->budget->hit('device-proof:' . hash('sha256',$sessionId),5,300);
        return $this->run(function () use ($sessionId,$code,$ip,$userAgent): string {
            [$user,$payload]=$this->authenticated($sessionId);
            if (isset($payload['impersonator_id']) || !in_array($payload['authentication']['method'],['totp','recovery'],true)) { $this->deny(); }
            $token=$this->two->trustDevice((int)$user['id'],$ip,$userAgent,verificationCode:$code);
            $this->audit->log((int)$user['id'],'authentication.device_trusted'); return $token;
        });
    }
    public function verifyCsrf(string $sessionId, string $token): void
    {
        $this->prepare();
        $this->run(function () use ($sessionId,$token): void {
            [, $payload] = $this->authenticated($sessionId);
            if (!hash_equals((string)($payload['authentication']['csrf_hash']??''),hash('sha256',$token))) { $this->deny(); }
        });
    }
    public function logout(string $sessionId): void
    {
        $this->prepare();
        $this->run(function () use ($sessionId): void {
            [$user]=$this->authenticated($sessionId); $this->sessions->destroy($sessionId);
            $this->audit->log((int)$user['id'],'authentication.logged_out');
        });
    }
    private function issue(array $user, string $method, ?string $device = null): array
    {
        $id=(int)$user['id']; $session=bin2hex(random_bytes(32)); $csrf=bin2hex(random_bytes(32));
        $state=['user_id'=>$id,'credential_version'=>$this->version($user),'factor_version'=>$this->factorVersion($id),'method'=>$method,'issued_at'=>time(),'expires_at'=>time()+7200,'csrf_hash'=>hash('sha256',$csrf)];
        if ($method==='trusted_device') { $state['device_hash']=hash('sha256',(string)$device); }
        $this->sessions->write($session,['authentication'=>$state],$id);
        $this->audit->log($id,'authentication.logged_in',payload:['method'=>$method]);
        return ['status'=>'authenticated','session_id'=>$session,'csrf_token'=>$csrf,'user_id'=>$id];
    }
    private function pending(string $id): array
    {
        [$row,$payload]=$this->session($id); $pending=$payload['login']??null;
        if ($row['user_id']!==null || !is_array($pending) || !is_int($pending['user_id']??null) || ($pending['expires_at']??0)<=time() || ($pending['attempts']??5)>=5) { $this->deny(); }
        $user=$this->user($pending['user_id']);
        if (!hash_equals($this->version($user),(string)($pending['credential_version']??''))) { $this->deny(); }
        return [$pending,$user];
    }
    private function authenticated(string $id): array
    {
        [$row,$payload]=$this->session($id); $state=$payload['authentication']??null;
        if (!is_array($state) || !is_int($state['user_id']??null) || (int)$row['user_id']!==$state['user_id'] || ($state['expires_at']??0)<=time()) { $this->deny(); }
        $user=$this->user($state['user_id']);
        if (!hash_equals($this->version($user),(string)($state['credential_version']??'')) || !hash_equals($this->factorVersion((int)$user['id']),(string)($state['factor_version']??''))) { $this->deny(); }
        $method=$state['method']??'';
        if (!in_array($method,['password','totp','recovery','trusted_device'],true)) { $this->deny(); }
        if ($this->two->isEnabled((int)$user['id']) && $method==='password') { $this->deny(); }
        if ($this->rbac->isSystemActor((int)$user['id']) && (!$this->two->isEnabled((int)$user['id']) || !in_array($method,['totp','recovery'],true))) { $this->deny(); }
        if ($method==='trusted_device') {
            $rows=$this->db->select('SELECT id FROM user_trusted_devices WHERE user_id = ? AND device_token_hash = ? AND expires_at > ?' . $this->db->forUpdate(),[$user['id'],$state['device_hash']??'',time()]);
            if (count($rows)!==1) { $this->deny(); }
        }
        return [$user,$payload];
    }
    private function session(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) { $this->deny(); }
        if ($this->db->selectOne('SELECT session_id FROM session_revocations WHERE session_id = ?' . $this->db->forUpdate(),[$id])!==null) { $this->deny(); }
        $row=$this->db->selectOne('SELECT * FROM sessions WHERE id = ?' . $this->db->forUpdate(),[$id]);
        if ($row===null || (int)$row['last_activity']<time()-7200) { $this->deny(); }
        try { $payload=json_decode((string)$row['payload'],true,flags:JSON_THROW_ON_ERROR); } catch (\JsonException) { $this->deny(); }
        if (!is_array($payload)) { $this->deny(); }
        return [$row,$payload];
    }
    private function user(int $id): array
    {
        $user=$this->db->selectOne('SELECT * FROM users WHERE id = ?' . $this->db->forUpdate(),[$id]);
        if ($id<1 || $user===null || (int)$user['is_active']!==1 || (array_key_exists('status',$user) && $user['status']!=='active')) { $this->deny(); }
        return $user;
    }
    private function version(array $user): string { return hash('sha256',json_encode([$user['id'],$user['email'],$user['password_hash']],JSON_THROW_ON_ERROR)); }
    private function factorVersion(int $id): string
    {
        $row=$this->db->selectOne('SELECT secret,is_enabled FROM user_two_factor WHERE user_id = ?' . $this->db->forUpdate(),[$id]);
        return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
    }
    private function challenge(array $payload,string $operation,string $token,bool $proved): void
    {
        $step=$payload['step_up']??null;
        if (isset($payload['impersonator_id']) || !is_array($step) || ($step['operation']??null)!==$operation || ($step['expires_at']??0)<=time() || ($step['proved']??null)!==$proved || !hash_equals((string)($step['hash']??''),hash('sha256',$token))) { $this->deny(); }
    }
    private function operation(string $operation): void { if (!preg_match('/^[a-z][a-z0-9_.:-]{0,99}$/D',$operation)) { throw new \InvalidArgumentException('Invalid protected operation.'); } }
    private function admitChallenge(string $challenge,string $kind): void
    {
        // Admission is committed before consuming any proof. Per-account budget survives challenge replacement.
        $this->budget->hit('challenge:' . hash('sha256',$challenge),10,300);
        $payload=$this->sessions->read($challenge); $id=$payload['login']['user_id']??0;
        $this->budget->hit('factor-account:' . hash('sha256',(string)$id),10,300);
    }
    private function run(callable $callback): mixed
    {
        return SessionRevocationSchema::run($this->db,function () use ($callback): mixed { KeyBinding::guard($this->db,$this->encryptor); return $callback(); });
    }
    private function deny(): never { throw new ValidationException(['authentication'=>['Current authenticated proof required.']]); }
}
