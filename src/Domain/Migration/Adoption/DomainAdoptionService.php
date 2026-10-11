<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

use Coleza\Domain\Migration\Canonical\CanonicalDomainDto;
use Coleza\Foundation\Database\Connection;

final class DomainAdoptionService
{
    private string $domainsTable = 'domains';

    public function __construct(
        private Connection $db,
        private AdoptedIdentityRepository $identityRepo,
        private ProviderIdentityResolver $resolver,
        private ?RemoteIdentityVerifierInterface $remoteVerifier = null
    ) {
    }

    public function ensureDomainsTable(): void
    {
        (new \Coleza\Domain\Domains\DomainService($this->db))->ensureTables();
        AdoptionSchema::ensure($this->db);
    }

    /**
     * Adopts an existing registered domain without triggering registrar registration API calls or charges.
     */
    public function adoptDomain(
        CanonicalDomainDto $domainDto,
        string $batchId,
        ?string $sourceRegistrarKey = null,
        bool $verifyRemote = false
    ): DomainAdoptionResult {
        $this->ensureDomainsTable();
        $this->identityRepo->ensureTable();

        $errors = [];
        $warnings = [];

        // 1. Resolve client/user ID
        $userId = $this->resolver->resolveClient($domainDto->getClientSourceId());
        if ($userId === null) {
            $errors[] = sprintf(
                'Cannot adopt domain [%s]: Client source ID [%s] has not been resolved to a Coleza user ID.',
                $domainDto->getSourceId(),
                $domainDto->getClientSourceId()
            );
            return DomainAdoptionResult::failed($errors);
        }

        $domainName = strtolower(trim($domainDto->getDomainName()));
        $regKey = $sourceRegistrarKey ?? $domainDto->getRegistrar() ?? 'manual';
        $targetRegistrar = $this->resolver->resolveRegistrar($regKey, defaultRegistrar: $regKey);

        // 3. Optional remote read-only registrar check
        $verificationPassed = false;
        if ($verifyRemote && $this->remoteVerifier !== null) {
            $verificationPassed = $this->remoteVerifier->verifyDomainRegistration(
                $targetRegistrar,
                $domainName
            );

            if (!$verificationPassed) {
                $warnings[] = sprintf(
                    'Warning: Domain [%s] could not be verified at registrar [%s]. Adopting in unverified state.',
                    $domainName,
                    $targetRegistrar
                );
            }
        }

        // 4. Insert adopted domain record WITHOUT registrar API registration
        $meta = array_merge($domainDto->getMetadata(), [
            'adopted_from' => $domainDto->getSourceSystem(),
            'is_adopted' => true,
            'source_domain_id' => $domainDto->getSourceId(),
            'registrar_api_suppressed' => true,
        ]);

        $meta['adopted_billing'] = ['recurring_amount' => $domainDto->getRecurringAmount(), 'currency_code' => $domainDto->getCurrency()];
        return $this->db->transaction(function () use ($domainDto, $batchId, $userId, $domainName, $targetRegistrar, $meta, $verificationPassed, $warnings): DomainAdoptionResult {
            $this->db->lockRow('migration_adoption_lock', 1);
            // 2. Check for conflict/duplicate
            $existingIdentity = $this->identityRepo->findByExternalReference(
                AdoptedIdentityType::DOMAIN_REGISTRATION,
                $targetRegistrar,
                $domainName
            );

            if ($existingIdentity !== null) {
                // Idempotent return if same source ID
                if ($existingIdentity->getSourceId() === $domainDto->getSourceId() && $existingIdentity->getSourceSystem() === $domainDto->getSourceSystem() && (int) ($this->db->selectOne('SELECT user_id FROM domains WHERE id = ?', [$existingIdentity->getTargetEntityId()])['user_id'] ?? 0) === $userId) {
                    return DomainAdoptionResult::successful(
                        adoptedDomainId: $existingIdentity->getTargetEntityId(),
                        identity: $existingIdentity,
                        warnings: ['Domain was already adopted previously under this registrar/domain name.']
                    );
                }

                $errors = [sprintf(
                    'Conflict: Domain name [%s] is already adopted from source entity [%s].',
                    $domainName,
                    $existingIdentity->getSourceId()
                )];
                return DomainAdoptionResult::failed($errors);
            }

            $domains = new \Coleza\Domain\Domains\DomainService($this->db);
            $domain = $domains->createDomain([
                'user_id' => $userId, 'domain' => $domainName, 'registrar_id' => $targetRegistrar,
                'status' => $domainDto->getStatus(), 'registration_period_years' => $domainDto->getRegistrationPeriodYears(),
                'auto_renew' => $domainDto->isAutoRenew(), 'whois_privacy' => $domainDto->hasIdProtection(),
                'nameservers' => $domainDto->getNameServers(), 'metadata' => $meta, 'actor_type' => 'migration',
            ]);
            $adoptedDomainId = $domain->getId();
            $this->db->update('domains', [
                'registration_date' => $domainDto->getRegistrationDate(), 'expiry_date' => $domainDto->getExpiryDate(),
                'next_due_date' => $domainDto->getNextDueDate(),
            ], 'id = :id', ['id' => $adoptedDomainId]);

            // 5. Record adopted provider identity link
            $providerIdentity = new AdoptedProviderIdentity(
                id: null,
                batchId: $batchId,
                identityType: AdoptedIdentityType::DOMAIN_REGISTRATION,
                sourceId: $domainDto->getSourceId(),
                sourceSystem: $domainDto->getSourceSystem(),
                targetEntityId: $adoptedDomainId,
                providerType: 'registrar',
                providerIdentifier: $targetRegistrar,
                externalReferenceId: $domainName,
                externalMetadata: [
                    'expiry_date' => $domainDto->getExpiryDate(),
                    'nameservers' => $domainDto->getNameServers(),
                    'auto_renew' => $domainDto->isAutoRenew(),
                ],
                remoteVerificationPassed: $verificationPassed,
                suppressProvisioning: true,
                adoptedAt: date('Y-m-d H:i:s')
            );

            $this->identityRepo->save($providerIdentity);

            return DomainAdoptionResult::successful(
                adoptedDomainId: $adoptedDomainId,
                identity: $providerIdentity,
                warnings: $warnings
            );
        });
    }
}
