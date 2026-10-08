<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Settings;

final class ResolvedProviderConfig
{
    /**
     * @param array<string, mixed> $values
     * @param array<string> $secretKeys
     */
    public function __construct(
        private string $providerSlug,
        private array $values = [],
        private array $secretKeys = []
    ) {
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        return isset($this->values[$key]) ? (string)$this->values[$key] : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return isset($this->values[$key]) ? (int)$this->values[$key] : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return isset($this->values[$key]) ? (bool)$this->values[$key] : $default;
    }

    public function getSecret(string $key): ?string
    {
        return isset($this->values[$key]) ? (string)$this->values[$key] : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Safe serialization that masks all sensitive secret keys.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        $safe = [];
        $secretMap = array_fill_keys($this->secretKeys, true);

        foreach ($this->values as $key => $value) {
            if (isset($secretMap[$key])) {
                $safe[$key] = '••••••••';
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
