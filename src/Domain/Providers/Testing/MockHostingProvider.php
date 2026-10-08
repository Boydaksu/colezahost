<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Testing;

use Coleza\Domain\Providers\Contracts\AbstractProvider;
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Contracts\ProviderCapabilitySet;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Operations\ChangePackageOperation;
use Coleza\Domain\Providers\Operations\ChangePasswordOperation;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Operations\CustomActionOperation;
use Coleza\Domain\Providers\Operations\OperationInterface;
use Coleza\Domain\Providers\Operations\SingleSignOnOperation;
use Coleza\Domain\Providers\Operations\SuspendAccountOperation;
use Coleza\Domain\Providers\Operations\TerminateAccountOperation;
use Coleza\Domain\Providers\Operations\UnsuspendAccountOperation;
use Coleza\Domain\Providers\Operations\UsageMetricsOperation;

final class MockHostingProvider extends AbstractProvider
{
    /** @var array<OperationInterface> */
    private array $recordedOperations = [];

    /** @var array<string, ProviderOperationResult> */
    private array $cannedResponses = [];

    public function __construct(
        string $name = 'Mock Hosting Provider',
        string $slug = 'mock-hosting',
        string $version = '1.0.0',
        ?ProviderCapabilitySet $capabilities = null
    ) {
        $caps = $capabilities ?? new ProviderCapabilitySet(ProviderCapability::all());
        parent::__construct($name, $slug, $version, $caps);
    }

    public function setCannedResponse(string $operationType, ProviderOperationResult $result): void
    {
        $this->cannedResponses[$operationType] = $result;
    }

    /**
     * @return array<OperationInterface>
     */
    public function getRecordedOperations(): array
    {
        return $this->recordedOperations;
    }

    public function getLastOperation(): ?OperationInterface
    {
        if (empty($this->recordedOperations)) {
            return null;
        }
        return $this->recordedOperations[count($this->recordedOperations) - 1];
    }

    public function clearRecordedOperations(): void
    {
        $this->recordedOperations = [];
    }

    public function createAccount(CreateAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CREATE_ACCOUNT);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::CREATE_ACCOUNT])) {
            return $this->cannedResponses[ProviderCapability::CREATE_ACCOUNT];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::CREATE_ACCOUNT,
            message: "Mock account created for {$operation->getUsername()}",
            externalIdentifier: "ext-user-{$operation->getUsername()}",
            data: [
                'username' => $operation->getUsername(),
                'domain' => $operation->getDomain(),
                'package' => $operation->getPackageIdentifier(),
            ]
        );
    }

    public function suspendAccount(SuspendAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::SUSPEND_ACCOUNT);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::SUSPEND_ACCOUNT])) {
            return $this->cannedResponses[ProviderCapability::SUSPEND_ACCOUNT];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::SUSPEND_ACCOUNT,
            message: "Mock account suspended for {$operation->getUsername()}: {$operation->getReason()}"
        );
    }

    public function unsuspendAccount(UnsuspendAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::UNSUSPEND_ACCOUNT);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::UNSUSPEND_ACCOUNT])) {
            return $this->cannedResponses[ProviderCapability::UNSUSPEND_ACCOUNT];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::UNSUSPEND_ACCOUNT,
            message: "Mock account unsuspended for {$operation->getUsername()}"
        );
    }

    public function terminateAccount(TerminateAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::TERMINATE_ACCOUNT);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::TERMINATE_ACCOUNT])) {
            return $this->cannedResponses[ProviderCapability::TERMINATE_ACCOUNT];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::TERMINATE_ACCOUNT,
            message: "Mock account terminated for {$operation->getUsername()}"
        );
    }

    public function changePackage(ChangePackageOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CHANGE_PACKAGE);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::CHANGE_PACKAGE])) {
            return $this->cannedResponses[ProviderCapability::CHANGE_PACKAGE];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::CHANGE_PACKAGE,
            message: "Mock package upgraded to {$operation->getNewPackageIdentifier()}"
        );
    }

    public function changePassword(ChangePasswordOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CHANGE_PASSWORD);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::CHANGE_PASSWORD])) {
            return $this->cannedResponses[ProviderCapability::CHANGE_PASSWORD];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::CHANGE_PASSWORD,
            message: "Mock password changed for {$operation->getUsername()}"
        );
    }

    public function getUsageMetrics(UsageMetricsOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::USAGE_METRICS);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::USAGE_METRICS])) {
            return $this->cannedResponses[ProviderCapability::USAGE_METRICS];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::USAGE_METRICS,
            message: "Mock usage retrieved for {$operation->getUsername()}",
            data: [
                'disk_used_mb' => 2048,
                'disk_limit_mb' => 10240,
                'bandwidth_used_mb' => 12500,
                'bandwidth_limit_mb' => 51200,
            ]
        );
    }

    public function getSingleSignOnUrl(SingleSignOnOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::SINGLE_SIGN_ON);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::SINGLE_SIGN_ON])) {
            return $this->cannedResponses[ProviderCapability::SINGLE_SIGN_ON];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::SINGLE_SIGN_ON,
            message: 'Mock SSO URL generated',
            data: [
                'sso_url' => "https://cpanel.example.com/cpsess12345/login?user={$operation->getUsername()}",
                'expires_in' => 300,
            ]
        );
    }

    public function executeCustomAction(CustomActionOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CUSTOM_ACTION);
        $this->recordedOperations[] = $operation;

        if (isset($this->cannedResponses[ProviderCapability::CUSTOM_ACTION])) {
            return $this->cannedResponses[ProviderCapability::CUSTOM_ACTION];
        }

        return ProviderOperationResult::success(
            operationType: ProviderCapability::CUSTOM_ACTION,
            message: "Mock custom action {$operation->getAction()} executed",
            data: $operation->getParameters()
        );
    }
}
