<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Export;

use DateTimeImmutable;

final class PrivacyExportPackage
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly int $userId,
        private readonly DateTimeImmutable $generatedAt,
        private readonly array $data,
        private readonly string $checksum
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(int $userId, array $data, ?DateTimeImmutable $generatedAt = null): self
    {
        $timestamp = $generatedAt ?? new DateTimeImmutable();
        $payload = [
            'schema' => 'colezahost_gdpr_export_v1',
            'user_id' => $userId,
            'generated_at' => $timestamp->format('Y-m-d H:i:s'),
            'data' => $data,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $checksum = hash('sha256', $json);

        return new self(
            userId: $userId,
            generatedAt: $timestamp,
            data: $data,
            checksum: $checksum
        );
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getGeneratedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function verifyIntegrity(): bool
    {
        $payload = [
            'schema' => 'colezahost_gdpr_export_v1',
            'user_id' => $this->userId,
            'generated_at' => $this->generatedAt->format('Y-m-d H:i:s'),
            'data' => $this->data,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $computed = hash('sha256', $json);

        return hash_equals($this->checksum, $computed);
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => 'colezahost_gdpr_export_v1',
            'user_id' => $this->userId,
            'generated_at' => $this->generatedAt->format('Y-m-d H:i:s'),
            'checksum' => $this->checksum,
            'data' => $this->data,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 'colezahost_gdpr_export_v1',
            'user_id' => $this->userId,
            'generated_at' => $this->generatedAt->format('Y-m-d H:i:s'),
            'checksum' => $this->checksum,
            'data' => $this->data,
        ];
    }
}
