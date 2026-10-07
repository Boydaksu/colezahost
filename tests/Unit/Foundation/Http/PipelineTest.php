<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Http;

use Closure;
use Coleza\Foundation\Http\MiddlewareInterface;
use Coleza\Foundation\Http\Pipeline;
use Coleza\Foundation\Http\Request;
use Coleza\Foundation\Http\Response;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testExecutesMiddlewaresInOrder(): void
    {
        $log = [];

        $mw1 = new class($log) implements MiddlewareInterface {
            private array $logRef;
            public function __construct(array &$logRef) { $this->logRef = &$logRef; }
            public function handle(Request $request, Closure $next): Response {
                $this->logRef[] = 'mw1_before';
                $resp = $next($request);
                $this->logRef[] = 'mw1_after';
                return $resp;
            }
        };

        $mw2 = new class($log) implements MiddlewareInterface {
            private array $logRef;
            public function __construct(array &$logRef) { $this->logRef = &$logRef; }
            public function handle(Request $request, Closure $next): Response {
                $this->logRef[] = 'mw2_before';
                $resp = $next($request);
                $this->logRef[] = 'mw2_after';
                return $resp;
            }
        };

        $pipeline = new Pipeline([$mw1, $mw2]);
        $request = new Request('GET', '/');

        $response = $pipeline->run($request, function (Request $req) use (&$log): Response {
            $log[] = 'destination';
            return new Response('OK');
        });

        $this->assertSame('OK', $response->getContent());
        $this->assertSame(['mw1_before', 'mw2_before', 'destination', 'mw2_after', 'mw1_after'], $log);
    }
}
