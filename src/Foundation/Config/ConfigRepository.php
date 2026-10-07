<?php

declare(strict_types=1);

namespace Coleza\Foundation\Config;

final class ConfigRepository
{
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$this->items;

        while (count($keys) > 1) {
            $k = array_shift($keys);
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current[array_shift($keys)] = $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Load all .php configuration files from a directory.
     * Each file's base name becomes the top-level configuration key.
     */
    public function loadFromDirectory(string $directory): void
    {
        if (!is_dir($directory) || !is_readable($directory)) {
            return;
        }

        $files = glob(rtrim($directory, '/\\') . '/*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $configValues = require $file;
            if (is_array($configValues)) {
                $this->items[$key] = $configValues;
            }
        }
    }
}
