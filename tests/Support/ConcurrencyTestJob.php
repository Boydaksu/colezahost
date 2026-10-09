<?php

declare(strict_types=1);

namespace Coleza\Tests\Support;

final class ConcurrencyTestJob implements \Coleza\Foundation\Queue\JobInterface
{
    public function handle(): void {}
    public function queue(): string { return 'default'; }
    public function maxAttempts(): int { return 3; }
    public function backoffSeconds(): int { return 1; }
}
