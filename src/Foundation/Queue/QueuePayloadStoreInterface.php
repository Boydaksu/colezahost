<?php
declare(strict_types=1);
namespace Coleza\Foundation\Queue;

/** Encrypted, owner-bound persistence supplied by the application's composition root. */
interface QueuePayloadStoreInterface
{
    public function prepareKey(): void;
    public function prepare(): void;
    public function guard(): void;
    public function read(string $table, array $owner): string;
    public function writeNew(string $table, array $owner, #[\SensitiveParameter] string $plain): string;
    public function delete(string $table, array $owner): void;
}
