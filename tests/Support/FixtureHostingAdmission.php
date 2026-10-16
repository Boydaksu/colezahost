<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Provisioning\Workflows\{HostingAdmission,HostingProvisioningRequest};
use Coleza\Foundation\Database\Connection;
final class FixtureHostingAdmission implements HostingAdmission
{
    public bool $allowed = true;
    public bool $failAudit = false;
    public function __construct(private Connection $db) {}
    public function connection(): Connection {return $this->db;}
    public function approve(int $serviceId,string $action,?HostingProvisioningRequest $request=null):array
    {
        if(!$this->allowed){throw new \RuntimeException('Fixture authority revoked.');}
        return ['version'=>1,'actor_id'=>3,'scope_sha256'=>hash('sha256',(string)$serviceId)];
    }
    public function record(int $serviceId,string $action,string $operationUuid,string $phase):void
    {
        if($phase==='completed'&&$this->failAudit){throw new \RuntimeException('Fixture audit failure.');}
    }
}
