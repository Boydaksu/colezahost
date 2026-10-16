<?php
declare(strict_types=1);
namespace Coleza\Domain\Provisioning\Services;
final class HostingExecutionBusy extends \RuntimeException
{
    public function __construct(){parent::__construct('A live hosting command or reconciliation already owns this service.');}
}
