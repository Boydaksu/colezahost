<?php
declare(strict_types=1);
namespace Coleza\Domain\Commerce\Services;
final class ServiceProviderLifecycleRequiredException extends \RuntimeException
{
    public function __construct() { parent::__construct('Provider lifecycle workflow required; local-only automation refused.'); }
}
