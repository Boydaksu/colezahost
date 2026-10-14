<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use Coleza\Domain\Privacy\Integrity\{PrivacySignature, PrivacySigningKey};
use RuntimeException;

/** Authenticated inventory. Whole read/merge/write is serialized by one file lock. */
final class FileTombstoneStore implements TombstoneStoreInterface
{
    public function __construct(
        private readonly string $filePath,
        #[\SensitiveParameter] private readonly ?string $secretKey = null
    ) {}

    private function decode(string $content, string $key): array
    {
        if (!str_starts_with(ltrim($content), '[')) { throw new RuntimeException('Invalid tombstone inventory array.'); }
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException('Invalid tombstone inventory.');
        }
        $records = [];
        foreach ($data as $item) {
            if (!is_array($item)) { throw new RuntimeException('Invalid tombstone record.'); }
            $record = PrivacyTombstone::fromArray($item);
            if ($record->getUserId() <= 0 || !$record->verifyIntegrity($key) || isset($records[$record->getUserId()])) {
                throw new RuntimeException('Unverified or duplicate tombstone; inventory refused.');
            }
            $records[$record->getUserId()] = $record;
        }
        return $records;
    }

    private function identical(PrivacyTombstone $first, PrivacyTombstone $second): bool
    {
        return PrivacySignature::encode($first->integrityData()) === PrivacySignature::encode($second->integrityData())
            && hash_equals($first->getIntegritySignature(), $second->getIntegritySignature());
    }

    private function merge(array $incoming): int
    {
        $key = PrivacySigningKey::resolve('tombstone', $this->secretKey);
        // Validate every incoming record before touching the destination.
        $incoming = $this->decode(PrivacySignature::encode(array_values($incoming)), $key);
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create tombstone inventory directory.');
        }
        $created = false;
        $handle = false;
        if (!file_exists($this->filePath)) { $handle = @fopen($this->filePath, 'x+b'); $created = $handle !== false; }
        if ($handle === false) { $handle = fopen($this->filePath, 'r+b'); }
        if ($handle === false) { throw new RuntimeException('Cannot open tombstone inventory.'); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Cannot lock tombstone inventory.'); }
            $content = stream_get_contents($handle);
            if ($content === false) { throw new RuntimeException('Cannot read tombstone inventory.'); }
            // Only an exclusively created file is initialization; existing empty files are corruption.
            $existing = $created && $content === '' ? [] : $this->decode($content, $key);
            $count = 0;
            foreach ($incoming as $userId => $record) {
                if (isset($existing[$userId])) {
                    if (!$this->identical($existing[$userId], $record)) {
                        throw new RuntimeException('Conflicting tombstone; replacement refused.');
                    }
                } else { $existing[$userId] = $record; $count++; }
            }
            if ($count > 0 || $content === '') {
                $json = json_encode(array_map(static fn (PrivacyTombstone $item): array => $item->toArray(), array_values($existing)),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                rewind($handle);
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($handle, substr($json, $offset));
                    if ($written === false || $written === 0) { throw new RuntimeException('Cannot write tombstone inventory.'); }
                    $offset += $written;
                }
                if (!ftruncate($handle, strlen($json)) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('Cannot flush tombstone inventory.');
                }
            }
            return $count;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function persist(PrivacyTombstone $tombstone): void { $this->merge([$tombstone->toArray()]); }

    public function loadAll(): array
    {
        if (!file_exists($this->filePath)) { return []; }
        $key = PrivacySigningKey::resolve('tombstone', $this->secretKey);
        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) { throw new RuntimeException('Cannot open tombstone inventory.'); }
        try {
            if (!flock($handle, LOCK_SH)) { throw new RuntimeException('Cannot lock tombstone inventory.'); }
            $content = stream_get_contents($handle);
            if ($content === false) { throw new RuntimeException('Cannot read tombstone inventory.'); }
            return $this->decode($content, $key);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function findByUserId(int $userId): ?PrivacyTombstone { return $this->loadAll()[$userId] ?? null; }
    public function hasUserId(int $userId): bool { return isset($this->loadAll()[$userId]); }

    public function exportManifest(): string
    {
        $payload = ['version' => 1, 'exported_at' => date('Y-m-d H:i:s'), 'count' => count($all = $this->loadAll()),
            'tombstones' => array_map(static fn (PrivacyTombstone $item): array => $item->toArray(), array_values($all))];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $payload['manifest_checksum'] = hash('sha256', $json);
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function importManifest(string $json): int
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !is_array($decoded['tombstones'] ?? null)
            || !array_is_list($decoded['tombstones']) || ($decoded['count'] ?? null) !== count($decoded['tombstones'])
            || !is_string($decoded['manifest_checksum'] ?? null)) { throw new RuntimeException('Invalid tombstone manifest.'); }
        $checksum = $decoded['manifest_checksum']; unset($decoded['manifest_checksum']);
        $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (!hash_equals(hash('sha256', $body), $checksum)) { throw new RuntimeException('Tombstone manifest checksum mismatch.'); }
        return $this->merge($decoded['tombstones']); // Checksum alone never establishes authenticity.
    }
}
