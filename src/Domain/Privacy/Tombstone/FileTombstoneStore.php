<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use RuntimeException;

final class FileTombstoneStore implements TombstoneStoreInterface
{
    public function __construct(
        private readonly string $filePath
    ) {
        $this->ensureDirectory();
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    public function persist(PrivacyTombstone $tombstone): void
    {
        $all = $this->loadAll();
        $all[$tombstone->getUserId()] = $tombstone;

        $serialized = [];
        foreach ($all as $item) {
            $serialized[] = $item->toArray();
        }

        $json = json_encode($serialized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->filePath, $json, LOCK_EX);
    }

    public function loadAll(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $tombstones = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $tombstone = PrivacyTombstone::fromArray($item);
                $tombstones[$tombstone->getUserId()] = $tombstone;
            }
        }

        return $tombstones;
    }

    public function findByUserId(int $userId): ?PrivacyTombstone
    {
        $all = $this->loadAll();
        return $all[$userId] ?? null;
    }

    public function hasUserId(int $userId): bool
    {
        $all = $this->loadAll();
        return isset($all[$userId]);
    }

    public function exportManifest(): string
    {
        $all = $this->loadAll();
        $items = [];
        foreach ($all as $item) {
            $items[] = $item->toArray();
        }

        $payload = [
            'version' => 1,
            'exported_at' => date('Y-m-d H:i:s'),
            'count' => count($items),
            'tombstones' => $items,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', $json);

        $payload['manifest_checksum'] = $checksum;
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function importManifest(string $json): int
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['tombstones']) || !is_array($decoded['tombstones'])) {
            throw new RuntimeException('Invalid tombstone manifest format.');
        }

        $existing = $this->loadAll();
        $importedCount = 0;

        foreach ($decoded['tombstones'] as $item) {
            if (is_array($item)) {
                $tombstone = PrivacyTombstone::fromArray($item);
                if (!isset($existing[$tombstone->getUserId()])) {
                    $existing[$tombstone->getUserId()] = $tombstone;
                    $importedCount++;
                }
            }
        }

        if ($importedCount > 0) {
            $serialized = [];
            foreach ($existing as $item) {
                $serialized[] = $item->toArray();
            }
            $out = json_encode($serialized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents($this->filePath, $out, LOCK_EX);
        }

        return $importedCount;
    }
}
