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
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                domain_name VARCHAR(255) NOT NULL UNIQUE,
                registrar VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                registration_period_years INT NOT NULL DEFAULT 1,
                recurring_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                registration_date VARCHAR(30) NULL,
                expiry_date VARCHAR(30) NULL,
                next_due_date VARCHAR(30) NULL,
                auto_renew TINYINT(1) NOT NULL DEFAULT 1,
                id_protection TINYINT(1) NOT NULL DEFAULT 0,
                nameservers_json TEXT NULL,
                is_adopted TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->domainsTable,
            $autoInc
        );

        $this->db->statement($sql);
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

        // 2. Check for conflict/duplicate
        $existingIdentity = $this->identityRepo->findByExternalReference(
            AdoptedIdentityType::DOMAIN_REGISTRATION,
            $targetRegistrar,
            $domainName
        );

        if ($existingIdentity !== null) {
            // Idempotent return if same source ID
            if ($existingIdentity->getSourceId() === $domainDto->getSourceId()) {
                return DomainAdoptionResult::successful(
                    adoptedDomainId: $existingIdentity->getTargetEntityId(),
                    identity: $existingIdentity,
                    warnings: ['Domain was already adopted previously under this registrar/domain name.']
                );
            }

            $errors[] = sprintf(
                'Conflict: Domain name [%s] is already adopted from source entity [%s].',
                $domainName,
                $existingIdentity->getSourceId()
            );
            return DomainAdoptionResult::failed($errors);
        }

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
            'source_domain_id' => $domainDto->getSourceId(),
            'registrar_api_suppressed' => true,
        ]);

        $this->db->statement(
            sprintf(
                'INSERT INTO %s
                (user_id, domain_name, registrar, status, registration_period_years, recurring_amount,
                 currency, registration_date, expiry_date, next_due_date, auto_renew, id_protection,
                 nameservers_json, is_adopted, metadata_json)
                VALUES
                (:u, :dom, :reg, :st, :pyr, :amt, :curr, :rd, :xd, :nd, :ar, :idp, :ns, 1, :meta)',
                $this->domainsTable
            ),
            [
                'u' => $userId,
                'dom' => $domainName,
                'reg' => $targetRegistrar,
                'st' => $domainDto->getStatus(),
                'pyr' => $domainDto->getRegistrationPeriodYears(),
                'amt' => $domainDto->getRecurringAmount(),
                'curr' => $domainDto->getCurrency(),
                'rd' => $domainDto->getRegistrationDate(),
                'xd' => $domainDto->getExpiryDate(),
                'nd' => $domainDto->getNextDueDate(),
                'ar' => $domainDto->isAutoRenew() ? 1 : 0,
                'idp' => $domainDto->hasIdProtection() ? 1 : 0,
                'ns' => json_encode($domainDto->getNameServers(), JSON_THROW_ON_ERROR),
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            ]
        );

        $adoptedDomainId = (int) $this->db->getPdo()->lastInsertId();

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
    }
}
