<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

interface RemoteIdentityVerifierInterface
{
    /**
     * Checks if a hosting account exists on the target server/hypervisor
     * using read-only API calls without modifying state.
     */
    public function verifyServerAccountExists(
        string|int $serverIdentifier,
        string $username,
        ?string $domain = null
    ): bool;

    /**
     * Checks if a domain exists at the registrar using read-only API calls
     * without modifying state or incurring registration fees.
     */
    public function verifyDomainRegistration(
        string $registrar,
        string $domain
    ): bool;
}
