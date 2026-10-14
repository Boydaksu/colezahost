<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class SecretStorageTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\SecretStorageCases;
    public function testUpgradeRejectsNonTransactionalStorageBeforeWrites(): void
    {
        [, , , $key] = $this->legacyFixture(); $this->db->statement('ALTER TABLE user_two_factor ENGINE=MyISAM');
        $before = $this->storageRows();
        try { (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key))->upgrade(true); self::fail('MyISAM accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->storageRows()); }
    }

}
