<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Workflows;
use Coleza\Foundation\Database\Connection;
/** Trusted host policy. All approvals run in a short local transaction, never around network I/O. */
interface HostingAdmission
{
    public function connection(): Connection;
    public function approve(int $serviceId, string $action, ?HostingProvisioningRequest $request = null): array;
    public function record(int $serviceId, string $action, string $operationUuid, string $phase): void;
}
