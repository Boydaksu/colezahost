<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

use Coleza\Domain\Domains\DomainContact;

final class DomainTransferCommand
{
    /**
     * @param array<int, string> $nameservers
     * @param array<string, DomainContact> $contacts
     */
    public function __construct(
        private readonly string $domain,
        private readonly string $eppCode,
        private readonly array $nameservers = [],
        private readonly array $contacts = [],
        private readonly bool $whoisPrivacy = false
    ) {
    }

    public function getDomain(): string
    {
        return strtolower(trim($this->domain));
    }

    public function getEppCode(): string
    {
        return trim($this->eppCode);
    }

    public function getAuthCode(): string
    {
        return $this->getEppCode();
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

    public function isWhoisPrivacy(): bool
    {
        return $this->whoisPrivacy;
    }

    public function hasWhoisPrivacy(): bool
    {
        return $this->whoisPrivacy;
    }
}
