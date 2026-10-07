<?php

declare(strict_types=1);

namespace Coleza\Foundation\Http\Middleware;

use Closure;
use Coleza\Foundation\Http\MiddlewareInterface;
use Coleza\Foundation\Http\Request;
use Coleza\Foundation\Http\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
