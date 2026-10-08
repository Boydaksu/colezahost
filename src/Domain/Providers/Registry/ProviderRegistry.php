<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Registry;

use Coleza\Domain\Providers\Contracts\ProviderInterface;
use Coleza\Domain\Providers\Exceptions\ProviderException;
use Coleza\Domain\Providers\Exceptions\UnsupportedCapabilityException;

final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /**
     * @param array<ProviderInterface> $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(ProviderInterface $provider): void
    {
        $this->providers[$provider->getSlug()] = $provider;
    }

    public function has(string $slug): bool
    {
        return isset($this->providers[$slug]);
    }

    public function get(string $slug): ProviderInterface
    {
        if (!isset($this->providers[$slug])) {
            throw new ProviderException(
                message: "Provider '{$slug}' is not registered.",
                errorCode: 'PROVIDER_NOT_FOUND',
                context: ['slug' => $slug]
            );
        }

        return $this->providers[$slug];
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getByCapability(string $capability): array
    {
        return array_filter($this->providers, fn (ProviderInterface $p) => $p->supports($capability));
    }

    public function assertSupports(string $slug, string $capability): void
    {
        $provider = $this->get($slug);
        if (!$provider->supports($capability)) {
            throw new UnsupportedCapabilityException($slug, $capability);
        }
    }
}
