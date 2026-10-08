<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

use Coleza\Foundation\Exceptions\ValidationException;

final class RegistrarRegistry
{
    /**
     * @var array<string, RegistrarProviderInterface>
     */
    private array $providers = [];
    private ?string $defaultRegistrarId = null;

    /**
     * @param array<int, RegistrarProviderInterface> $initialProviders
     */
    public function __construct(array $initialProviders = [])
    {
        foreach ($initialProviders as $provider) {
            $this->register($provider);
        }
    }

    public function register(RegistrarProviderInterface $provider): void
    {
        $id = strtolower(trim($provider->getRegistrarId()));
        $this->providers[$id] = $provider;

        if ($this->defaultRegistrarId === null) {
            $this->defaultRegistrarId = $id;
        }
    }

    public function has(string $registrarId): bool
    {
        return isset($this->providers[strtolower(trim($registrarId))]);
    }

    public function get(string $registrarId): RegistrarProviderInterface
    {
        $id = strtolower(trim($registrarId));
        if (!isset($this->providers[$id])) {
            throw new ValidationException(
                ['registrar' => "No registrar provider registered with ID '{$registrarId}'."],
                'Unknown registrar'
            );
        }
        return $this->providers[$id];
    }

    /**
     * @return array<string, RegistrarProviderInterface>
     */
    public function list(): array
    {
        return $this->providers;
    }

    public function setDefault(string $registrarId): void
    {
        $id = strtolower(trim($registrarId));
        if (!isset($this->providers[$id])) {
            throw new ValidationException(
                ['registrar' => "Cannot set unknown registrar '{$registrarId}' as default."],
                'Unknown registrar'
            );
        }
        $this->defaultRegistrarId = $id;
    }

    public function getDefault(): ?RegistrarProviderInterface
    {
        if ($this->defaultRegistrarId === null) {
            return null;
        }
        return $this->providers[$this->defaultRegistrarId] ?? null;
    }
}
