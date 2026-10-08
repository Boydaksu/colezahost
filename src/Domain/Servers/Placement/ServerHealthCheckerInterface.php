<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Placement;

use Coleza\Domain\Servers\Entities\Server;

interface ServerHealthCheckerInterface
{
    public function checkHealth(Server $server): ServerHealthResult;
}
