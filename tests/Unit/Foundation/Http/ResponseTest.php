<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Http;

use Coleza\Foundation\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJsonResponseFormatting(): void
    {
        $response = Response::json(['name' => 'Coleza Host', 'version' => '1.0'], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertJsonStringEqualsJsonString(
            '{"name":"Coleza Host","version":"1.0"}',
            $response->getContent()
        );
    }

    public function testRedirectResponse(): void
    {
        $response = Response::redirect('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function testHeadersAndStatusMutators(): void
    {
        $response = new Response('Hello World', 200);
        $response->setStatusCode(404);
        $response->setHeader('X-Custom-Header', 'custom_value');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('custom_value', $response->getHeader('X-Custom-Header'));
        $this->assertSame('custom_value', $response->getHeader('x-custom-header'));
        $this->assertSame('Hello World', $response->getContent());
    }
}
