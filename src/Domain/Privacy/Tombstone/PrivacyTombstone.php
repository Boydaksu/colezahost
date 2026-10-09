<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Tombstone;

use JsonSerializable;

/**
 * Immutable Privacy Tombstone record guaranteeing that customer erasure
 * decisions permanently persist across system wipes, migrations, and database backup restores.
 */
final class PrivacyTombstone implements JsonSerializable
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly string $userEmailHash,
        private readonly string $erasureType,
        private readonly string $reason,
        private readonly string $erasureChecksum,
        private readonly array $metadata,
        private readonly string $tombstonedAt,
        private readonly string $integritySignature
    ) {
    }

    public static function computeEmailHash(string $email, string $salt = 'coleza_privacy_salt'): string
    {
        $normalized = strtolower(trim($email));
        return hash_hmac('sha256', $normalized, $salt);
    }

    public static function computeIntegritySignature(
        int $userId,
        string $emailHash,
        string $erasureChecksum,
        string $tombstonedAt,
        string $secretKey = 'coleza_tombstone_key'
    ): string {
        $payload = sprintf('%d|%s|%s|%s', $userId, $emailHash, $erasureChecksum, $tombstonedAt);
        return hash_hmac('sha256', $payload, $secretKey);
    }

    public static function create(
        int $userId,
        string $email,
        string $erasureType,
        string $reason,
        string $erasureChecksum,
        array $metadata = [],
        ?string $tombstonedAt = null,
        ?int $id = null,
        string $secretKey = 'coleza_tombstone_key',
        string $salt = 'coleza_privacy_salt'
    ): self {
        $timestamp = $tombstonedAt ?? date('Y-m-d H:i:s');
        $emailHash = self::computeEmailHash($email, $salt);
        $signature = self::computeIntegritySignature($userId, $emailHash, $erasureChecksum, $timestamp, $secretKey);

        return new self(
            id: $id,
            userId: $userId,
            userEmailHash: $emailHash,
            erasureType: $erasureType,
            reason: $reason,
            erasureChecksum: $erasureChecksum,
            metadata: $metadata,
            tombstonedAt: $timestamp,
            integritySignature: $signature
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserEmailHash(): string
    {
        return $this->userEmailHash;
    }

    public function getErasureType(): string
    {
        return $this->erasureType;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getErasureChecksum(): string
    {
        return $this->erasureChecksum;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTombstonedAt(): string
    {
        return $this->tombstonedAt;
    }

    public function getIntegritySignature(): string
    {
        return $this->integritySignature;
    }

    public function verifyIntegrity(string $secretKey = 'coleza_tombstone_key'): bool
    {
        $expected = self::computeIntegritySignature(
            $this->userId,
            $this->userEmailHash,
            $this->erasureChecksum,
            $this->tombstonedAt,
            $secretKey
        );

        return hash_equals($expected, $this->integritySignature);
    }

    public function matchesEmail(string $email, string $salt = 'coleza_privacy_salt'): bool
    {
        $hash = self::computeEmailHash($email, $salt);
        return hash_equals($this->userEmailHash, $hash);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'user_email_hash' => $this->userEmailHash,
            'erasure_type' => $this->erasureType,
            'reason' => $this->reason,
            'erasure_checksum' => $this->erasureChecksum,
            'metadata' => $this->metadata,
            'tombstoned_at' => $this->tombstonedAt,
            'integrity_signature' => $this->integritySignature,
        ];
    }

    public static function fromArray(array $data): self
    {
        $metadata = $data['metadata'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            userId: (int) $data['user_id'],
            userEmailHash: (string) $data['user_email_hash'],
            erasureType: (string) $data['erasure_type'],
            reason: (string) $data['reason'],
            erasureChecksum: (string) $data['erasure_checksum'],
            metadata: $metadata,
            tombstonedAt: (string) $data['tombstoned_at'],
            integritySignature: (string) $data['integrity_signature']
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
