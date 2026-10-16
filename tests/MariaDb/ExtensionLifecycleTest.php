<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ExtensionLifecycleTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ExtensionLifecycleCases;
    protected function setUp(): void {parent::setUp();$this->prepareExtensionFixture();}
    protected function tearDown(): void {if(isset($this->extensionRoot)){\Coleza\Domain\Backup\BackupFileTree::removeOwned($this->extensionRoot);}parent::tearDown();}
}
