<?php

declare(strict_types=1);

namespace Coleza\Foundation\Cache;

use Coleza\Foundation\Container\Container;
use Coleza\Foundation\Database\Connection;
use InvalidArgumentException;

final class CacheManager
{
    /** @var array<string, CacheInterface> */
    private array $stores = [];

    public function __construct(
        private string $defaultDriver,
        private string $cacheDirectory,
        private ?Container $container = null
    ) {
    }

    public function store(?string $name = null): CacheInterface
    {
        $name = $name ?: $this->defaultDriver;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        $store = match ($name) {
            'array' => new ArrayCache(),
            'file' => new FileCache($this->cacheDirectory),
            'database' => new DatabaseCache($this->getDatabaseConnection()),
            default => throw new InvalidArgumentException(sprintf('Unsupported cache store: %s', $name)),
        };

        $this->stores[$name] = $store;
        return $store;
    }

    private function getDatabaseConnection(): Connection
    {
        $container = $this->container ?? Container::getInstance();
        return $container->get(Connection::class);
    }
}
