<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use Coleza\Domain\Providers\Contracts\AbstractProvider;
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Contracts\ProviderCapabilitySet;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Operations\SuspendAccountOperation;
use Coleza\Domain\Providers\Operations\TerminateAccountOperation;
use Coleza\Domain\Providers\Operations\UnsuspendAccountOperation;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\ServerHealthResult;

final class CpanelProvider extends AbstractProvider
{
    public const SLUG = 'cpanel';
    public const NAME = 'cPanel & WHM';
    public const VERSION = '1.0.0';

    private CpanelPackageService $packageService;
    private CpanelServerHealthChecker $healthChecker;

    public function __construct(
        private ?CpanelHttpTransportInterface $transport = null,
        ?CpanelPackageService $packageService = null,
        ?CpanelServerHealthChecker $healthChecker = null
    ) {
        $capabilities = new ProviderCapabilitySet([
            ProviderCapability::CREATE_ACCOUNT,
            ProviderCapability::SUSPEND_ACCOUNT,
            ProviderCapability::UNSUSPEND_ACCOUNT,
            ProviderCapability::TERMINATE_ACCOUNT,
            ProviderCapability::CHANGE_PACKAGE,
            ProviderCapability::CHANGE_PASSWORD,
            ProviderCapability::USAGE_METRICS,
            ProviderCapability::SINGLE_SIGN_ON,
            ProviderCapability::CUSTOM_ACTION,
        ]);

        parent::__construct(
            name: self::NAME,
            slug: self::SLUG,
            version: self::VERSION,
            capabilities: $capabilities
        );

        $this->packageService = $packageService ?? new CpanelPackageService();
        $this->healthChecker = $healthChecker ?? new CpanelServerHealthChecker($this->transport);
    }

    public function createClient(ServerConnectionDto $connection): CpanelApiClient
    {
        $config = CpanelConfiguration::fromServerConnection($connection);
        return new CpanelApiClient($config, $this->transport);
    }

    /**
     * @return array{authenticated: bool, version: string, latency_ms: int}
     */
    public function testAuthentication(ServerConnectionDto $connection): array
    {
        $client = $this->createClient($connection);
        return $client->testAuthentication();
    }

    /**
     * @return array<string, CpanelPackageDto>
     */
    public function listPackages(ServerConnectionDto $connection): array
    {
        $client = $this->createClient($connection);
        return $this->packageService->listPackages($client);
    }

    public function checkHealth(Server $server): ServerHealthResult
    {
        return $this->healthChecker->checkHealth($server);
    }

    public function getPackageService(): CpanelPackageService
    {
        return $this->packageService;
    }

    public function getHealthChecker(): CpanelServerHealthChecker
    {
        return $this->healthChecker;
    }

    public function getTransport(): ?CpanelHttpTransportInterface
    {
        return $this->transport;
    }

    public function createAccount(CreateAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CREATE_ACCOUNT);

        $server = $operation->getServer();
        if ($server === null) {
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: 'No server connection information provided for cPanel account creation.',
                errorCode: 'MISSING_SERVER_CONNECTION'
            );
        }

        try {
            $client = $this->createClient($server);
            $resolvedPackage = $this->packageService->resolvePackage($client, $operation->getPackageIdentifier());

            $metadata = $operation->getMetadata();
            $quotas = $operation->getResourceQuotas();

            $params = [
                'username' => $operation->getUsername(),
                'domain' => $operation->getDomain(),
                'password' => $operation->getPassword(),
                'plan' => $resolvedPackage->getName(),
            ];

            if (isset($metadata['contact_email'])) {
                $params['contactemail'] = (string)$metadata['contact_email'];
            } elseif (isset($metadata['email'])) {
                $params['contactemail'] = (string)$metadata['email'];
            }

            if (isset($quotas['disk_limit_mb']) && (int)$quotas['disk_limit_mb'] > 0) {
                $params['quota'] = (int)$quotas['disk_limit_mb'];
            }
            if (isset($quotas['bandwidth_limit_mb']) && (int)$quotas['bandwidth_limit_mb'] > 0) {
                $params['bwlimit'] = (int)$quotas['bandwidth_limit_mb'];
            }
            if (isset($metadata['dedicated_ip']) && (bool)$metadata['dedicated_ip']) {
                $params['ip'] = 'y';
            }

            $response = $client->createAccount($params);
            $result = (int)($response['metadata']['result'] ?? ($response['result'][0]['status'] ?? 0));
            $reason = (string)($response['metadata']['reason'] ?? ($response['result'][0]['statusmsg'] ?? ''));

            if ($result === 1) {
                $data = (array)($response['data'] ?? []);
                return ProviderOperationResult::success(
                    operationType: ProviderCapability::CREATE_ACCOUNT,
                    message: "cPanel account '{$operation->getUsername()}' created successfully.",
                    externalIdentifier: $operation->getUsername(),
                    data: [
                        'username' => $operation->getUsername(),
                        'domain' => $operation->getDomain(),
                        'package' => $resolvedPackage->getName(),
                        'ip' => $data['ip'] ?? $server->getIpAddress(),
                        'nameserver' => $data['nameserver'] ?? null,
                        'nameserver2' => $data['nameserver2'] ?? null,
                    ],
                    rawResponse: $response
                );
            }

            $classification = ProvisioningErrorClassifier::classify($reason, 'WHM_CREATEACCT_FAILED', null, $response);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: $classification->getErrorCode(),
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                    'retryable' => $classification->isRetryable(),
                    'raw_reason' => $reason,
                ],
                rawResponse: $response
            );
        } catch (\Throwable $e) {
            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::CREATE_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: 'PROVIDER_EXCEPTION',
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                    'exception_class' => get_class($e),
                ]
            );
        }
    }

    public function suspendAccount(SuspendAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::SUSPEND_ACCOUNT);

        $server = $operation->getServer();
        if ($server === null) {
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::SUSPEND_ACCOUNT,
                message: 'No server connection provided for account suspension.',
                errorCode: 'MISSING_SERVER_CONNECTION'
            );
        }

        try {
            $client = $this->createClient($server);
            $username = $operation->getUsername();
            $reason = $operation->getReason();

            $response = $client->suspendAccount($username, $reason);
            $result = (int)($response['metadata']['result'] ?? ($response['result'][0]['status'] ?? 0));
            $msg = (string)($response['metadata']['reason'] ?? ($response['result'][0]['statusmsg'] ?? ''));

            $isAlreadySuspended = str_contains(strtolower($msg), 'already suspended') || str_contains(strtolower($msg), 'is suspended');

            if ($result === 1 || $isAlreadySuspended) {
                return ProviderOperationResult::success(
                    operationType: ProviderCapability::SUSPEND_ACCOUNT,
                    message: "cPanel account '{$username}' suspended successfully.",
                    externalIdentifier: $username,
                    data: [
                        'username' => $username,
                        'reason' => $reason,
                        'idempotent' => $isAlreadySuspended,
                    ],
                    rawResponse: $response
                );
            }

            $classification = ProvisioningErrorClassifier::classify($msg, 'WHM_SUSPENDACCT_FAILED', null, $response);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::SUSPEND_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: $classification->getErrorCode(),
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                    'raw_reason' => $msg,
                ],
                rawResponse: $response
            );
        } catch (\Throwable $e) {
            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::SUSPEND_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: 'PROVIDER_EXCEPTION',
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                ]
            );
        }
    }

    public function unsuspendAccount(UnsuspendAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::UNSUSPEND_ACCOUNT);

        $server = $operation->getServer();
        if ($server === null) {
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::UNSUSPEND_ACCOUNT,
                message: 'No server connection provided for account unsuspension.',
                errorCode: 'MISSING_SERVER_CONNECTION'
            );
        }

        try {
            $client = $this->createClient($server);
            $username = $operation->getUsername();

            $response = $client->unsuspendAccount($username);
            $result = (int)($response['metadata']['result'] ?? ($response['result'][0]['status'] ?? 0));
            $msg = (string)($response['metadata']['reason'] ?? ($response['result'][0]['statusmsg'] ?? ''));

            $isNotSuspended = str_contains(strtolower($msg), 'not suspended') || str_contains(strtolower($msg), 'is not suspended');

            if ($result === 1 || $isNotSuspended) {
                return ProviderOperationResult::success(
                    operationType: ProviderCapability::UNSUSPEND_ACCOUNT,
                    message: "cPanel account '{$username}' unsuspended successfully.",
                    externalIdentifier: $username,
                    data: [
                        'username' => $username,
                        'idempotent' => $isNotSuspended,
                    ],
                    rawResponse: $response
                );
            }

            $classification = ProvisioningErrorClassifier::classify($msg, 'WHM_UNSUSPENDACCT_FAILED', null, $response);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::UNSUSPEND_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: $classification->getErrorCode(),
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                    'raw_reason' => $msg,
                ],
                rawResponse: $response
            );
        } catch (\Throwable $e) {
            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::UNSUSPEND_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: 'PROVIDER_EXCEPTION',
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                ]
            );
        }
    }

    public function terminateAccount(TerminateAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::TERMINATE_ACCOUNT);

        $server = $operation->getServer();
        if ($server === null) {
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::TERMINATE_ACCOUNT,
                message: 'No server connection provided for account termination.',
                errorCode: 'MISSING_SERVER_CONNECTION'
            );
        }

        try {
            $client = $this->createClient($server);
            $username = $operation->getUsername();

            $response = $client->terminateAccount($username, keepDns: false);
            $result = (int)($response['metadata']['result'] ?? ($response['result'][0]['status'] ?? 0));
            $msg = (string)($response['metadata']['reason'] ?? ($response['result'][0]['statusmsg'] ?? ''));

            $isAlreadyRemoved = str_contains(strtolower($msg), 'does not exist') || str_contains(strtolower($msg), 'unknown user') || str_contains(strtolower($msg), 'not found');

            if ($result === 1 || $isAlreadyRemoved) {
                return ProviderOperationResult::success(
                    operationType: ProviderCapability::TERMINATE_ACCOUNT,
                    message: "cPanel account '{$username}' terminated successfully.",
                    externalIdentifier: $username,
                    data: [
                        'username' => $username,
                        'idempotent' => $isAlreadyRemoved,
                    ],
                    rawResponse: $response
                );
            }

            $classification = ProvisioningErrorClassifier::classify($msg, 'WHM_REMOVEACCT_FAILED', null, $response);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::TERMINATE_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: $classification->getErrorCode(),
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                    'raw_reason' => $msg,
                ],
                rawResponse: $response
            );
        } catch (\Throwable $e) {
            $classification = ProvisioningErrorClassifier::classifyThrowable($e);
            return ProviderOperationResult::failure(
                operationType: ProviderCapability::TERMINATE_ACCOUNT,
                message: $classification->getClientSafeMessage(),
                errorCode: 'PROVIDER_EXCEPTION',
                data: [
                    'admin_advice' => $classification->getAdminActionableMessage(),
                    'category' => $classification->getCategory(),
                ]
            );
        }
    }
}
