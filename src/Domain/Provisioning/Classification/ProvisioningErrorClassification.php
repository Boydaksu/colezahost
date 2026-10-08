<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Classification;

final class ProvisioningErrorClassification
{
    /**
     * @param array<string, mixed> $rawDetails
     */
    public function __construct(
        private string $category,
        private string $errorCode,
        private string $clientSafeMessage,
        private string $adminActionableMessage,
        private ?bool $retryable = null,
        private int $suggestedRetryDelaySeconds = 0,
        private bool $requiresAdminIntervention = false,
        private array $rawDetails = []
    ) {
        if ($this->retryable === null) {
            $this->retryable = ProvisioningErrorCategory::isRetryable($this->category);
        }
    }

    public static function transientNetwork(
        string $message,
        string $errorCode = 'NETWORK_TIMEOUT',
        int $retryDelaySeconds = 60,
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::TRANSIENT_NETWORK,
            errorCode: $errorCode,
            clientSafeMessage: 'Your service setup is temporarily delayed while connecting to the provisioning server. We will automatically retry shortly.',
            adminActionableMessage: "Transient network issue detected: {$message}. Check network routing and target server latency.",
            retryable: true,
            suggestedRetryDelaySeconds: $retryDelaySeconds,
            requiresAdminIntervention: false,
            rawDetails: $rawDetails
        );
    }

    public static function rateLimited(
        string $message,
        int $retryDelaySeconds = 300,
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::RATE_LIMITED,
            errorCode: 'PROVIDER_RATE_LIMIT',
            clientSafeMessage: 'Service setup is queued due to provider rate throttling. It will resume automatically.',
            adminActionableMessage: "Provider API rate limit reached: {$message}. Consider adjusting concurrency throttles.",
            retryable: true,
            suggestedRetryDelaySeconds: $retryDelaySeconds,
            requiresAdminIntervention: false,
            rawDetails: $rawDetails
        );
    }

    public static function authentication(
        string $message,
        string $errorCode = 'AUTH_FAILURE',
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::AUTHENTICATION,
            errorCode: $errorCode,
            clientSafeMessage: 'Service activation is pending review by technical support.',
            adminActionableMessage: "Provider authentication failed: {$message}. Verify API tokens/credentials in Vault.",
            retryable: false,
            suggestedRetryDelaySeconds: 0,
            requiresAdminIntervention: true,
            rawDetails: $rawDetails
        );
    }

    public static function resourceExhausted(
        string $message,
        string $errorCode = 'QUOTA_EXHAUSTED',
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::RESOURCE_EXHAUSTED,
            errorCode: $errorCode,
            clientSafeMessage: 'Service activation is pending capacity allocation by our team.',
            adminActionableMessage: "Target node capacity exhausted: {$message}. Add capacity or migrate pool allocations.",
            retryable: false,
            suggestedRetryDelaySeconds: 0,
            requiresAdminIntervention: true,
            rawDetails: $rawDetails
        );
    }

    public static function validation(
        string $message,
        string $errorCode = 'VALIDATION_FAILED',
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::VALIDATION,
            errorCode: $errorCode,
            clientSafeMessage: "Unable to setup service: {$message}",
            adminActionableMessage: "Validation error rejected by provider: {$message}. Verify package name and user credentials.",
            retryable: false,
            suggestedRetryDelaySeconds: 0,
            requiresAdminIntervention: false,
            rawDetails: $rawDetails
        );
    }

    public static function conflict(
        string $message,
        string $errorCode = 'RESOURCE_CONFLICT',
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::CONFLICT,
            errorCode: $errorCode,
            clientSafeMessage: 'The requested username or domain already exists on the server. Please contact support.',
            adminActionableMessage: "Resource collision: {$message}. Existing account or DNS record must be cleared or renamed.",
            retryable: false,
            suggestedRetryDelaySeconds: 0,
            requiresAdminIntervention: true,
            rawDetails: $rawDetails
        );
    }

    public static function providerFault(
        string $message,
        string $errorCode = 'PROVIDER_INTERNAL_ERROR',
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::PROVIDER_FAULT,
            errorCode: $errorCode,
            clientSafeMessage: 'Service setup encountered an unexpected error on the hosting server. Our team has been notified.',
            adminActionableMessage: "Provider returned internal error: {$message}. Check daemon logs on the target server.",
            retryable: false,
            suggestedRetryDelaySeconds: 0,
            requiresAdminIntervention: true,
            rawDetails: $rawDetails
        );
    }

    public static function unknown(
        string $message,
        string $errorCode = 'UNKNOWN_PROVISIONING_ERROR',
        array $rawDetails = []
    ): self {
        return new self(
            category: ProvisioningErrorCategory::UNKNOWN,
            errorCode: $errorCode,
            clientSafeMessage: 'An error occurred during service setup. Our engineers are investigating.',
            adminActionableMessage: "Unclassified provisioning failure: {$message}.",
            retryable: false,
            suggestedRetryDelaySeconds: 0,
            requiresAdminIntervention: true,
            rawDetails: $rawDetails
        );
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getClientSafeMessage(): string
    {
        return $this->clientSafeMessage;
    }

    public function getAdminActionableMessage(): string
    {
        return $this->adminActionableMessage;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getSuggestedRetryDelaySeconds(): int
    {
        return $this->suggestedRetryDelaySeconds;
    }

    public function requiresAdminIntervention(): bool
    {
        return $this->requiresAdminIntervention;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawDetails(): array
    {
        return $this->rawDetails;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'error_code' => $this->errorCode,
            'client_safe_message' => $this->clientSafeMessage,
            'admin_actionable_message' => $this->adminActionableMessage,
            'retryable' => $this->retryable,
            'suggested_retry_delay_seconds' => $this->suggestedRetryDelaySeconds,
            'requires_admin_intervention' => $this->requiresAdminIntervention,
            'raw_details' => $this->rawDetails,
        ];
    }
}
