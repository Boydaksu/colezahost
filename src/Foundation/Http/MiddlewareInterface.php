<?php

declare(strict_types=1);

namespace Coleza\Foundation\Http;

use Closure;

interface MiddlewareInterface
{
    /**
     * Handle an incoming HTTP request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
