<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

use Coleza\Domain\Domains\DomainContact;

final class DomainRegistrationCommand
{
    /**
     * @param array<int, string> $nameservers
     * @param array<string, DomainContact> $contacts
     * @param array<string, mixed> $additionalFields
     */
    public function __construct(
        private readonly string $domain,
        private readonly int $years = 1,
        private readonly array $nameservers = [],
        private readonly array $contacts = [],
        private readonly bool $whoisPrivacy = false,
        private readonly bool $autoRenew = true,
        private readonly array $additionalFields = []
    ) {
    }

    public function getDomain(): string
    {
        return strtolower(trim($this->domain));
    }

    public function getYears(): int
    {
        return $this->years;
    }

    /**
     * @return array<int, string>
     */
    public function getNameservers(): array
    {
        return $this->nameservers;
    }

    /**
     * @return array<string, DomainContact>
     */
    public function getContacts(): array
    {
        return $this->contacts;
    }

    public function getContact(string $type): ?DomainContact
    {
        return $this->contacts[$type] ?? null;
    }

    public function isWhoisPrivacy(): bool
    {
        return $this->whoisPrivacy;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalFields(): array
    {
        return $this->additionalFields;
    }
}
