<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
use Coleza\Domain\Backup\BackupFileTree;
final class UpdateMigrationTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\UpdateMigrationCases;
    protected function setUp(): void {parent::setUp();$this->prepareMigrationFixture();}
    protected function tearDown(): void {if(isset($this->migrationRoot)){BackupFileTree::removeOwned($this->migrationRoot);}parent::tearDown();}
    public function testCanonicalSchemaAdmitsSignedMigrationAfterTheOriginalTwentySix(): void
    {
        $migrator=new \Coleza\Foundation\Database\Migrator($this->db);$migrator->migrate(dirname(__DIR__,2).'/database/migrations');self::assertCount(26,$migrator->getRanMigrations());
        $this->migrationPackage(['db/signed_next.php'=>$this->migrationCode('$db->statement("CREATE TABLE update_probe (id INT)");')]);
        $result=$this->applyMigrationPackage();self::assertSame(1,$result['migrations_run']);self::assertCount(27,$migrator->getRanMigrations());
        $backup=json_decode(file_get_contents($this->migrationRoot.'/backup/'.$result['backup_id'].'.manifest.json'),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(26,$backup['metadata']['database']['tables']['migrations']['rows']);
    }
}
