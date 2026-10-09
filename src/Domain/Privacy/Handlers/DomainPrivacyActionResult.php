<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Handlers;

use JsonSerializable;

final class DomainPrivacyActionResult implements JsonSerializable
{
    public function __construct(
        private readonly string $domainName,
        private readonly string $action,
        private readonly int $recordsAffected,
        private readonly array $fieldsRedacted,
        private readonly array $details = [],
        private readonly bool $success = true,
        private readonly string $preChecksum = '',
        private readonly string $postChecksum = ''
    ) {
    }

    public function getDomainName(): string
    {
        return $this->domainName;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getRecordsAffected(): int
    {
        return $this->recordsAffected;
    }

    public function getFieldsRedacted(): array
    {
        return $this->fieldsRedacted;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getPreChecksum(): string
    {
        return $this->preChecksum;
    }

    public function getPostChecksum(): string
    {
        return $this->postChecksum;
    }

    public function toArray(): array
    {
        return [
            'domain_name' => $this->domainName,
            'action' => $this->action,
            'records_affected' => $this->recordsAffected,
            'fields_redacted' => $this->fieldsRedacted,
            'details' => $this->details,
            'success' => $this->success,
            'pre_checksum' => $this->preChecksum,
            'post_checksum' => $this->postChecksum,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
