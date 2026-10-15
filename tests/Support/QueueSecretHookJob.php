<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
final class QueueSecretHookJob implements \Coleza\Foundation\Queue\JobInterface
{
    public static int $wakes = 0;
    public function __construct(public array $data = []) {}
    public function __wakeup(): void { self::$wakes++; }
    public function handle(): void {}
    public function queue(): string { return 'default'; }
    public function maxAttempts(): int { return 1; }
    public function backoffSeconds(): int { return 0; }
}
