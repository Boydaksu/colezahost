<?php

declare(strict_types=1);

namespace Coleza\Foundation\Storage;

final class StorageManager
{
    /** @var array<string, StorageInterface> */
    private array $disks = [];

    public function __construct(private string $baseStorageDir)
    {
    }

    public function private(): StorageInterface
    {
        return $this->disk('private');
    }

    public function backups(): StorageInterface
    {
        return $this->disk('backups');
    }

    public function disk(string $name = 'private'): StorageInterface
    {
        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $diskPath = rtrim($this->baseStorageDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        $disk = new LocalStorage($diskPath);

        $this->disks[$name] = $disk;
        return $disk;
    }
}
