<?php

declare(strict_types=1);

namespace Coleza\Foundation\Http;

use Closure;
use Coleza\Foundation\Container\Container;

final class Router
{
    /** @var array<int, Route> */
    private array $routes = [];

    /** @var array<int, array{prefix: string, middlewares: array<int, MiddlewareInterface|string>}> */
    private array $groupStack = [];

    public function get(string $pattern, mixed $handler): Route
    {
        return $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): Route
    {
        return $this->addRoute('POST', $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): Route
    {
        return $this->addRoute('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->addRoute('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * @param array{prefix?: string, middleware?: array<int, MiddlewareInterface|string>|MiddlewareInterface|string} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $prefix = $attributes['prefix'] ?? '';
        $middlewares = $attributes['middleware'] ?? [];
        if (!is_array($middlewares)) {
            $middlewares = [$middlewares];
        }

        $this->groupStack[] = [
            'prefix' => $prefix,
            'middlewares' => $middlewares,
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    private function addRoute(string $method, string $pattern, mixed $handler): Route
    {
        $fullPattern = $pattern;
        $combinedMiddlewares = [];

        foreach ($this->groupStack as $group) {
            $prefix = '/' . trim($group['prefix'], '/');
            if ($prefix !== '/') {
                $fullPattern = $prefix . '/' . ltrim($fullPattern, '/');
            }
            foreach ($group['middlewares'] as $mw) {
                $combinedMiddlewares[] = $mw;
            }
        }

        $route = new Route($method, $fullPattern, $handler);
        foreach ($combinedMiddlewares as $mw) {
            $route->middleware($mw);
        }

        $this->routes[] = $route;
        return $route;
    }

    public function dispatch(Request $request, ?Container $container = null): Response
    {
        $container = $container ?? Container::getInstance();
        $method = $request->getMethod();
        $path = $request->getPath();

        $matchedRoute = null;
        $methodNotAllowed = false;

        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                $matchedRoute = $route;
                break;
            }
            // Check if route matches path with a different HTTP method
            if ($route->matches('GET', $path) || $route->matches('POST', $path) ||
                $route->matches('PUT', $path) || $route->matches('DELETE', $path) ||
                $route->matches('PATCH', $path)) {
                $methodNotAllowed = true;
            }
        }

        if ($matchedRoute === null) {
            if ($methodNotAllowed) {
                return Response::json(['error' => 'Method Not Allowed', 'code' => 'METHOD_NOT_ALLOWED'], 405);
            }
            return Response::json(['error' => 'Not Found', 'code' => 'NOT_FOUND'], 404);
        }

        // Build pipeline with route middlewares
        $pipeline = new Pipeline();
        foreach ($matchedRoute->getMiddlewares() as $middleware) {
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            }
            $pipeline->pipe($middleware);
        }

        $params = $matchedRoute->getParameters();
        $handler = $matchedRoute->getHandler();

        return $pipeline->run($request, function (Request $req) use ($handler, $params, $container): Response {
            // If handler is Closure or invokable
            if ($handler instanceof Closure) {
                $result = $handler($req, $params);
            } elseif (is_array($handler) && count($handler) === 2) {
                [$controllerClass, $action] = $handler;
                $controller = is_object($controllerClass) ? $controllerClass : $container->get($controllerClass);
                $result = $controller->$action($req, $params);
            } else {
                $result = call_user_func($handler, $req, $params);
            }

            if ($result instanceof Response) {
                return $result;
            }

            if (is_array($result) || is_object($result)) {
                return Response::json($result);
            }

            return new Response((string) $result);
        });
    }

    /**
     * @return array<int, Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
