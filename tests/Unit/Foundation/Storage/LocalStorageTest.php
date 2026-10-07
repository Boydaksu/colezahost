<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Storage;

use Coleza\Foundation\Storage\LocalStorage;
use Coleza\Foundation\Storage\StorageManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LocalStorageTest extends TestCase
{
    private string $tempStorageDir;
    private LocalStorage $storage;

    protected function setUp(): void
    {
        $this->tempStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_storage_' . uniqid();
        mkdir($this->tempStorageDir, 0777, true);
        $this->storage = new LocalStorage($this->tempStorageDir);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempStorageDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($this->tempStorageDir);
        parent::tearDown();
    }

    public function testStoreAndRetrieveFile(): void
    {
        $stored = $this->storage->put('invoices/inv_1001.pdf', 'dummy-pdf-content');
        $this->assertTrue($stored);

        $this->assertTrue($this->storage->exists('invoices/inv_1001.pdf'));
        $this->assertSame('dummy-pdf-content', $this->storage->get('invoices/inv_1001.pdf'));
        $this->assertSame(strlen('dummy-pdf-content'), $this->storage->size('invoices/inv_1001.pdf'));
    }

    public function testDeleteFile(): void
    {
        $this->storage->put('temp/file.txt', 'hello');
        $this->assertTrue($this->storage->exists('temp/file.txt'));

        $this->storage->delete('temp/file.txt');
        $this->assertFalse($this->storage->exists('temp/file.txt'));
        $this->assertNull($this->storage->get('temp/file.txt'));
    }

    public function testRejectsPathTraversalAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal attempt detected');

        $this->storage->get('../../../etc/passwd');
    }

    public function testRejectsNullByteInPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Null byte detected');

        $this->storage->get("file.txt\0.jpg");
    }

    public function testStorageManagerDiskSeparation(): void
    {
        $manager = new StorageManager($this->tempStorageDir);

        $privateDisk = $manager->private();
        $backupDisk = $manager->backups();

        $privateDisk->put('sec.txt', 'secret');
        $backupDisk->put('dump.sql', 'db dump');

        $this->assertTrue($privateDisk->exists('sec.txt'));
        $this->assertFalse($backupDisk->exists('sec.txt'));
        $this->assertTrue($backupDisk->exists('dump.sql'));
    }
}
