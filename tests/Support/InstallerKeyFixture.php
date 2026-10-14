<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait InstallerKeyFixture
{
    private array $installerKeyDirectories = [];
    private function configureInstallerKey(\Coleza\Domain\Installer\WebInstallerService $installer): string
    {
        $directory = sys_get_temp_dir() . '/coleza-installer-key-test-' . bin2hex(random_bytes(12));
        $this->installerKeyDirectories[] = $directory;
        $installer->setupApplicationKey($directory . '/application.key');
        return $directory . '/application.key';
    }
    private function cleanupInstallerKeys(): void
    {
        foreach ($this->installerKeyDirectories as $directory) {
            if (is_file($directory . '/application.key')) { unlink($directory . '/application.key'); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }
}
