<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Lists;

use DateTimeImmutable;

final class FraudListEntry
{
    public function __construct(
        private readonly ?int $id,
        private readonly FraudListType $listType,
        private readonly FraudListEntryType $entryType,
        private readonly string $value,
        private readonly string $reason,
        private readonly ?int $createdBy = null,
        private readonly ?DateTimeImmutable $expiresAt = null,
        private readonly bool $isActive = true,
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListType(): FraudListType
    {
        return $this->listType;
    }

    public function getEntryType(): FraudListEntryType
    {
        return $this->entryType;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $current = $now ?? new DateTimeImmutable();
        return $current > $this->expiresAt;
    }

    public function matches(string $candidateValue): bool
    {
        if (!$this->isActive || $this->isExpired()) {
            return false;
        }

        $candidate = trim(strtolower($candidateValue));
        $pattern = trim(strtolower($this->value));

        return match ($this->entryType) {
            FraudListEntryType::IP => $this->matchesIp($pattern, $candidate),
            FraudListEntryType::EMAIL => $this->matchesEmail($pattern, $candidate),
            FraudListEntryType::COUNTRY => strtoupper($pattern) === strtoupper($candidate),
            default => $pattern === $candidate,
        };
    }

    private function matchesIp(string $pattern, string $candidateIp): bool
    {
        if ($pattern === $candidateIp) {
            return true;
        }

        // Subnet CIDR match e.g. 192.168.1.0/24
        if (str_contains($pattern, '/')) {
            [$subnet, $bits] = explode('/', $pattern, 2);
            $bitsInt = (int) $bits;

            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                filter_var($candidateIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $subnetLong = ip2long($subnet);
                $candidateLong = ip2long($candidateIp);
                if ($subnetLong !== false && $candidateLong !== false && $bitsInt >= 0 && $bitsInt <= 32) {
                    $mask = ~((1 << (32 - $bitsInt)) - 1);
                    return ($subnetLong & $mask) === ($candidateLong & $mask);
                }
            }
        }

        return false;
    }

    private function matchesEmail(string $pattern, string $candidateEmail): bool
    {
        if ($pattern === $candidateEmail) {
            return true;
        }

        // Wildcard domain match: *@domain.com or @domain.com or domain.com
        if (str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2);
            return str_ends_with($candidateEmail, '@' . $domain);
        }

        if (str_starts_with($pattern, '@')) {
            return str_ends_with($candidateEmail, $pattern);
        }

        if (!str_contains($pattern, '@') && str_contains($candidateEmail, '@')) {
            $parts = explode('@', $candidateEmail);
            return end($parts) === $pattern;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'list_type' => $this->listType->value,
            'entry_type' => $this->entryType->value,
            'value' => $this->value,
            'reason' => $this->reason,
            'created_by' => $this->createdBy,
            'expires_at' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'is_active' => $this->isActive,
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
