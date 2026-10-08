<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

use Coleza\Domain\Domains\DomainContact;

interface RegistrarProviderInterface
{
    /**
     * Unique identifier slug for this registrar provider (e.g. 'namesilo', 'resellerclub', 'mock').
     */
    public function getRegistrarId(): string;

    /**
     * Human-readable display name for this registrar provider.
     */
    public function getName(): string;

    /**
     * Determine if this provider supports a specific capability.
     */
    public function supportsCapability(string $capability): bool;

    /**
     * @return array<int, string> List of supported RegistrarCapability constants
     */
    public function getSupportedCapabilities(): array;

    /**
     * Check if a domain name is available for registration.
     */
    public function checkAvailability(string $domain): DomainAvailabilityResult;

    /**
     * Register a new domain name with the remote registrar.
     */
    public function registerDomain(DomainRegistrationCommand $command): RegistrarOperationResult;

    /**
     * Renew an existing domain name with the remote registrar.
     */
    public function renewDomain(DomainRenewalCommand $command): RegistrarOperationResult;

    /**
     * Initiate transfer of an existing domain name into the remote registrar.
     */
    public function transferDomain(DomainTransferCommand $command): RegistrarOperationResult;

    /**
     * Get nameservers assigned to the domain at the remote registrar.
     *
     * @return array<int, string>
     */
    public function getNameservers(string $domain): array;

    /**
     * Update nameservers for the domain at the remote registrar.
     *
     * @param array<int, string> $nameservers
     */
    public function updateNameservers(string $domain, array $nameservers): RegistrarOperationResult;

    /**
     * Get registrar transfer lock status.
     */
    public function getRegistrarLock(string $domain): bool;

    /**
     * Update registrar transfer lock status.
     */
    public function setRegistrarLock(string $domain, bool $locked): RegistrarOperationResult;

    /**
     * Get EPP transfer authorization code for the domain.
     */
    public function getEppCode(string $domain): ?string;

    /**
     * Get contact profiles for the domain from the remote registrar.
     *
     * @return array<string, DomainContact>
     */
    public function getContacts(string $domain): array;

    /**
     * Update contact profiles for the domain at the remote registrar.
     *
     * @param array<string, DomainContact> $contacts
     */
    public function updateContacts(string $domain, array $contacts): RegistrarOperationResult;
}
