<?php

declare(strict_types=1);

namespace Coleza\Foundation\Http;

use Closure;

final class Route
{
    /** @var array<int, MiddlewareInterface|string> */
    private array $middlewares = [];

    /** @var array<string, string> */
    private array $parameters = [];

    private string $regex;

    /**
     * @param callable|array{0: class-string|object, 1: string}|Closure $handler
     */
    public function __construct(
        private string $method,
        private string $pattern,
        private mixed $handler
    ) {
        $this->method = strtoupper($method);
        $this->pattern = '/' . trim($pattern, '/');
        $this->regex = $this->compileRegex($this->pattern);
    }

    public function middleware(MiddlewareInterface|string $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * @return array<int, MiddlewareInterface|string>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function matches(string $method, string $path): bool
    {
        if ($this->method !== strtoupper($method)) {
            return false;
        }

        $path = '/' . trim($path, '/');
        if (preg_match($this->regex, $path, $matches)) {
            $params = [];
            foreach ($matches as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            $this->parameters = $params;
            return true;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    private function compileRegex(string $pattern): string
    {
        // Replace {param} or {param:[a-z]+} with named captures
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_-]*)(?::([^}]+))?\}/', function (array $m): string {
            $paramName = $m[1];
            $pattern = $m[2] ?? '[^/]+';
            return '(?P<' . $paramName . '>' . $pattern . ')';
        }, $pattern);

        return '#^' . $regex . '$#';
    }
}
