<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Idempotency;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ConnectionFactory;
use Coleza\Foundation\Idempotency\IdempotencyManager;
use PHPUnit\Framework\TestCase;

final class IdempotencyManagerTest extends TestCase
{
    private Connection $db;
    private IdempotencyManager $idempotency;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->idempotency = new IdempotencyManager($this->db);
    }

    public function testExecutesActionAndReplaysCachedResponseOnSubsequentCalls(): void
    {
        $key = 'webhook:iyzico:evt_998877';
        $executionCount = 0;

        $action = function () use (&$executionCount): array {
            $executionCount++;
            return ['status' => 'settled', 'order_id' => 554];
        };

        // First execution
        $res1 = $this->idempotency->execute($key, $action);
        $this->assertSame(['status' => 'settled', 'order_id' => 554], $res1);
        $this->assertSame(1, $executionCount);

        // Second execution with same idempotency key (must replay cached response without running action again)
        $res2 = $this->idempotency->execute($key, $action);
        $this->assertSame(['status' => 'settled', 'order_id' => 554], $res2);
        $this->assertSame(1, $executionCount, 'Action must not be re-executed for identical idempotency key');
    }
}
