<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Attachments;

interface AttachmentStorageInterface
{
    public function put(string $storageKey, string $content): bool;

    public function get(string $storageKey): ?string;

    public function exists(string $storageKey): bool;

    public function delete(string $storageKey): bool;
}
