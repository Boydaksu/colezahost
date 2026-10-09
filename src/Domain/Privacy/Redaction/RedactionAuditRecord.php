<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Redaction;

use JsonSerializable;

final class RedactionAuditRecord implements JsonSerializable
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly string $domainName,
        private readonly string $actionType,
        private readonly int $recordsAffected,
        private readonly array $redactedFields,
        private readonly string $preChecksum,
        private readonly string $postChecksum,
        private readonly bool $verifiedClean,
        private readonly string $auditedAt,
        private readonly int $auditedBy,
        private readonly string $auditSignature
    ) {
    }

    public static function computeSignature(
        int $userId,
        string $domainName,
        string $actionType,
        int $recordsAffected,
        string $preChecksum,
        string $postChecksum,
        string $auditedAt,
        string $secretKey = 'coleza_redaction_secret'
    ): string {
        $payload = sprintf(
            '%d|%s|%s|%d|%s|%s|%s',
            $userId,
            $domainName,
            $actionType,
            $recordsAffected,
            $preChecksum,
            $postChecksum,
            $auditedAt
        );
        return hash_hmac('sha256', $payload, $secretKey);
    }

    public static function create(
        int $userId,
        string $domainName,
        string $actionType,
        int $recordsAffected,
        array $redactedFields,
        string $preChecksum,
        string $postChecksum,
        bool $verifiedClean,
        int $auditedBy,
        ?string $auditedAt = null,
        ?int $id = null,
        string $secretKey = 'coleza_redaction_secret'
    ): self {
        $timestamp = $auditedAt ?? date('Y-m-d H:i:s');
        $signature = self::computeSignature(
            $userId,
            $domainName,
            $actionType,
            $recordsAffected,
            $preChecksum,
            $postChecksum,
            $timestamp,
            $secretKey
        );

        return new self(
            id: $id,
            userId: $userId,
            domainName: $domainName,
            actionType: $actionType,
            recordsAffected: $recordsAffected,
            redactedFields: $redactedFields,
            preChecksum: $preChecksum,
            postChecksum: $postChecksum,
            verifiedClean: $verifiedClean,
            auditedAt: $timestamp,
            auditedBy: $auditedBy,
            auditSignature: $signature
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

    public function getDomainName(): string
    {
        return $this->domainName;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function getRecordsAffected(): int
    {
        return $this->recordsAffected;
    }

    public function getRedactedFields(): array
    {
        return $this->redactedFields;
    }

    public function getPreChecksum(): string
    {
        return $this->preChecksum;
    }

    public function getPostChecksum(): string
    {
        return $this->postChecksum;
    }

    public function isVerifiedClean(): bool
    {
        return $this->verifiedClean;
    }

    public function getAuditedAt(): string
    {
        return $this->auditedAt;
    }

    public function getAuditedBy(): int
    {
        return $this->auditedBy;
    }

    public function getAuditSignature(): string
    {
        return $this->auditSignature;
    }

    public function verifySignature(string $secretKey = 'coleza_redaction_secret'): bool
    {
        $expected = self::computeSignature(
            $this->userId,
            $this->domainName,
            $this->actionType,
            $this->recordsAffected,
            $this->preChecksum,
            $this->postChecksum,
            $this->auditedAt,
            $secretKey
        );

        return hash_equals($expected, $this->auditSignature);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'domain_name' => $this->domainName,
            'action_type' => $this->actionType,
            'records_affected' => $this->recordsAffected,
            'redacted_fields' => $this->redactedFields,
            'pre_checksum' => $this->preChecksum,
            'post_checksum' => $this->postChecksum,
            'verified_clean' => $this->verifiedClean,
            'audited_at' => $this->auditedAt,
            'audited_by' => $this->auditedBy,
            'audit_signature' => $this->auditSignature,
        ];
    }

    public static function fromArray(array $data): self
    {
        $fields = $data['redacted_fields'] ?? [];
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            userId: (int) $data['user_id'],
            domainName: (string) $data['domain_name'],
            actionType: (string) $data['action_type'],
            recordsAffected: (int) $data['records_affected'],
            redactedFields: $fields,
            preChecksum: (string) $data['pre_checksum'],
            postChecksum: (string) $data['post_checksum'],
            verifiedClean: (bool) $data['verified_clean'],
            auditedAt: (string) $data['audited_at'],
            auditedBy: (int) $data['audited_by'],
            auditSignature: (string) $data['audit_signature']
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
