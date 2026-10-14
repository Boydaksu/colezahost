<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Foundation\Database;
final class D02CloseoutTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\D02CloseoutCases;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void { $this->db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:')); }
}
