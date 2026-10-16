<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Domain\Module;
use Coleza\Foundation\Database\Connection;
final class ExtensionLifecycleTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\ExtensionLifecycleCases;
    private Connection $db;
    protected function setUp(): void {$this->db=new Connection(new \PDO('sqlite::memory:'));$this->prepareExtensionFixture();}
    protected function tearDown(): void {unset($this->db);if(isset($this->extensionRoot)){\Coleza\Domain\Backup\BackupFileTree::removeOwned($this->extensionRoot);}}
}
