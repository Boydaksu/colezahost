<?php

declare(strict_types=1);

namespace Coleza\Tests\Support;

use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class MariaDbTestCase extends TestCase
{
    protected PDO $root;
    protected Connection $db;
    protected string $database;

    protected function setUp(): void
    {
        $dsn = getenv('COLEZA_TEST_MARIADB_DSN');
        if (!$dsn || !str_starts_with($dsn, 'mysql:')) {
            throw new \RuntimeException('An isolated MariaDB DSN is required; this suite never substitutes SQLite.');
        }
        $this->root = new PDO($dsn, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $this->root->query('SELECT VERSION()')->fetchColumn();
        self::assertStringContainsString('10.11.', $version);
        self::assertStringContainsString('MariaDB', $version);
        $this->database = 'colezahost_d02_test_' . bin2hex(random_bytes(8));
        $this->root->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->root->exec('USE `' . $this->database . '`');
        $this->db = new Connection($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root, $this->database) && preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D', $this->database)) {
            $this->root->exec('DROP DATABASE `' . $this->database . '`');
        }
    }
}
