<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
final class QueueSecretJob implements \Coleza\Foundation\Queue\JobInterface
{
    public function __construct(public array $data = [], public int $limit = 1) {}
    public function handle(): void {}
    public function queue(): string { return 'default'; }
    public function maxAttempts(): int { return $this->limit; }
    public function backoffSeconds(): int { return 0; }
}
