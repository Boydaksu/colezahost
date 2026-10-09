<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

use Coleza\Domain\Migration\Canonical\CanonicalServiceDto;
use Coleza\Foundation\Database\Connection;

final class ServiceAdoptionService
{
    private string $servicesTable = 'services';

    public function __construct(
        private Connection $db,
        private AdoptedIdentityRepository $identityRepo,
        private ProviderIdentityResolver $resolver,
        private ?RemoteIdentityVerifierInterface $remoteVerifier = null
    ) {
    }

    public function ensureServicesTable(): void
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
                product_id INT NOT NULL,
                server_id INT NULL,
                domain VARCHAR(255) NULL,
                username VARCHAR(64) NULL,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                billing_cycle VARCHAR(32) NOT NULL DEFAULT "monthly",
                recurring_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(3) NOT NULL DEFAULT "USD",
                registration_date VARCHAR(30) NULL,
                next_due_date VARCHAR(30) NULL,
                is_adopted TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->servicesTable,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Adopts an existing hosting service without triggering destructive remote provisioning creation.
     */
    public function adoptService(
        CanonicalServiceDto $serviceDto,
        string $batchId,
        ?string $sourceServerKey = null,
        bool $verifyRemote = false
    ): ServiceAdoptionResult {
        $this->ensureServicesTable();
        $this->identityRepo->ensureTable();

        $errors = [];
        $warnings = [];

        // 1. Resolve client/user ID
        $userId = $this->resolver->resolveClient($serviceDto->getClientSourceId());
        if ($userId === null) {
            $errors[] = sprintf(
                'Cannot adopt service [%s]: Client source ID [%s] has not been resolved to a Coleza user ID.',
                $serviceDto->getSourceId(),
                $serviceDto->getClientSourceId()
            );
            return ServiceAdoptionResult::failed($errors);
        }

        // 2. Resolve product ID
        $productId = $this->resolver->resolveProduct($serviceDto->getProductSourceId(), defaultProductId: 1);
        if ($productId === null) {
            $errors[] = sprintf(
                'Cannot adopt service [%s]: Product source ID [%s] could not be resolved.',
                $serviceDto->getSourceId(),
                $serviceDto->getProductSourceId()
            );
            return ServiceAdoptionResult::failed($errors);
        }

        // 3. Resolve target server
        $serverId = $sourceServerKey !== null
            ? $this->resolver->resolveServer($sourceServerKey, defaultServerId: 1)
            : 1;

        $username = $serviceDto->getUsername();
        $domain = $serviceDto->getDomain();

        // 4. Check for duplicate/conflict adoption
        if ($username !== null && $serverId !== null) {
            $existingIdentity = $this->identityRepo->findByExternalReference(
                AdoptedIdentityType::HOSTING_SERVICE,
                (string) $serverId,
                $username
            );

            if ($existingIdentity !== null) {
                // Idempotent adoption if same source ID
                if ($existingIdentity->getSourceId() === $serviceDto->getSourceId()) {
                    return ServiceAdoptionResult::successful(
                        adoptedServiceId: $existingIdentity->getTargetEntityId(),
                        identity: $existingIdentity,
                        warnings: ['Service was already adopted previously under this server/username.']
                    );
                }

                $errors[] = sprintf(
                    'Conflict: Server account username [%s] on server [%d] is already adopted by source service [%s].',
                    $username,
                    $serverId,
                    $existingIdentity->getSourceId()
                );
                return ServiceAdoptionResult::failed($errors);
            }
        }

        // 5. Optional remote read-only verification
        $verificationPassed = false;
        if ($verifyRemote && $this->remoteVerifier !== null && $username !== null && $serverId !== null) {
            $verificationPassed = $this->remoteVerifier->verifyServerAccountExists(
                $serverId,
                $username,
                $domain
            );

            if (!$verificationPassed) {
                $warnings[] = sprintf(
                    'Warning: Account [%s] could not be verified on remote server [%d]. Adopting in unverified state.',
                    $username,
                    $serverId
                );
            }
        }

        // 6. Insert adopted service into services table WITHOUT provisioning hooks
        $meta = array_merge($serviceDto->getMetadata(), [
            'adopted_from' => $serviceDto->getSourceSystem(),
            'source_service_id' => $serviceDto->getSourceId(),
            'provisioning_suppressed' => true,
        ]);

        $this->db->statement(
            sprintf(
                'INSERT INTO %s
                (user_id, product_id, server_id, domain, username, status, billing_cycle,
                 recurring_amount, currency, registration_date, next_due_date, is_adopted, metadata_json)
                VALUES
                (:u, :p, :srv, :dom, :usr, :st, :bc, :amt, :curr, :rd, :nd, 1, :meta)',
                $this->servicesTable
            ),
            [
                'u' => $userId,
                'p' => $productId,
                'srv' => $serverId,
                'dom' => $domain,
                'usr' => $username,
                'st' => $serviceDto->getStatus(),
                'bc' => $serviceDto->getBillingCycle(),
                'amt' => $serviceDto->getRecurringAmount(),
                'curr' => $serviceDto->getCurrency(),
                'rd' => $serviceDto->getRegistrationDate(),
                'nd' => $serviceDto->getNextDueDate(),
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            ]
        );

        $adoptedServiceId = (int) $this->db->getPdo()->lastInsertId();

        // 7. Record adopted provider identity link
        $providerIdentity = new AdoptedProviderIdentity(
            id: null,
            batchId: $batchId,
            identityType: AdoptedIdentityType::HOSTING_SERVICE,
            sourceId: $serviceDto->getSourceId(),
            sourceSystem: $serviceDto->getSourceSystem(),
            targetEntityId: $adoptedServiceId,
            providerType: 'server',
            providerIdentifier: (string) ($serverId ?? 'unassigned'),
            externalReferenceId: (string) ($username ?? $domain ?? "service-{$adoptedServiceId}"),
            externalMetadata: [
                'domain' => $domain,
                'username' => $username,
                'dedicated_ip' => $serviceDto->getDedicatedIp(),
            ],
            remoteVerificationPassed: $verificationPassed,
            suppressProvisioning: true,
            adoptedAt: date('Y-m-d H:i:s')
        );

        $this->identityRepo->save($providerIdentity);

        return ServiceAdoptionResult::successful(
            adoptedServiceId: $adoptedServiceId,
            identity: $providerIdentity,
            warnings: $warnings
        );
    }
}
