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

    public static function computeEmailHash(string $email, #[\SensitiveParameter] ?string $salt = null): string
    {
        $normalized = strtolower(trim($email));
        return hash_hmac('sha256', $normalized, \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('email', $salt));
    }

    public static function computeIntegritySignature(
        int $userId,
        string $emailHash,
        string $erasureChecksum,
        string $tombstonedAt,
        #[\SensitiveParameter] ?string $secretKey = null,
        string $erasureType = 'ANONYMIZE', string $reason = '', array $metadata = []
    ): string {
        return \Coleza\Domain\Privacy\Integrity\PrivacySignature::sign('tombstone', [
            'user_id' => $userId, 'user_email_hash' => $emailHash, 'erasure_type' => $erasureType,
            'reason' => $reason, 'erasure_checksum' => $erasureChecksum, 'metadata' => $metadata, 'tombstoned_at' => $tombstonedAt,
        ], \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('tombstone', $secretKey));
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
        #[\SensitiveParameter] ?string $secretKey = null,
        #[\SensitiveParameter] ?string $salt = null
    ): self {
        $salt = \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('email', $salt);
        $secretKey = \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('tombstone', $secretKey);
        if (hash_equals($salt, $secretKey)) { throw new \RuntimeException('Privacy email and integrity keys must differ.'); }
        $emailKeyId = \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::id($salt);
        if (isset($metadata['privacy_email_key_id']) && $metadata['privacy_email_key_id'] !== $emailKeyId) {
            throw new \InvalidArgumentException('Reserved privacy email key identifier conflicts with configuration.');
        }
        $metadata['privacy_email_key_id'] = $emailKeyId;
        $timestamp = $tombstonedAt ?? date('Y-m-d H:i:s');
        $emailHash = self::computeEmailHash($email, $salt);
        $signature = self::computeIntegritySignature($userId, $emailHash, $erasureChecksum, $timestamp, $secretKey, $erasureType, $reason, $metadata);

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

    public function integrityData(): array
    {
        return ['user_id' => $this->userId, 'user_email_hash' => $this->userEmailHash, 'erasure_type' => $this->erasureType,
            'reason' => $this->reason, 'erasure_checksum' => $this->erasureChecksum, 'metadata' => $this->metadata, 'tombstoned_at' => $this->tombstonedAt];
    }
    public function verifyIntegrity(#[\SensitiveParameter] ?string $secretKey = null): bool
    {
        try {
            $key = \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('tombstone', $secretKey);
            if (str_starts_with($this->integritySignature, \Coleza\Domain\Privacy\Integrity\PrivacySignature::PREFIX)) {
                return \Coleza\Domain\Privacy\Integrity\PrivacySignature::verify('tombstone', $this->integrityData(), $this->integritySignature, $key);
            }
            return false; // Public/default legacy signatures cannot establish authenticity.
        } catch (\Throwable) { return false; }
    }

    public function matchesEmail(string $email, #[\SensitiveParameter] ?string $salt = null): bool
    {
        $key = \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::resolve('email', $salt);
        if (($this->metadata['privacy_email_key_id'] ?? null) !== \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::id($key)) {
            throw new \RuntimeException('Privacy email key changed; matching refused.');
        }
        $hash = self::computeEmailHash($email, $key);
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
            $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
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
