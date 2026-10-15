<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Security;
final class SecretAcceptanceTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\SecretAcceptanceCases;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void { $this->db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:')); }
}
