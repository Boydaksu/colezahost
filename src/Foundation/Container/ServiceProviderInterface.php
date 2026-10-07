<?php

declare(strict_types=1);

namespace Coleza\Foundation\Container;

interface ServiceProviderInterface
{
    /**
     * Register bindings and services in the container.
     */
    public function register(Container $container): void;

    /**
     * Bootstrap/configure registered services if needed.
     */
    public function boot(Container $container): void;
}
