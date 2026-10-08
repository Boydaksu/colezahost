<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

use Coleza\Domain\Domains\Registrar\Adapters\NameSilo\NameSiloRegistrarAdapter;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Coleza\Foundation\Exceptions\ValidationException;

final class RegistrarAdapterFactory
{
    /**
     * @param RegistrarConfiguration $config
     * @param callable|null $httpClient
     * @return RegistrarProviderInterface
     */
    public function create(RegistrarConfiguration $config, ?callable $httpClient = null): RegistrarProviderInterface
    {
        return match (strtolower($config->getRegistrarId())) {
            'namesilo' => new NameSiloRegistrarAdapter($config, $httpClient),
            default => throw new ValidationException(
                ['provider' => "Unsupported registrar provider: '{$config->getRegistrarId()}'"],
                'Unsupported registrar provider'
            ),
        };
    }
}
