<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

/**
 * Interface for persistent, out-of-band storage of Privacy Tombstones.
 * Ensures tombstones are preserved independently from the operational relational database,
 * preventing data resurrection when a historical database backup is restored.
 */
interface TombstoneStoreInterface
{
    /**
     * Persist a tombstone in the out-of-band storage.
     */
    public function persist(PrivacyTombstone $tombstone): void;

    /**
     * Retrieve all tombstones from out-of-band storage.
     *
     * @return array<int, PrivacyTombstone> Map of userId => PrivacyTombstone
     */
    public function loadAll(): array;

    /**
     * Find a tombstone by user ID.
     */
    public function findByUserId(int $userId): ?PrivacyTombstone;

    /**
     * Check if a user ID is tombstoned in out-of-band storage.
     */
    public function hasUserId(int $userId): bool;

    /**
     * Export all tombstones as an integrity-sealed JSON manifest.
     */
    public function exportManifest(): string;

    /**
     * Import tombstones from a JSON manifest.
     *
     * @return int Count of newly imported tombstones
     */
    public function importManifest(string $json): int;
}
