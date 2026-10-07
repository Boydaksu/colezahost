<?php

declare(strict_types=1);

namespace Coleza\Foundation\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

final class Container implements ContainerInterface
{
    private static ?self $instance = null;

    /** @var array<string, array{concrete: mixed, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, ServiceProviderInterface> */
    private array $providers = [];

    private bool $booted = false;

    /** @var array<string, bool> Tracks currently resolving classes to detect circular dependencies */
    private array $currentlyResolving = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    public function bind(string $id, mixed $concrete = null, bool $shared = false): void
    {
        if ($concrete === null) {
            $concrete = $id;
        }

        unset($this->instances[$id]);

        $this->bindings[$id] = [
            'concrete' => $concrete,
            'shared' => $shared,
        ];
    }

    public function singleton(string $id, mixed $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        try {
            return $this->resolve($id);
        } catch (ContainerException $e) {
            throw $e;
        } catch (NotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ContainerException(sprintf('Failed to resolve entry "%s": %s', $id, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($id, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolve(string $id, array $parameters = []): mixed
    {
        // 1. If already resolved as a shared singleton instance, return it
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Circular dependency detection
        if (isset($this->currentlyResolving[$id])) {
            throw new ContainerException(sprintf('Circular dependency detected while resolving "%s".', $id));
        }

        $concrete = $id;
        $shared = false;

        if (isset($this->bindings[$id])) {
            $concrete = $this->bindings[$id]['concrete'];
            $shared = $this->bindings[$id]['shared'];
        }

        $this->currentlyResolving[$id] = true;

        try {
            // If concrete is a Closure, invoke it
            if ($concrete instanceof Closure) {
                $object = $concrete($this, $parameters);
            } elseif (is_object($concrete) && !($concrete instanceof Closure)) {
                $object = $concrete;
            } elseif (is_string($concrete)) {
                $object = $this->build($concrete, $parameters);
            } else {
                throw new ContainerException(sprintf('Target [%s] is not instantiable.', $id));
            }

            if ($shared) {
                $this->instances[$id] = $object;
            }

            return $object;
        } finally {
            unset($this->currentlyResolving[$id]);
        }
    }

    /**
     * Build a concrete class using Reflection and auto-wiring.
     *
     * @param array<string, mixed> $parameters
     */
    private function build(string $concrete, array $parameters = []): mixed
    {
        if (!class_exists($concrete)) {
            throw new NotFoundException(sprintf('Class "%s" does not exist.', $concrete));
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new ContainerException(sprintf('Class "%s" is not reflectable.', $concrete), 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new ContainerException(sprintf('Class "%s" is not instantiable.', $concrete));
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * @param array<ReflectionParameter> $dependencies
     * @param array<string, mixed> $parameters
     * @return array<int, mixed>
     */
    private function resolveDependencies(array $dependencies, array $parameters): array
    {
        $results = [];

        foreach ($dependencies as $parameter) {
            $name = $parameter->getName();

            // Override parameter provided directly
            if (array_key_exists($name, $parameters)) {
                $results[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $results[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new ContainerException(sprintf('Cannot resolve un-typed parameter "$%s" without a default value.', $name));
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
                $results[] = $this->get($className);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $results[] = $parameter->getDefaultValue();
                continue;
            }

            throw new ContainerException(sprintf('Cannot resolve primitive parameter "$%s" without a default value.', $name));
        }

        return $results;
    }

    public function register(ServiceProviderInterface|string $provider): void
    {
        if (is_string($provider)) {
            $provider = $this->build($provider);
        }

        $class = get_class($provider);
        $this->providers[$class] = $provider;

        $provider->register($this);

        if ($this->booted) {
            $provider->boot($this);
        }
    }

    public function bootProviders(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this);
        }

        $this->booted = true;
    }
}
