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
        (new \Coleza\Domain\Commerce\Services\ServiceService($this->db))->ensureTables();
        AdoptionSchema::ensure($this->db);
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

        foreach ([$serviceDto->getRegistrationDate(), $serviceDto->getNextDueDate()] as $date) {
            if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return ServiceAdoptionResult::failed(['Service adoption requires explicit valid registration and next-due dates; reconcile missing source dates first.']);
            }
        }

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
        $productId = $this->resolver->resolveProduct($serviceDto->getProductSourceId());
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
            ? $this->resolver->resolveServer($sourceServerKey)
            : null;
        if ($serverId === null) {
            return ServiceAdoptionResult::failed(['Service adoption requires an explicit target server mapping.']);
        }

        $username = $serviceDto->getUsername();
        $domain = $serviceDto->getDomain();
        $reference = $username ?? $domain ?? ($serviceDto->getSourceSystem() . ':' . $serviceDto->getSourceId());

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
            'is_adopted' => true,
            'source_service_id' => $serviceDto->getSourceId(),
            'provisioning_suppressed' => true,
        ]);

        $amount = $serviceDto->getRecurringAmount();
        if (!is_finite($amount) || $amount < 0 || $amount * 100 > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid recurring amount for adoption.');
        }
        return $this->db->transaction(function () use ($serviceDto, $batchId, $userId, $productId, $serverId, $domain, $username, $reference, $meta, $verificationPassed, $warnings, $amount): ServiceAdoptionResult {
            $this->db->lockRow('migration_adoption_lock', 1);
            // 4. Check for duplicate/conflict adoption
            if ($serverId !== null) {
                $existingIdentity = $this->identityRepo->findByExternalReference(
                    AdoptedIdentityType::HOSTING_SERVICE,
                    (string) $serverId,
                    $reference
                );

                if ($existingIdentity !== null) {
                    // Idempotent adoption if same source ID
                    if ($existingIdentity->getSourceId() === $serviceDto->getSourceId() && $existingIdentity->getSourceSystem() === $serviceDto->getSourceSystem() && (int) ($this->db->selectOne('SELECT user_id FROM services WHERE id = ?', [$existingIdentity->getTargetEntityId()])['user_id'] ?? 0) === $userId) {
                        return ServiceAdoptionResult::successful(
                            adoptedServiceId: $existingIdentity->getTargetEntityId(),
                            identity: $existingIdentity,
                            warnings: ['Service was already adopted previously under this server/username.']
                        );
                    }

                    $errors = [sprintf(
                        'Conflict: Server account username [%s] on server [%d] is already adopted by source service [%s].',
                        $username,
                        $serverId,
                        $existingIdentity->getSourceId()
                    )];
                    return ServiceAdoptionResult::failed($errors);
                }
            }

            $services = new \Coleza\Domain\Commerce\Services\ServiceService($this->db);
            if ($username !== null && (new \Coleza\Domain\Migration\Execution\ConflictDetector($this->db))->detectServiceConflict($serverId, $username) !== null) {
                return ServiceAdoptionResult::failed(['Existing service placement has no matching provider identity; reconcile before adoption.']);
            }
            $service = $services->createService([
                'user_id' => $userId, 'product_id' => $productId, 'status' => $serviceDto->getStatus(),
                'billing_cycle' => $serviceDto->getBillingCycle(), 'recurring_amount_minor' => (int) round($amount * 100),
                'currency_code' => $serviceDto->getCurrency(), 'domain' => $domain, 'username' => $username,
                'registration_date' => $serviceDto->getRegistrationDate(),
                'next_due_date' => $serviceDto->getNextDueDate(),
                'ip_address' => $serviceDto->getDedicatedIp(), 'metadata' => $meta,
            ]);
            $adoptedServiceId = $service->getId();
            if ($serverId !== null) {
                $services->assignPlacement($adoptedServiceId, ['server_id' => $serverId, 'dedicated_ip' => $serviceDto->getDedicatedIp()]);
            }

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
                externalReferenceId: $reference,
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
        });
    }
}
