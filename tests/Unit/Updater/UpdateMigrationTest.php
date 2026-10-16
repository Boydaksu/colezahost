<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Updater;
use Coleza\Domain\Backup\BackupFileTree;
use Coleza\Foundation\Database\Connection;
final class UpdateMigrationTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\UpdateMigrationCases;
    private Connection $db;
    protected function setUp(): void {$this->db=new Connection(new \PDO('sqlite::memory:'));$this->prepareMigrationFixture();}
    protected function tearDown(): void {unset($this->db);if(isset($this->migrationRoot)){BackupFileTree::removeOwned($this->migrationRoot);}}
}
