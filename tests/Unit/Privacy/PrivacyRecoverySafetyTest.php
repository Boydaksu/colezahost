<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Privacy;
use Coleza\Domain\Privacy\Tombstone\{BackupRestoreReconciliationService,PrivacyTombstoneService};
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
final class PrivacyRecoverySafetyTest extends TestCase
{
    private Connection $db;
    private PrivacyTombstoneService $service;
    protected function setUp(): void
    {
        $this->db=new Connection(new PDO('sqlite::memory:'));$this->db->statement('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,phone TEXT,password_hash TEXT)');
        $this->service=new PrivacyTombstoneService($this->db,secretKey:\Coleza\Tests\Support\PrivacyFixtureKeys::tombstone(),salt:\Coleza\Tests\Support\PrivacyFixtureKeys::email());$this->service->recordTombstone(42,'erased@example.test');
    }
    private function user(int $id): void {$this->db->insert('users',['id'=>$id,'name'=>'private name','email'=>'erased@example.test','status'=>'active','phone'=>'private phone','password_hash'=>'old-hash']);}
    public function testWriteFailureCannotBeCountedAsSuccessfulRescrub(): void
    {
        $this->user(42);$this->db->statement("CREATE TRIGGER refuse_user_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT,'refused'); END");
        $this->expectException(\RuntimeException::class);(new BackupRestoreReconciliationService($this->db,$this->service))->reconcileAfterBackupRestore();
    }
    public function testEmailReappearanceUnderMultipleDifferentIdsIsFullyScrubbed(): void
    {
        $this->user(7);$this->user(8);$report=(new BackupRestoreReconciliationService($this->db,$this->service))->reconcileAfterBackupRestore();
        self::assertSame('erased',$this->db->selectOne('SELECT status FROM users WHERE id=8')['status']);self::assertSame([7,8],$report->getScrubbedUserIds());self::assertTrue($report->isProductionReady());
        foreach($this->db->select('SELECT phone,password_hash FROM users')as$row){self::assertNull($row['phone']);self::assertSame('ERASED',$row['password_hash']);}
    }
    public function testRelatedTableFailureRollsBackUserScrubAndDoesNotReportClean(): void
    {
        $this->user(42);$this->db->statement('CREATE TABLE payment_methods(id INT PRIMARY KEY,user_id INT,token TEXT,card_last4 TEXT)');$this->db->insert('payment_methods',['id'=>1,'user_id'=>42,'token'=>'sensitive','card_last4'=>'1234']);
        $this->db->statement("CREATE TRIGGER refuse_payment_update BEFORE UPDATE ON payment_methods BEGIN SELECT RAISE(ABORT,'refused'); END");
        $failed=false;try{(new BackupRestoreReconciliationService($this->db,$this->service))->reconcileAfterBackupRestore();}catch(\RuntimeException){$failed=true;}
        self::assertTrue($failed,'Related scrub failure was silently ignored');self::assertSame('active',$this->db->selectOne('SELECT status FROM users WHERE id=42')['status']);self::assertSame('sensitive',$this->db->selectOne('SELECT token FROM payment_methods')['token']);
    }
}
