<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

use RuntimeException;

/** Manifest is the publication marker; mutation locks never expire automatically. */
final class RemoteBackupRepository
{
    public function __construct(private RemoteBackupTransport $transport) {}

    public function store(string $id, string $archive, string $manifest): bool
    {
        BackupPackage::verify($id, $archive, $manifest);
        $this->transport->lock($id);
        try {
            return $this->staged(function (string $stage) use ($id, $archive, $manifest): bool {
                $hasManifest = $this->transport->read($id . '.manifest.json', $stage . '/manifest');
                $hasArchive = $this->transport->read($id . '.zip', $stage . '/archive');
                if ($hasManifest || $hasArchive) {
                    if (!$hasManifest || !$hasArchive) { throw new RuntimeException('Incomplete remote backup requires reconciliation; existing objects retained.'); }
                    BackupPackage::verify($id, $stage . '/archive', $stage . '/manifest');
                    if (!hash_equals((string) hash_file('sha256', $archive), (string) hash_file('sha256', $stage . '/archive')) ||
                        !hash_equals((string) hash_file('sha256', $manifest), (string) hash_file('sha256', $stage . '/manifest'))) {
                        throw new RuntimeException('Backup ID already exists with different content; remote package retained.');
                    }
                    return true;
                }
                $this->transport->write($id . '.zip', $archive);
                // Verify the archive before publishing its manifest. Service independently verifies the pair again.
                if (!$this->transport->read($id . '.zip', $stage . '/written') ||
                    !hash_equals((string) hash_file('sha256', $archive), (string) hash_file('sha256', $stage . '/written'))) {
                    throw new RuntimeException('Remote archive read-back failed; source retained.');
                }
                $this->transport->write($id . '.manifest.json', $manifest);
                if (!$this->transport->read($id . '.manifest.json', $stage . '/manifest') ||
                    !hash_equals((string) hash_file('sha256', $manifest), (string) hash_file('sha256', $stage . '/manifest'))) {
                    throw new RuntimeException('Remote manifest read-back failed; source retained.');
                }
                return true;
            });
        } finally { $this->transport->unlock($id); }
    }

    public function retrieve(string $id, string $destination): bool
    {
        BackupPackage::id($id);
        return $this->staged(function (string $stage) use ($id, $destination): bool {
            if (!$this->pair($id, $stage)) { return false; }
            return (new LocalBackupStorageAdapter($destination))->store($id, $stage . '/archive', $stage . '/manifest');
        });
    }

    public function exists(string $id): bool
    {
        BackupPackage::id($id);
        return $this->staged(fn (string $stage): bool => $this->pair($id, $stage));
    }

    public function delete(string $id): bool
    {
        BackupPackage::id($id);
        $this->transport->lock($id);
        try {
            return $this->staged(function (string $stage) use ($id): bool {
                if (!$this->pair($id, $stage)) { return false; }
                $this->transport->remove($id . '.manifest.json');
                $this->transport->remove($id . '.zip');
                return true;
            });
        } finally { $this->transport->unlock($id); }
    }

    /** @return list<string> */
    public function listBackups(): array
    {
        $ids = [];
        foreach ($this->transport->names() as $name) {
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,127})\.manifest\.json$/D', $name, $match) && $this->exists($match[1])) { $ids[] = $match[1]; }
        }
        $ids = array_values(array_unique($ids)); sort($ids);
        return $ids;
    }

    private function pair(string $id, string $stage): bool
    {
        if (!$this->transport->read($id . '.manifest.json', $stage . '/manifest') || !$this->transport->read($id . '.zip', $stage . '/archive')) { return false; }
        BackupPackage::verify($id, $stage . '/archive', $stage . '/manifest');
        return true;
    }

    private function staged(callable $operation): mixed
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/coleza_remote_' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create private remote backup staging directory.'); }
        try { return $operation($directory); }
        finally {
            foreach (['archive', 'manifest', 'written'] as $name) { if (is_file($directory . '/' . $name) && !is_link($directory . '/' . $name)) { unlink($directory . '/' . $name); } }
            rmdir($directory);
        }
    }
}
