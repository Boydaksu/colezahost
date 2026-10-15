<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

/** Internal object operations. Names are confined to one configured backup directory/prefix. */
interface RemoteBackupTransport
{
    public function read(string $name, string $destination): bool;
    public function write(string $name, string $source): void;
    public function remove(string $name): void;
    /** @return list<string> */
    public function names(): array;
    public function lock(string $id): void;
    public function unlock(string $id): void;
}
