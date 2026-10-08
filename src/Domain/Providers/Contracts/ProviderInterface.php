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

interface ProviderInterface
{
    public function getName(): string;

    public function getSlug(): string;

    public function getVersion(): string;

    public function getCapabilities(): ProviderCapabilitySet;

    public function supports(string $capability): bool;

    public function createAccount(CreateAccountOperation $operation): ProviderOperationResult;

    public function suspendAccount(SuspendAccountOperation $operation): ProviderOperationResult;

    public function unsuspendAccount(UnsuspendAccountOperation $operation): ProviderOperationResult;

    public function terminateAccount(TerminateAccountOperation $operation): ProviderOperationResult;

    public function changePackage(ChangePackageOperation $operation): ProviderOperationResult;

    public function changePassword(ChangePasswordOperation $operation): ProviderOperationResult;

    public function getUsageMetrics(UsageMetricsOperation $operation): ProviderOperationResult;

    public function getSingleSignOnUrl(SingleSignOnOperation $operation): ProviderOperationResult;

    public function executeCustomAction(CustomActionOperation $operation): ProviderOperationResult;
}
