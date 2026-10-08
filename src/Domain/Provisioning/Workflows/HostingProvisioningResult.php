<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Workflows;

use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;

final class HostingProvisioningResult
{
    /**
     * @param array<string> $completedSteps
     * @param array<string> $nameservers
     * @param array<string, mixed> $data
     */
    public function __construct(
        private bool $success,
        private int $serviceId,
        private string $currentStep,
        private array $completedSteps = [],
        private ?int $serverId = null,
        private ?string $reservationToken = null,
        private ?string $remoteIdentifier = null,
        private ?string $remoteIp = null,
        private array $nameservers = [],
        private ?string $errorMessage = null,
        private ?string $errorCode = null,
        private ?ProvisioningErrorClassification $classification = null,
        private ?string $operationUuid = null,
        private array $data = []
    ) {
    }

    /**
     * @param array<string> $completedSteps
     * @param array<string> $nameservers
     * @param array<string, mixed> $data
     */
    public static function success(
        int $serviceId,
        int $serverId,
        string $remoteIdentifier,
        string $remoteIp,
        array $completedSteps = [],
        ?string $reservationToken = null,
        array $nameservers = [],
        ?string $operationUuid = null,
        array $data = []
    ): self {
        return new self(
            success: true,
            serviceId: $serviceId,
            currentStep: HostingProvisioningStep::ACTIVATE,
            completedSteps: $completedSteps,
            serverId: $serverId,
            reservationToken: $reservationToken,
            remoteIdentifier: $remoteIdentifier,
            remoteIp: $remoteIp,
            nameservers: $nameservers,
            errorMessage: null,
            errorCode: null,
            classification: null,
            operationUuid: $operationUuid,
            data: $data
        );
    }

    /**
     * @param array<string> $completedSteps
     * @param array<string, mixed> $data
     */
    public static function failure(
        int $serviceId,
        string $failedStep,
        string $errorMessage,
        string $errorCode,
        array $completedSteps = [],
        ?int $serverId = null,
        ?string $reservationToken = null,
        ?ProvisioningErrorClassification $classification = null,
        ?string $operationUuid = null,
        array $data = []
    ): self {
        return new self(
            success: false,
            serviceId: $serviceId,
            currentStep: $failedStep,
            completedSteps: $completedSteps,
            serverId: $serverId,
            reservationToken: $reservationToken,
            remoteIdentifier: null,
            remoteIp: null,
            nameservers: [],
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            classification: $classification,
            operationUuid: $operationUuid,
            data: $data
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getCurrentStep(): string
    {
        return $this->currentStep;
    }

    /**
     * @return array<string>
     */
    public function getCompletedSteps(): array
    {
        return $this->completedSteps;
    }

    public function hasStepCompleted(string $step): bool
    {
        return in_array($step, $this->completedSteps, true);
    }

    public function getServerId(): ?int
    {
        return $this->serverId;
    }

    public function getReservationToken(): ?string
    {
        return $this->reservationToken;
    }

    public function getRemoteIdentifier(): ?string
    {
        return $this->remoteIdentifier;
    }

    public function getRemoteIp(): ?string
    {
        return $this->remoteIp;
    }

    /**
     * @return array<string>
     */
    public function getNameservers(): array
    {
        return $this->nameservers;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getClassification(): ?ProvisioningErrorClassification
    {
        return $this->classification;
    }

    public function getOperationUuid(): ?string
    {
        return $this->operationUuid;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'service_id' => $this->serviceId,
            'current_step' => $this->currentStep,
            'completed_steps' => $this->completedSteps,
            'server_id' => $this->serverId,
            'reservation_token' => $this->reservationToken,
            'remote_identifier' => $this->remoteIdentifier,
            'remote_ip' => $this->remoteIp,
            'nameservers' => $this->nameservers,
            'error_message' => $this->errorMessage,
            'error_code' => $this->errorCode,
            'category' => $this->classification?->getCategory(),
            'operation_uuid' => $this->operationUuid,
            'data' => $this->data,
        ];
    }
}
