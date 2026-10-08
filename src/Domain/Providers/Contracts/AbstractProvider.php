<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Contracts;

use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\Operations\ChangePackageOperation;
use Coleza\Domain\Providers\Operations\ChangePasswordOperation;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Operations\CustomActionOperation;
use Coleza\Domain\Providers\Operations\SingleSignOnOperation;
use Coleza\Domain\Providers\Operations\SuspendAccountOperation;
use Coleza\Domain\Providers\Operations\TerminateAccountOperation;
use Coleza\Domain\Providers\Operations\UnsuspendAccountOperation;
use Coleza\Domain\Providers\Operations\UsageMetricsOperation;

abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(
        protected string $name,
        protected string $slug,
        protected string $version,
        protected ProviderCapabilitySet $capabilities
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCapabilities(): ProviderCapabilitySet
    {
        return $this->capabilities;
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities->has($capability);
    }

    protected function assertCapability(string $capability): void
    {
        $this->capabilities->assertSupported($this->getSlug(), $capability);
    }

    public function createAccount(CreateAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CREATE_ACCOUNT);
        return ProviderOperationResult::success(ProviderCapability::CREATE_ACCOUNT, 'Account created successfully');
    }

    public function suspendAccount(SuspendAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::SUSPEND_ACCOUNT);
        return ProviderOperationResult::success(ProviderCapability::SUSPEND_ACCOUNT, 'Account suspended successfully');
    }

    public function unsuspendAccount(UnsuspendAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::UNSUSPEND_ACCOUNT);
        return ProviderOperationResult::success(ProviderCapability::UNSUSPEND_ACCOUNT, 'Account unsuspended successfully');
    }

    public function terminateAccount(TerminateAccountOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::TERMINATE_ACCOUNT);
        return ProviderOperationResult::success(ProviderCapability::TERMINATE_ACCOUNT, 'Account terminated successfully');
    }

    public function changePackage(ChangePackageOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CHANGE_PACKAGE);
        return ProviderOperationResult::success(ProviderCapability::CHANGE_PACKAGE, 'Package changed successfully');
    }

    public function changePassword(ChangePasswordOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CHANGE_PASSWORD);
        return ProviderOperationResult::success(ProviderCapability::CHANGE_PASSWORD, 'Password changed successfully');
    }

    public function getUsageMetrics(UsageMetricsOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::USAGE_METRICS);
        return ProviderOperationResult::success(ProviderCapability::USAGE_METRICS, 'Usage metrics retrieved successfully');
    }

    public function getSingleSignOnUrl(SingleSignOnOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::SINGLE_SIGN_ON);
        return ProviderOperationResult::success(ProviderCapability::SINGLE_SIGN_ON, 'Single sign-on generated successfully');
    }

    public function executeCustomAction(CustomActionOperation $operation): ProviderOperationResult
    {
        $this->assertCapability(ProviderCapability::CUSTOM_ACTION);
        return ProviderOperationResult::success(ProviderCapability::CUSTOM_ACTION, "Custom action '{$operation->getAction()}' executed");
    }
}
