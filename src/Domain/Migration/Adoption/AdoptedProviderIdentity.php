<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

use JsonSerializable;

/**
 * Represents an external provider identity (server account or registrar registration)
 * that was adopted from a legacy system without triggering destructive creation actions.
 */
final class AdoptedProviderIdentity implements JsonSerializable
{
    /**
     * @param array<string, mixed> $externalMetadata
     */
    public function __construct(
        private ?int $id,
        private string $batchId,
        private AdoptedIdentityType $identityType,
        private string $sourceId,
        private string $sourceSystem,
        private int $targetEntityId,
        private string $providerType, // 'server' or 'registrar'
        private string $providerIdentifier, // Server ID or Registrar module name
        private string $externalReferenceId, // e.g. cPanel username, or domain name
        private array $externalMetadata = [],
        private bool $remoteVerificationPassed = false,
        private bool $suppressProvisioning = true,
        private ?string $adoptedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getIdentityType(): AdoptedIdentityType
    {
        return $this->identityType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function getTargetEntityId(): int
    {
        return $this->targetEntityId;
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    public function getProviderIdentifier(): string
    {
        return $this->providerIdentifier;
    }

    public function getExternalReferenceId(): string
    {
        return $this->externalReferenceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExternalMetadata(): array
    {
        return $this->externalMetadata;
    }

    public function isRemoteVerificationPassed(): bool
    {
        return $this->remoteVerificationPassed;
    }

    public function isProvisioningSuppressed(): bool
    {
        return $this->suppressProvisioning;
    }

    public function getAdoptedAt(): ?string
    {
        return $this->adoptedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batchId,
            'identity_type' => $this->identityType->value,
            'source_id' => $this->sourceId,
            'source_system' => $this->sourceSystem,
            'target_entity_id' => $this->targetEntityId,
            'provider_type' => $this->providerType,
            'provider_identifier' => $this->providerIdentifier,
            'external_reference_id' => $this->externalReferenceId,
            'external_metadata' => $this->externalMetadata,
            'remote_verification_passed' => $this->remoteVerificationPassed,
            'suppress_provisioning' => $this->suppressProvisioning,
            'adopted_at' => $this->adoptedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
