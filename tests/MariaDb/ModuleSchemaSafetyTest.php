<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ModuleSchemaSafetyTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ModuleSchemaSafetyCases;
    public function testMyisamWritesRefuseBeforeChangingInventory(): void
    {
        $this->seedModule(); $this->db->statement('ALTER TABLE installed_modules ENGINE=MyISAM');
        $before = $this->db->select('SELECT * FROM installed_modules');
        foreach (['disable', 'enable', 'install'] as $action) {
            try {
                if ($action === 'install') {
                    $this->moduleService()->install(new \Coleza\Domain\Module\ModuleManifest('new', 'New', '1.0.0', 'integration', '1.0.0'));
                } else { $this->moduleService()->$action('original'); }
                self::fail('Nontransactional module write accepted');
            } catch (\LogicException $error) { self::assertStringContainsString('InnoDB', $error->getMessage()); }
            self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
        }
        self::assertSame(1, (new \Coleza\Domain\Updater\ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0')['total_checked']);
    }
    public function testLostManagedCallerRefusesBeforeReadOrWrite(): void
    {
        $this->seedModule(); $before = $this->db->select('SELECT * FROM installed_modules');
        $this->db->beginTransaction(); $this->db->getPdo()->commit();
        foreach (['read', 'write'] as $action) {
            try {
                if ($action === 'read') { (new \Coleza\Domain\Updater\ModuleCompatibilityChecker($this->db))->checkCompatibility('1.1.0'); }
                else { $this->moduleService()->disable('original'); }
                self::fail('Lost caller transaction accepted');
            } catch (\LogicException $error) { self::assertStringContainsString('ended unexpectedly', $error->getMessage()); }
        }
        self::assertSame($before, $this->db->select('SELECT * FROM installed_modules'));
    }
}
