<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\Domain;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Foundation\Exceptions\ValidationException;
use Throwable;

final class DomainRegistrarService
{
    public function __construct(
        private readonly DomainService $domainService,
        private readonly RegistrarRegistry $registrarRegistry,
        private readonly ?DomainCatalogService $catalogService = null
    ) {
    }

    /**
     * Check domain registration availability across catalog policy and registrar.
     */
    public function checkAvailability(string $domainName, ?string $registrarId = null): DomainAvailabilityResult
    {
        $domainName = strtolower(trim($domainName));

        // 1. Catalog syntax & TLD validation
        if ($this->catalogService !== null) {
            $validation = $this->catalogService->validateDomainName($domainName);
            if (!$validation->isValid()) {
                return DomainAvailabilityResult::unavailable($domainName, $validation->getFirstError());
            }

            $split = $this->catalogService->splitDomain($domainName);
            $tldExt = $split['tld'];
            $tldEntity = $this->catalogService->findTldByExtension($tldExt);
            if ($tldEntity === null || !$tldEntity->isActive()) {
                return DomainAvailabilityResult::unavailable($domainName, "TLD '{$tldExt}' is not offered or currently disabled.");
            }
        }

        // 2. Resolve registrar adapter
        $registrar = $this->resolveRegistrar($registrarId);
        if (!$registrar->supportsCapability(RegistrarCapability::AVAILABILITY_CHECK)) {
            return DomainAvailabilityResult::unavailable($domainName, "Registrar does not support availability check.");
        }

        // 3. Query registrar
        try {
            $result = $registrar->checkAvailability($domainName);

            // If available and catalog service available, enrich pricing
            if ($result->isAvailable() && $this->catalogService !== null && $result->getPrice() === null) {
                $split = $this->catalogService->splitDomain($domainName);
                $tldExt = $split['tld'];
                try {
                    $regMinor = $this->catalogService->calculateRegistrationPrice($tldExt, 1, 'USD');
                    return DomainAvailabilityResult::available(
                        domain: $domainName,
                        price: $regMinor / 100.0,
                        currency: 'USD'
                    );
                } catch (Throwable) {
                    // pricing not configured, keep original result
                }
            }

            return $result;
        } catch (Throwable $e) {
            return DomainAvailabilityResult::unavailable($domainName, 'Registrar lookup error: ' . $e->getMessage());
        }
    }

    /**
     * Check availability for multiple domains or TLD variations.
     *
     * @param list<string> $domainNames
     * @return array<string, DomainAvailabilityResult>
     */
    public function bulkCheckAvailability(array $domainNames, ?string $registrarId = null): array
    {
        $results = [];
        foreach ($domainNames as $domain) {
            $results[$domain] = $this->checkAvailability($domain, $registrarId);
        }
        return $results;
    }

    /**
     * Complete domain registration flow with registrar and domain lifecycle update.
     */
    public function registerDomain(
        int $domainId,
        ?string $registrarId = null,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        if ($domain->getStatus() !== DomainStateMachine::STATUS_PENDING_REGISTRATION) {
            throw new ValidationException(
                ['status' => "Cannot register domain with status '{$domain->getStatus()}'. Must be 'pending_registration'."],
                'Invalid domain status'
            );
        }

        $targetRegistrarId = $registrarId ?? $domain->getRegistrarId();
        $registrar = $this->resolveRegistrar($targetRegistrarId);

        if (!$registrar->supportsCapability(RegistrarCapability::REGISTER)) {
            return RegistrarOperationResult::failure(
                operation: 'register',
                domain: $domain->getDomain(),
                errorCode: 'UNSUPPORTED_CAPABILITY',
                errorMessage: "Registrar '{$registrar->getRegistrarId()}' does not support registration."
            );
        }

        $contacts = $this->domainService->getAllContacts($domainId);

        $command = new DomainRegistrationCommand(
            domain: $domain->getDomain(),
            years: $domain->getRegistrationPeriodYears(),
            nameservers: $domain->getNameservers(),
            contacts: $contacts,
            whoisPrivacy: $domain->isWhoisPrivacy(),
            autoRenew: $domain->isAutoRenew()
        );

        try {
            $result = $registrar->registerDomain($command);

            if ($result->isSuccessful()) {
                // Update registrar ID if needed
                if ($domain->getRegistrarId() !== $registrar->getRegistrarId()) {
                    $this->domainService->updateDomain($domainId, ['registrar_id' => $registrar->getRegistrarId()]);
                }

                // Activate domain asset
                $this->domainService->activateDomain(
                    id: $domainId,
                    expiryDate: $result->getExpirationDate(),
                    actorType: $actorType,
                    actorId: $actorId
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_register_success',
                    description: "Domain successfully registered with {$registrar->getName()}. Order/Transaction: {$result->getRemoteTransactionId()}",
                    payload: [
                        'remote_transaction_id' => $result->getRemoteTransactionId(),
                        'registrar' => $registrar->getRegistrarId(),
                        'expiration_date' => $result->getExpirationDate(),
                    ],
                    actorType: $actorType,
                    actorId: $actorId
                );
            } else {
                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_register_failed',
                    description: "Domain registration failed at {$registrar->getName()}: {$result->getErrorMessage()}",
                    payload: [
                        'error_code' => $result->getErrorCode(),
                        'error_message' => $result->getErrorMessage(),
                        'registrar' => $registrar->getRegistrarId(),
                    ],
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $result;
        } catch (Throwable $e) {
            $this->domainService->recordTimelineEvent(
                domainId: $domainId,
                eventType: 'registrar_register_failed',
                description: "Unexpected registration error with {$registrar->getName()}: {$e->getMessage()}",
                payload: ['exception' => $e->getMessage()],
                actorType: $actorType,
                actorId: $actorId
            );

            return RegistrarOperationResult::failure(
                operation: 'register',
                domain: $domain->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Domain renewal flow extending expiration date at registrar and locally.
     */
    public function renewDomain(
        int $domainId,
        int $years = 1,
        ?string $registrarId = null,
        string $actorType = 'automation',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        if (!in_array($domain->getStatus(), [DomainStateMachine::STATUS_ACTIVE, DomainStateMachine::STATUS_GRACE, DomainStateMachine::STATUS_EXPIRED], true)) {
            throw new ValidationException(
                ['status' => "Cannot renew domain with status '{$domain->getStatus()}'."],
                'Invalid domain status'
            );
        }

        $targetRegistrarId = $registrarId ?? $domain->getRegistrarId();
        $registrar = $this->resolveRegistrar($targetRegistrarId);

        if (!$registrar->supportsCapability(RegistrarCapability::RENEW)) {
            return RegistrarOperationResult::failure(
                operation: 'renew',
                domain: $domain->getDomain(),
                errorCode: 'UNSUPPORTED_CAPABILITY',
                errorMessage: "Registrar '{$registrar->getRegistrarId()}' does not support renewal."
            );
        }

        $command = new DomainRenewalCommand($domain->getDomain(), $years);

        try {
            $result = $registrar->renewDomain($command);

            if ($result->isSuccessful()) {
                $this->domainService->renewDomain(
                    id: $domainId,
                    years: $years,
                    actorType: $actorType,
                    actorId: $actorId
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_renew_success',
                    description: "Domain renewed for {$years} year(s) at {$registrar->getName()}.",
                    payload: [
                        'remote_transaction_id' => $result->getRemoteTransactionId(),
                        'years' => $years,
                        'registrar' => $registrar->getRegistrarId(),
                    ],
                    actorType: $actorType,
                    actorId: $actorId
                );
            } else {
                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_renew_failed',
                    description: "Domain renewal failed at {$registrar->getName()}: {$result->getErrorMessage()}",
                    payload: [
                        'error_code' => $result->getErrorCode(),
                        'error_message' => $result->getErrorMessage(),
                    ],
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $result;
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'renew',
                domain: $domain->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Domain transfer flow initiating inbound transfer at registrar.
     */
    public function transferDomain(
        int $domainId,
        string $eppCode,
        ?string $registrarId = null,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        if (trim($eppCode) === '') {
            throw new ValidationException(['epp_code' => 'EPP transfer authorization code is required.'], 'Validation error');
        }

        $targetRegistrarId = $registrarId ?? $domain->getRegistrarId();
        $registrar = $this->resolveRegistrar($targetRegistrarId);

        if (!$registrar->supportsCapability(RegistrarCapability::TRANSFER)) {
            return RegistrarOperationResult::failure(
                operation: 'transfer',
                domain: $domain->getDomain(),
                errorCode: 'UNSUPPORTED_CAPABILITY',
                errorMessage: "Registrar '{$registrar->getRegistrarId()}' does not support transfer."
            );
        }

        $command = new DomainTransferCommand(
            domain: $domain->getDomain(),
            eppCode: $eppCode,
            nameservers: $domain->getNameservers(),
            contacts: $this->domainService->getAllContacts($domainId),
            whoisPrivacy: $domain->isWhoisPrivacy()
        );

        try {
            $result = $registrar->transferDomain($command);

            if ($result->isSuccessful()) {
                $this->domainService->transitionStatus(
                    id: $domainId,
                    newStatus: DomainStateMachine::STATUS_PENDING_TRANSFER,
                    reason: 'Inbound transfer initiated at registrar.',
                    actorType: $actorType,
                    actorId: $actorId
                );

                $this->domainService->updateDomain($domainId, [
                    'epp_code' => $eppCode,
                    'registrar_id' => $registrar->getRegistrarId(),
                ]);

                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_transfer_initiated',
                    description: "Domain transfer initiated with {$registrar->getName()}.",
                    payload: [
                        'remote_transaction_id' => $result->getRemoteTransactionId(),
                        'registrar' => $registrar->getRegistrarId(),
                    ],
                    actorType: $actorType,
                    actorId: $actorId
                );
            } else {
                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_transfer_failed',
                    description: "Domain transfer failed at {$registrar->getName()}: {$result->getErrorMessage()}",
                    payload: [
                        'error_code' => $result->getErrorCode(),
                        'error_message' => $result->getErrorMessage(),
                    ],
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $result;
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'transfer',
                domain: $domain->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Update nameservers at registrar and synchronize local domain entity.
     *
     * @param list<string> $nameservers
     */
    public function updateNameservers(
        int $domainId,
        array $nameservers,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $registrar = $this->resolveRegistrar($domain->getRegistrarId());
        if (!$registrar->supportsCapability(RegistrarCapability::UPDATE_NAMESERVERS)) {
            return RegistrarOperationResult::failure(
                operation: 'update_nameservers',
                domain: $domain->getDomain(),
                errorCode: 'UNSUPPORTED_CAPABILITY',
                errorMessage: "Registrar does not support nameserver updates."
            );
        }

        try {
            $result = $registrar->updateNameservers($domain->getDomain(), $nameservers);

            if ($result->isSuccessful()) {
                $this->domainService->updateNameservers(
                    id: $domainId,
                    nameservers: $nameservers,
                    actorType: $actorType,
                    actorId: $actorId
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_nameservers_synced',
                    description: "Nameservers synced with registrar: " . implode(', ', $nameservers),
                    payload: ['nameservers' => $nameservers],
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $result;
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'update_nameservers',
                domain: $domain->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Set transfer lock status at registrar and update local domain asset.
     */
    public function setRegistrarLock(
        int $domainId,
        bool $locked,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $registrar = $this->resolveRegistrar($domain->getRegistrarId());
        if (!$registrar->supportsCapability(RegistrarCapability::SET_LOCK)) {
            return RegistrarOperationResult::failure(
                operation: 'set_lock',
                domain: $domain->getDomain(),
                errorCode: 'UNSUPPORTED_CAPABILITY',
                errorMessage: "Registrar does not support lock management."
            );
        }

        try {
            $result = $registrar->setRegistrarLock($domain->getDomain(), $locked);

            if ($result->isSuccessful()) {
                $this->domainService->setRegistrarLock(
                    id: $domainId,
                    locked: $locked,
                    actorType: $actorType,
                    actorId: $actorId
                );

                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_lock_synced',
                    description: "Registrar lock set to " . ($locked ? 'LOCKED' : 'UNLOCKED') . " at {$registrar->getName()}.",
                    payload: ['is_locked' => $locked],
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $result;
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'set_lock',
                domain: $domain->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Retrieve EPP authorization code from registrar and persist locally.
     */
    public function retrieveEppCode(
        int $domainId,
        string $actorType = 'user',
        ?int $actorId = null
    ): ?string {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $registrar = $this->resolveRegistrar($domain->getRegistrarId());
        if (!$registrar->supportsCapability(RegistrarCapability::GET_EPP_CODE)) {
            return null;
        }

        try {
            $eppCode = $registrar->getEppCode($domain->getDomain());
            if ($eppCode !== null && $eppCode !== '') {
                $this->domainService->setEppCode($domainId, $eppCode);

                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_epp_retrieved',
                    description: "EPP authorization code retrieved from {$registrar->getName()}.",
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $eppCode;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Push locally configured contacts to remote registrar.
     */
    public function syncContactsToRegistrar(
        int $domainId,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $registrar = $this->resolveRegistrar($domain->getRegistrarId());
        if (!$registrar->supportsCapability(RegistrarCapability::UPDATE_CONTACTS)) {
            return RegistrarOperationResult::failure(
                operation: 'update_contacts',
                domain: $domain->getDomain(),
                errorCode: 'UNSUPPORTED_CAPABILITY',
                errorMessage: "Registrar does not support contact updates."
            );
        }

        $contacts = $this->domainService->getAllContacts($domainId);

        try {
            $result = $registrar->updateContacts($domain->getDomain(), $contacts);

            if ($result->isSuccessful()) {
                $this->domainService->recordTimelineEvent(
                    domainId: $domainId,
                    eventType: 'registrar_contacts_synced',
                    description: "Domain contact profiles successfully synchronized with {$registrar->getName()}.",
                    actorType: $actorType,
                    actorId: $actorId
                );
            }

            return $result;
        } catch (Throwable $e) {
            return RegistrarOperationResult::failure(
                operation: 'update_contacts',
                domain: $domain->getDomain(),
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Pull remote registrar state (nameservers, lock status) and synchronize local domain.
     */
    public function syncDomainFromRegistrar(
        int $domainId,
        string $actorType = 'system',
        ?int $actorId = null
    ): Domain {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $registrar = $this->resolveRegistrar($domain->getRegistrarId());

        $remoteNs = $registrar->getNameservers($domain->getDomain());
        $remoteLock = $registrar->getRegistrarLock($domain->getDomain());

        $updates = ['is_locked' => $remoteLock];
        if (!empty($remoteNs)) {
            $updates['nameservers'] = $remoteNs;
        }

        $updatedDomain = $this->domainService->updateDomain($domainId, $updates);

        $this->domainService->recordTimelineEvent(
            domainId: $domainId,
            eventType: 'registrar_synced',
            description: "Synchronized state from {$registrar->getName()}.",
            payload: [
                'remote_nameservers' => $remoteNs,
                'remote_lock' => $remoteLock,
            ],
            actorType: $actorType,
            actorId: $actorId
        );

        return $updatedDomain;
    }

    private function resolveRegistrar(?string $registrarId): RegistrarProviderInterface
    {
        if ($registrarId !== null && $registrarId !== '') {
            return $this->registrarRegistry->get($registrarId);
        }

        return $this->registrarRegistry->getDefault();
    }
}
