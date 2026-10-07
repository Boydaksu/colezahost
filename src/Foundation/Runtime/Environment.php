<?php

declare(strict_types=1);

namespace Coleza\Foundation\Runtime;

final class Environment
{
    /** @var array<string, string> */
    private array $variables = [];

    /**
     * @param array<string, string> $initialVariables
     */
    public function __construct(array $initialVariables = [])
    {
        $this->variables = $initialVariables;
    }

    /**
     * Load environment configuration from a simple .env key-value file if present.
     */
    public static function fromFile(string $filePath): self
    {
        $env = new self();
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $env;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $env;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);
                // Strip quotes if wrapped
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                $env->set($key, $val);
            }
        }

        return $env;
    }

    public function set(string $key, string $value): void
    {
        $this->variables[$key] = $value;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->variables)) {
            return $this->variables[$key];
        }

        $envVal = getenv($key);
        if ($envVal !== false) {
            return $envVal;
        }

        return $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $val = $this->get($key);
        if ($val === null) {
            return $default;
        }

        return in_array(strtolower($val), ['true', '1', 'yes', 'on'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $val = $this->get($key);
        if ($val === null || !is_numeric($val)) {
            return $default;
        }

        return (int) $val;
    }

    /**
     * @return array<string, string> Masked variables safe for telemetry/display
     */
    public function toMaskedArray(): array
    {
        $masked = [];
        $sensitiveKeys = ['PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'SALT', 'AUTH', 'CREDENTIAL'];

        foreach ($this->variables as $k => $v) {
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (stripos($k, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            $masked[$k] = $isSensitive ? '********' : $v;
        }

        return $masked;
    }
}
