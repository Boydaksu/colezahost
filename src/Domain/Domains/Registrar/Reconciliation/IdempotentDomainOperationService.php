<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Reconciliation;

use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\Registrar\DomainRegistrarService;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Foundation\Exceptions\ValidationException;
use Throwable;

final class IdempotentDomainOperationService
{
    public function __construct(
        private readonly DomainOperationRepository $operationRepo,
        private readonly DomainRegistrarService $registrarService,
        private readonly DomainService $domainService
    ) {
    }

    /**
     * Execute domain registration with strict idempotency and uncertain timeout protection.
     */
    public function executeRegister(
        int $domainId,
        string $idempotencyKey,
        ?string $registrarId = null,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        return $this->executeIdempotent(
            domainId: $domainId,
            operationType: DomainOperation::TYPE_REGISTER,
            idempotencyKey: $idempotencyKey,
            callback: fn() => $this->registrarService->registerDomain($domainId, $registrarId, $actorType, $actorId),
            actorType: $actorType,
            actorId: $actorId
        );
    }

    /**
     * Execute domain renewal with strict idempotency and uncertain timeout protection.
     */
    public function executeRenew(
        int $domainId,
        string $idempotencyKey,
        int $years = 1,
        ?string $registrarId = null,
        string $actorType = 'automation',
        ?int $actorId = null
    ): RegistrarOperationResult {
        return $this->executeIdempotent(
            domainId: $domainId,
            operationType: DomainOperation::TYPE_RENEW,
            idempotencyKey: $idempotencyKey,
            callback: fn() => $this->registrarService->renewDomain($domainId, $years, $registrarId, $actorType, $actorId),
            actorType: $actorType,
            actorId: $actorId
        );
    }

    /**
     * Execute domain transfer with strict idempotency and uncertain timeout protection.
     */
    public function executeTransfer(
        int $domainId,
        string $idempotencyKey,
        string $eppCode,
        ?string $registrarId = null,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        return $this->executeIdempotent(
            domainId: $domainId,
            operationType: DomainOperation::TYPE_TRANSFER,
            idempotencyKey: $idempotencyKey,
            callback: fn() => $this->registrarService->transferDomain($domainId, $eppCode, $registrarId, $actorType, $actorId),
            actorType: $actorType,
            actorId: $actorId
        );
    }

    /**
     * @param callable(): RegistrarOperationResult $callback
     */
    public function executeIdempotent(
        int $domainId,
        string $operationType,
        string $idempotencyKey,
        callable $callback,
        string $actorType = 'user',
        ?int $actorId = null
    ): RegistrarOperationResult {
        $domain = $this->domainService->findDomainById($domainId);
        if ($domain === null) {
            throw new ValidationException(['domain_id' => "Domain ID {$domainId} not found."], 'Domain not found');
        }

        $existing = $this->operationRepo->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            if ($existing->isSucceeded()) {
                // Return cached successful result
                $cachedResult = $existing->getResult();
                return RegistrarOperationResult::success(
                    operation: $operationType,
                    domain: $domain->getDomain(),
                    remoteTransactionId: $existing->getRemoteTransactionId(),
                    expirationDate: (string) ($cachedResult['expiration_date'] ?? ''),
                    metadata: $cachedResult
                );
            }

            if ($existing->isProcessing()) {
                throw new ValidationException(
                    ['idempotency_key' => "Operation '{$idempotencyKey}' is already processing. Concurrent duplicate prevented."],
                    'Concurrent operation prevented'
                );
            }

            if ($existing->isUncertain()) {
                return RegistrarOperationResult::failure(
                    operation: $operationType,
                    domain: $domain->getDomain(),
                    errorCode: 'OPERATION_UNCERTAIN',
                    errorMessage: "Operation '{$idempotencyKey}' timed out previously and is pending reconciliation."
                );
            }

            // Retry failed operation
            $operationId = (int) $existing->getId();
            $this->operationRepo->markProcessing($operationId);
        } else {
            // New operation record
            $operation = $this->operationRepo->create([
                'domain_id' => $domainId,
                'operation_type' => $operationType,
                'idempotency_key' => $idempotencyKey,
                'status' => DomainOperation::STATUS_PROCESSING,
            ]);
            $operationId = (int) $operation->getId();
        }

        try {
            $result = $callback();

            if ($result->isSuccessful()) {
                $this->operationRepo->markSucceeded(
                    id: $operationId,
                    remoteTransactionId: $result->getRemoteTransactionId(),
                    result: $result->toArray()
                );
            } else {
                // Check if result itself signifies a timeout
                if ($this->isTimeoutErrorCode((string) $result->getErrorCode()) || $this->isTimeoutMessage((string) $result->getErrorMessage())) {
                    $this->markUncertain(
                        domainId: $domainId,
                        operationId: $operationId,
                        operationType: $operationType,
                        idempotencyKey: $idempotencyKey,
                        errorMessage: (string) $result->getErrorMessage(),
                        errorCode: (string) $result->getErrorCode(),
                        result: $result->toArray(),
                        actorType: $actorType,
                        actorId: $actorId
                    );

                    return RegistrarOperationResult::failure(
                        operation: $operationType,
                        domain: $domain->getDomain(),
                        errorCode: 'UNCERTAIN_TIMEOUT',
                        errorMessage: "Operation timed out at registrar. Queued for reconciliation: {$result->getErrorMessage()}"
                    );
                }

                $this->operationRepo->markFailed(
                    id: $operationId,
                    errorCode: (string) $result->getErrorCode(),
                    errorMessage: (string) $result->getErrorMessage(),
                    result: $result->toArray()
                );
            }

            return $result;
        } catch (Throwable $e) {
            if ($this->isTimeoutException($e)) {
                $this->markUncertain(
                    domainId: $domainId,
                    operationId: $operationId,
                    operationType: $operationType,
                    idempotencyKey: $idempotencyKey,
                    errorMessage: $e->getMessage(),
                    errorCode: 'EXCEPTION_TIMEOUT',
                    result: ['exception' => $e->getMessage()],
                    actorType: $actorType,
                    actorId: $actorId
                );

                return RegistrarOperationResult::failure(
                    operation: $operationType,
                    domain: $domain->getDomain(),
                    errorCode: 'UNCERTAIN_TIMEOUT',
                    errorMessage: "Registrar network timeout occurred. Operation queued for automated reconciliation."
                );
            }

            $this->operationRepo->markFailed(
                id: $operationId,
                errorCode: 'EXCEPTION',
                errorMessage: $e->getMessage(),
                result: ['exception' => $e->getMessage()]
            );

            throw $e;
        }
    }

    private function isTimeoutException(Throwable $e): bool
    {
        return $this->isTimeoutMessage($e->getMessage());
    }

    private function isTimeoutMessage(string $msg): bool
    {
        $normalized = strtolower($msg);
        $needles = [
            'timeout',
            'timed out',
            'timed_out',
            'curl error 28',
            '504 gateway time-out',
            '502 bad gateway',
            'connection reset',
            'failed to connect',
            'network error',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isTimeoutErrorCode(string $code): bool
    {
        $normalized = strtoupper(trim($code));
        return in_array($normalized, ['TIMEOUT', 'GATEWAY_TIMEOUT', 'TRANSPORT_ERROR', 'CONNECTION_LOST'], true);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function markUncertain(
        int $domainId,
        int $operationId,
        string $operationType,
        string $idempotencyKey,
        string $errorMessage,
        string $errorCode,
        array $result,
        string $actorType,
        ?int $actorId
    ): void {
        $this->operationRepo->markUncertain(
            id: $operationId,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            result: $result
        );

        $this->domainService->recordTimelineEvent(
            domainId: $domainId,
            eventType: 'operation_uncertain',
            description: "Registrar {$operationType} timed out (Key: {$idempotencyKey}). Operation marked UNCERTAIN pending automated reconciliation.",
            payload: [
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'error' => $errorMessage,
            ],
            actorType: $actorType,
            actorId: $actorId
        );
    }
}
