<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Privacy;
final class PrivacyBindingTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\PrivacyBindingCases;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void { $this->db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:')); }
}
