<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Updater;
use Coleza\Foundation\Database\Connection;
final class UpdatePublicationTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\UpdatePublicationCases;
    private Connection $db;
    protected function setUp(): void {$this->db=new Connection(new \PDO('sqlite::memory:'));$this->preparePublicationFixture();}
    protected function tearDown(): void {unset($this->db);if(isset($this->publicationRoot)){\Coleza\Domain\Backup\BackupFileTree::removeOwned($this->publicationRoot);}}
}
