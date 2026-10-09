<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Attachments;

final class InMemoryAttachmentStorage implements AttachmentStorageInterface
{
    /** @var array<string, string> */
    private array $storage = [];

    public function put(string $storageKey, string $content): bool
    {
        $this->storage[$storageKey] = $content;
        return true;
    }

    public function get(string $storageKey): ?string
    {
        return $this->storage[$storageKey] ?? null;
    }

    public function exists(string $storageKey): bool
    {
        return array_key_exists($storageKey, $this->storage);
    }

    public function delete(string $storageKey): bool
    {
        if (array_key_exists($storageKey, $this->storage)) {
            unset($this->storage[$storageKey]);
            return true;
        }

        return false;
    }

    public function count(): int
    {
        return count($this->storage);
    }

    public function clear(): void
    {
        $this->storage = [];
    }
}
