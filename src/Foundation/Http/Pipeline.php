<?php

declare(strict_types=1);

namespace Coleza\Foundation\Http;

use Closure;

final class Pipeline
{
    /** @var array<int, MiddlewareInterface|callable> */
    private array $middlewares = [];

    /**
     * @param array<int, MiddlewareInterface|callable> $middlewares
     */
    public function __construct(array $middlewares = [])
    {
        $this->middlewares = $middlewares;
    }

    public function pipe(MiddlewareInterface|callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Run the pipeline against a request and final destination handler.
     *
     * @param Closure(Request): Response $destination
     */
    public function run(Request $request, Closure $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function (Closure $next, MiddlewareInterface|callable $middleware): Closure {
                return function (Request $req) use ($next, $middleware): Response {
                    if ($middleware instanceof MiddlewareInterface) {
                        return $middleware->handle($req, $next);
                    }
                    return $middleware($req, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}
