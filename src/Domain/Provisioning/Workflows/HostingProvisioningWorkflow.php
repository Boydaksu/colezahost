<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Workflows;

use Coleza\Domain\Commerce\Services\Exceptions\ServiceConcurrencyException;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\PlacementDecision;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Placement\PlacementRequest;
use Coleza\Domain\Servers\Services\ServerService;
use RuntimeException;
use Throwable;

final class HostingProvisioningWorkflow
{
    /** @var array<string> */
    private static array $reservedUsernames = [
        'root', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt',
        'mail', 'news', 'uucp', 'operator', 'games', 'gopher', 'ftp',
        'nobody', 'cpanel', 'whm', 'admin', 'test', 'guest', 'user', 'system'
    ];

    public function __construct(
        private ServiceService $serviceService,
        private ServerService $serverService,
        private PlacementEngine $placementEngine,
        private CapacityReservationService $reservationService,
        private ProviderRegistry $providerRegistry,
        private ProvisioningOperationService $operationService
    ) {
    }

    /**
     * Execute the full 6-step hosting provisioning workflow:
     * validate -> place -> reserve -> remote -> verify -> activate
     */
    public function execute(HostingProvisioningRequest $request): HostingProvisioningResult
    {
        $serviceId = $request->getServiceId();
        /** @var array<string> $completedSteps */
        $completedSteps = [];

        // ====================================================================
        // STEP 1: VALIDATE
        // ====================================================================
        $service = $this->serviceService->findServiceById($serviceId);
        if ($service === null) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Service {$serviceId} does not exist.",
                errorCode: 'SERVICE_NOT_FOUND',
                completedSteps: $completedSteps
            );
        }

        // Idempotency: if already active, treat as completed
        if ($service->getStatus() === ServiceStateMachine::STATUS_ACTIVE) {
            return HostingProvisioningResult::success(
                serviceId: $serviceId,
                serverId: $service->getPlacement()?->getServerId() ?? 0,
                remoteIdentifier: (string)$service->getUsername(),
                remoteIp: (string)$service->getIpAddress(),
                completedSteps: HostingProvisioningStep::allSteps(),
                data: ['idempotent' => true, 'already_active' => true]
            );
        }

        if ($service->getStatus() !== ServiceStateMachine::STATUS_PENDING) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Service status '{$service->getStatus()}' is not eligible for provisioning (must be 'pending').",
                errorCode: 'INVALID_SERVICE_STATUS',
                completedSteps: $completedSteps
            );
        }

        // Domain validation
        $domain = trim($request->getDomain() ?? ($service->getDomain() ?? ''));
        if ($domain === '' || !str_contains($domain, '.') || str_contains($domain, ' ')) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Invalid or missing domain name '{$domain}'.",
                errorCode: 'INVALID_DOMAIN',
                completedSteps: $completedSteps
            );
        }

        // Username validation / generation
        $username = trim($request->getUsername() ?? ($service->getUsername() ?? ''));
        if ($username === '') {
            $username = $this->generateCpanelUsername($domain, $serviceId);
        } else {
            $username = strtolower($username);
        }

        if (!$this->isValidCpanelUsername($username)) {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: "Username '{$username}' is invalid for cPanel hosting (1-16 alphanumeric characters, must start with letter, cannot be reserved).",
                errorCode: 'INVALID_USERNAME',
                completedSteps: $completedSteps
            );
        }

        // Password resolution
        $password = $request->getPassword() ?? $service->getPasswordEncrypted();
        if ($password === null || $password === '') {
            $password = $this->generateSecurePassword();
        }

        // Package identifier resolution
        $package = trim($request->getPackageIdentifier() ?? (string)($service->getMetadata()['package_identifier'] ?? ($service->getMetadata()['plan'] ?? '')));
        if ($package === '') {
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VALIDATE,
                errorMessage: 'Hosting package identifier is required but not provided.',
                errorCode: 'MISSING_PACKAGE_IDENTIFIER',
                completedSteps: $completedSteps
            );
        }

        $completedSteps[] = HostingProvisioningStep::VALIDATE;

        // ====================================================================
        // STEP 2: PLACE
        // ====================================================================
        $selectedServer = null;

        if ($request->getPreferredServerId() !== null) {
            $candidate = $this->serverService->findServerById($request->getPreferredServerId());
            if ($candidate !== null && $candidate->isActive()) {
                $selectedServer = $candidate;
            } else {
                return HostingProvisioningResult::failure(
                    serviceId: $serviceId,
                    failedStep: HostingProvisioningStep::PLACE,
                    errorMessage: "Preferred server {$request->getPreferredServerId()} is not found or inactive.",
                    errorCode: 'PREFERRED_SERVER_UNAVAILABLE',
                    completedSteps: $completedSteps
                );
            }
        } else {
            $placementReq = new PlacementRequest(
                serviceId: $serviceId,
                productId: $service->getProductId(),
                serverPoolId: $request->getServerPoolId(),
                providerSlug: $request->getProviderSlug(),
                locationId: $request->getLocationId(),
                requiredDiskMb: $request->getRequiredDiskMb(),
                requiredBandwidthMb: $request->getRequiredBandwidthMb(),
                requiredDedicatedIp: $request->requiresDedicatedIp()
            );

            $decision = $this->placementEngine->selectServer($placementReq);
            if (!$decision->isSuccessful() || $decision->getSelectedServer() === null) {
                return HostingProvisioningResult::failure(
                    serviceId: $serviceId,
                    failedStep: HostingProvisioningStep::PLACE,
                    errorMessage: $decision->getMessage(),
                    errorCode: 'PLACEMENT_REJECTED',
                    completedSteps: $completedSteps,
                    data: ['rejection_reasons' => $decision->getRejectionReasons()]
                );
            }
            $selectedServer = $decision->getSelectedServer();
        }

        $serverId = $selectedServer->getId() ?? 0;

        // Queue operation record
        $op = $this->operationService->queueOperation(
            serviceId: $serviceId,
            providerSlug: $request->getProviderSlug(),
            action: 'create_account',
            payload: [
                'username' => $username,
                'domain' => $domain,
                'package' => $package,
                'disk_limit_mb' => $request->getRequiredDiskMb(),
                'bandwidth_limit_mb' => $request->getRequiredBandwidthMb(),
            ],
            serverId: $serverId,
            correlationId: $request->getCorrelationId()
        );
        $opUuid = $op->getOperationUuid();

        // Assign placement record (status: reserved)
        $this->serviceService->assignPlacement($serviceId, [
            'server_id' => $serverId,
            'server_pool_id' => $selectedServer->getServerPoolId(),
            'location_id' => $selectedServer->getLocationId(),
            'status' => ServicePlacement::STATUS_RESERVED,
            'package_identifier' => $package,
            'disk_limit_mb' => $request->getRequiredDiskMb(),
            'bandwidth_limit_mb' => $request->getRequiredBandwidthMb(),
        ]);

        $completedSteps[] = HostingProvisioningStep::PLACE;

        // ====================================================================
        // STEP 3: RESERVE
        // ====================================================================
        $reservationToken = null;
        try {
            $reservation = $this->reservationService->reserve(
                serverId: $serverId,
                accountsCount: 1,
                diskMb: $request->getRequiredDiskMb(),
                bandwidthMb: $request->getRequiredBandwidthMb(),
                ttlSeconds: 900,
                serviceId: $serviceId,
                poolId: $selectedServer->getServerPoolId()
            );
            $reservationToken = $reservation->getReservationToken();
        } catch (Throwable $e) {
            // Roll back placement
            $this->serviceService->releasePlacement($serviceId, ServicePlacement::STATUS_EVICTED);

            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            $failResult = ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: "Capacity reservation failed: {$e->getMessage()}",
                errorCode: 'CAPACITY_RESERVATION_FAILED'
            );
            $this->operationService->recordAttempt($opUuid, $failResult, $classification);

            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::RESERVE,
                errorMessage: $e->getMessage(),
                errorCode: 'CAPACITY_RESERVATION_FAILED',
                completedSteps: $completedSteps,
                serverId: $serverId,
                classification: $classification,
                operationUuid: $opUuid
            );
        }

        $completedSteps[] = HostingProvisioningStep::RESERVE;

        // ====================================================================
        // STEP 4: REMOTE
        // ====================================================================
        try {
            $provider = $this->providerRegistry->get($request->getProviderSlug());
            $serverConn = $selectedServer->toServerConnectionDto();

            $createOp = new CreateAccountOperation(
                serviceId: $serviceId,
                serviceNumber: $service->getServiceNumber(),
                username: $username,
                password: $password,
                domain: $domain,
                packageIdentifier: $package,
                resourceQuotas: [
                    'disk_limit_mb' => $request->getRequiredDiskMb(),
                    'bandwidth_limit_mb' => $request->getRequiredBandwidthMb(),
                ],
                server: $serverConn,
                metadata: array_merge($request->getMetadata(), [
                    'email' => $request->getContactEmail(),
                    'dedicated_ip' => $request->requiresDedicatedIp() ? 1 : 0,
                ])
            );

            $remoteResult = $provider->createAccount($createOp);
        } catch (Throwable $e) {
            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            $remoteResult = ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: $e->getMessage(),
                errorCode: 'PROVIDER_EXCEPTION',
                data: ['exception' => get_class($e), 'exception_message' => $e->getMessage()]
            );
        }

        if (!$remoteResult->isSuccess()) {
            // COMPENSATION: release capacity reservation and placement
            try {
                $this->reservationService->release($reservationToken, 'remote_creation_failed');
            } catch (Throwable) {
            }
            try {
                $this->serviceService->releasePlacement($serviceId, ServicePlacement::STATUS_EVICTED);
            } catch (Throwable) {
            }

            $classification = ProvisioningErrorClassifier::classifyResult($remoteResult);
            $this->operationService->recordAttempt($opUuid, $remoteResult, $classification);

            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::REMOTE,
                errorMessage: $remoteResult->getMessage(),
                errorCode: $remoteResult->getErrorCode() ?? 'REMOTE_CREATION_FAILED',
                completedSteps: $completedSteps,
                serverId: $serverId,
                reservationToken: $reservationToken,
                classification: $classification,
                operationUuid: $opUuid,
                data: $remoteResult->getData()
            );
        }

        $completedSteps[] = HostingProvisioningStep::REMOTE;

        // ====================================================================
        // STEP 5: VERIFY
        // ====================================================================
        $remoteIp = $remoteResult->getData()['ip'] ?? $selectedServer->getIpAddress();
        $nameservers = [];
        if (isset($remoteResult->getData()['nameserver'])) {
            $nameservers[] = (string)$remoteResult->getData()['nameserver'];
        }
        if (isset($remoteResult->getData()['nameserver2'])) {
            $nameservers[] = (string)$remoteResult->getData()['nameserver2'];
        }

        $verified = false;
        try {
            if ($provider instanceof CpanelProvider) {
                $client = $provider->createClient($selectedServer->toServerConnectionDto());
                $summary = $client->getAccountSummary($username);
                if ($summary !== null && strcasecmp((string)($summary['user'] ?? ''), $username) === 0) {
                    $verified = true;
                    if (!empty($summary['ip'])) {
                        $remoteIp = (string)$summary['ip'];
                    }
                }
            } else {
                $verified = true;
            }
        } catch (Throwable) {
            $verified = false;
        }

        if (!$verified) {
            // COMPENSATION on verification failure
            try {
                $this->reservationService->release($reservationToken, 'verification_failed');
            } catch (Throwable) {
            }
            try {
                $this->serviceService->releasePlacement($serviceId, ServicePlacement::STATUS_EVICTED);
            } catch (Throwable) {
            }

            $classification = ProvisioningErrorClassifier::classify(
                'Remote account verification failed after creation.',
                'VERIFICATION_FAILED'
            );
            $verifyFail = ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: 'Remote account verification failed.',
                errorCode: 'VERIFICATION_FAILED'
            );
            $this->operationService->recordAttempt($opUuid, $verifyFail, $classification);

            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::VERIFY,
                errorMessage: 'Remote account verification failed after creation.',
                errorCode: 'VERIFICATION_FAILED',
                completedSteps: $completedSteps,
                serverId: $serverId,
                reservationToken: $reservationToken,
                classification: $classification,
                operationUuid: $opUuid
            );
        }

        $completedSteps[] = HostingProvisioningStep::VERIFY;

        // ====================================================================
        // STEP 6: ACTIVATE
        // ====================================================================
        try {
            // Commit reservation permanently
            $this->reservationService->commit($reservationToken, $serviceId);

            // Update placement status to placed
            $this->serviceService->assignPlacement($serviceId, [
                'server_id' => $serverId,
                'server_pool_id' => $selectedServer->getServerPoolId(),
                'location_id' => $selectedServer->getLocationId(),
                'status' => ServicePlacement::STATUS_PLACED,
                'package_identifier' => $package,
                'dedicated_ip' => $remoteIp,
                'hostname' => $selectedServer->getHostname(),
                'disk_limit_mb' => $request->getRequiredDiskMb(),
                'bandwidth_limit_mb' => $request->getRequiredBandwidthMb(),
            ]);

            // Activate service entity
            $this->serviceService->activateService($serviceId, [
                'domain' => $domain,
                'username' => $username,
                'password_encrypted' => $password,
                'server_name' => $selectedServer->getName(),
                'ip_address' => $remoteIp,
            ]);

            // Record completed operation in audit log
            $this->operationService->recordAttempt($opUuid, $remoteResult);
        } catch (Throwable $e) {
            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            return HostingProvisioningResult::failure(
                serviceId: $serviceId,
                failedStep: HostingProvisioningStep::ACTIVATE,
                errorMessage: "Activation step failed: {$e->getMessage()}",
                errorCode: 'ACTIVATION_FAILED',
                completedSteps: $completedSteps,
                serverId: $serverId,
                reservationToken: $reservationToken,
                classification: $classification,
                operationUuid: $opUuid
            );
        }

        $completedSteps[] = HostingProvisioningStep::ACTIVATE;

        return HostingProvisioningResult::success(
            serviceId: $serviceId,
            serverId: $serverId,
            remoteIdentifier: $username,
            remoteIp: $remoteIp,
            completedSteps: $completedSteps,
            reservationToken: $reservationToken,
            nameservers: $nameservers,
            operationUuid: $opUuid,
            data: [
                'username' => $username,
                'domain' => $domain,
                'package' => $package,
                'server_name' => $selectedServer->getName(),
                'server_hostname' => $selectedServer->getHostname(),
            ]
        );
    }

    /**
     * Generate compliant, clean cPanel username:
     * - lowercase alphanumeric
     * - begins with letter
     * - length 8-12 characters
     */
    public function generateCpanelUsername(string $domain, int $serviceId): string
    {
        $domainClean = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));
        if ($domainClean === '' || !ctype_alpha($domainClean[0])) {
            $domainClean = 'usr' . $domainClean;
        }

        $base = substr($domainClean, 0, 8);
        $suffix = (string)($serviceId % 1000);
        $candidate = substr($base, 0, 16 - strlen($suffix)) . $suffix;

        if (in_array($candidate, self::$reservedUsernames, true)) {
            $candidate = 'c' . $candidate;
        }

        return substr($candidate, 0, 16);
    }

    public function isValidCpanelUsername(string $username): bool
    {
        if (strlen($username) < 1 || strlen($username) > 16) {
            return false;
        }

        if (!preg_match('/^[a-z][a-z0-9]{0,15}$/', $username)) {
            return false;
        }

        if (in_array($username, self::$reservedUsernames, true)) {
            return false;
        }

        return true;
    }

    private function generateSecurePassword(): string
    {
        return 'P@ss-' . bin2hex(random_bytes(8));
    }
}
