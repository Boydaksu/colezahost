<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Http;

use Coleza\Foundation\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testGettersAndNormalization(): void
    {
        $request = new Request(
            method: 'get',
            path: '/api/v1/health/',
            query: ['page' => '2'],
            post: ['token' => 'abc'],
            headers: ['content-type' => 'application/json', 'authorization' => 'Bearer 123'],
            rawBody: '{"status":"ok"}'
        );

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/health', $request->getPath());
        $this->assertSame('2', $request->getQuery('page'));
        $this->assertSame('abc', $request->getPost('token'));
        $this->assertSame('Bearer 123', $request->getHeader('Authorization'));
        $this->assertTrue($request->isJson());
        $this->assertSame(['status' => 'ok'], $request->getJson());
    }

    public function testCreateFromGlobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/login?ref=home';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
        $_GET['ref'] = 'home';
        $_POST['username'] = 'admin';

        $req = Request::createFromGlobals();

        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v1/login', $req->getPath());
        $this->assertSame('home', $req->getQuery('ref'));
        $this->assertSame('admin', $req->getPost('username'));
        $this->assertSame('localhost', $req->getHeader('host'));
        $this->assertSame('192.168.1.50', $req->getClientIp());
    }
}
