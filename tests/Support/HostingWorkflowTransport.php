<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Providers\Cpanel\{CpanelHttpTransportInterface,CpanelMemoryTransport};
final class HostingWorkflowTransport implements CpanelHttpTransportInterface
{
    public ?\Closure $after=null;
    public CpanelMemoryTransport $memory;
    public function __construct(){$this->memory=new CpanelMemoryTransport();}
    public function request(string $method,string $url,array $headers=[],array $queryParams=[],?array $bodyParams=null,int $timeout=30):array
    {
        $result=$this->memory->request($method,$url,$headers,$queryParams,$bodyParams,$timeout);$this->after?->__invoke($url);return $result;
    }
}
