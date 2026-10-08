<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Contracts;

use Coleza\Domain\Providers\Exceptions\UnsupportedCapabilityException;

final class ProviderCapabilitySet
{
    /** @var array<string, bool> */
    private array $capabilities = [];

    /**
     * @param array<string> $capabilities
     */
    public function __construct(array $capabilities = [])
    {
        foreach ($capabilities as $cap) {
            $this->capabilities[$cap] = true;
        }
    }

    public function has(string $capability): bool
    {
        return isset($this->capabilities[$capability]);
    }

    public function supports(string $capability): bool
    {
        return $this->has($capability);
    }

    /**
     * @return array<string>
     */
    public function all(): array
    {
        return array_keys($this->capabilities);
    }

    public function with(string ...$capabilities): self
    {
        $merged = array_merge($this->all(), $capabilities);
        return new self($merged);
    }

    public function without(string ...$capabilities): self
    {
        $removeMap = array_fill_keys($capabilities, true);
        $filtered = array_filter($this->all(), fn ($c) => !isset($removeMap[$c]));
        return new self(array_values($filtered));
    }

    public function assertSupported(string $providerSlug, string $capability): void
    {
        if (!$this->has($capability)) {
            throw new UnsupportedCapabilityException($providerSlug, $capability);
        }
    }

    /**
     * @return array<string>
     */
    public function toArray(): array
    {
        return $this->all();
    }
}
