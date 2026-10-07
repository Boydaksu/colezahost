<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Lock;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ConnectionFactory;
use Coleza\Foundation\Exceptions\ConflictException;
use Coleza\Foundation\Lock\DatabaseLock;
use PHPUnit\Framework\TestCase;

final class DatabaseLockTest extends TestCase
{
    private Connection $db;
    private DatabaseLock $lock;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->lock = new DatabaseLock($this->db);
    }

    public function testAcquireAndReleaseLock(): void
    {
        $resource = 'order:invoice_1001';

        $this->assertTrue($this->lock->acquire($resource, 60, 'owner_a'));
        $this->assertTrue($this->lock->isLocked($resource));

        // Second acquire from different owner must fail
        $this->assertFalse($this->lock->acquire($resource, 60, 'owner_b'));

        // Release from owner_a
        $this->assertTrue($this->lock->release($resource, 'owner_a'));
        $this->assertFalse($this->lock->isLocked($resource));
    }

    public function testSynchronizedExecution(): void
    {
        $resource = 'provision:cpanel_srv1';
        $executed = false;

        $result = $this->lock->synchronized($resource, function () use (&$executed): string {
            $executed = true;
            return 'provision_success';
        });

        $this->assertSame('provision_success', $result);
        $this->assertTrue($executed);
        $this->assertFalse($this->lock->isLocked($resource));
    }

    public function testSynchronizedThrowsConflictWhenLocked(): void
    {
        $resource = 'invoice:renew_99';
        $this->lock->acquire($resource, 60, 'existing_worker');

        $this->expectException(ConflictException::class);
        $this->lock->synchronized($resource, function (): void {
            // Should not run
        });
    }
}
