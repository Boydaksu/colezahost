<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

final class DomainRenewalCommand
{
    public function __construct(
        private readonly string $domain,
        private readonly int $years = 1,
        private readonly ?string $currentExpiryDate = null
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

    public function getCurrentExpiryDate(): ?string
    {
        return $this->currentExpiryDate;
    }
}
