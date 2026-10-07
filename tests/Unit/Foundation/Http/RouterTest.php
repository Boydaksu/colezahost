<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Http;

use Coleza\Foundation\Container\Container;
use Coleza\Foundation\Http\Middleware\SecurityHeadersMiddleware;
use Coleza\Foundation\Http\Request;
use Coleza\Foundation\Http\Response;
use Coleza\Foundation\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;
    private Container $container;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->container = new Container();
        Container::setInstance($this->container);
    }

    public function testMatchesSimpleGetRoute(): void
    {
        $this->router->get('/health', function (): Response {
            return Response::json(['status' => 'healthy']);
        });

        $request = new Request('GET', '/health');
        $response = $this->router->dispatch($request, $this->container);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"healthy"}', $response->getContent());
    }

    public function testExtractsRouteParameters(): void
    {
        $this->router->get('/services/{id}', function (Request $req, array $params): Response {
            return Response::json(['service_id' => $params['id']]);
        });

        $request = new Request('GET', '/services/srv_456');
        $response = $this->router->dispatch($request, $this->container);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"service_id":"srv_456"}', $response->getContent());
    }

    public function testRouteGroupsWithPrefixAndMiddleware(): void
    {
        $this->router->group([
            'prefix' => '/api/v1',
            'middleware' => SecurityHeadersMiddleware::class,
        ], function (Router $r): void {
            $r->get('/ping', function (): string {
                return 'pong';
            });
        });

        $this->container->singleton(SecurityHeadersMiddleware::class);

        $request = new Request('GET', '/api/v1/ping');
        $response = $this->router->dispatch($request, $this->container);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', $response->getContent());
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
    }

    public function testReturns404ForUnregisteredRoute(): void
    {
        $request = new Request('GET', '/non-existent-endpoint');
        $response = $this->router->dispatch($request, $this->container);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns405ForMethodMismatch(): void
    {
        $this->router->post('/orders', function (): string {
            return 'created';
        });

        $request = new Request('GET', '/orders');
        $response = $this->router->dispatch($request, $this->container);

        $this->assertSame(405, $response->getStatusCode());
    }
}
