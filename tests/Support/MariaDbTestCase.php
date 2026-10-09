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

    protected function runParallel(string $action, int $id, string $extra = ''): array
    {
        $this->db->statement('CREATE TABLE worker_barrier (worker_id INT PRIMARY KEY, ready INT)');
        $this->db->statement('CREATE TABLE worker_control (id INT PRIMARY KEY, started INT)');
        $this->db->statement('INSERT INTO worker_control VALUES (1, 0)');
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/MariaDb/fixtures/financial-worker.php', $this->database, (string) $i, $action, (string) $id, $extra],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM worker_barrier')['n'];
                if ($ready === 4) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(4, $ready);
            $this->db->statement('UPDATE worker_control SET started = 1');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 20);
                $output = stream_get_contents($pipes[1]);
                self::assertSame('', stream_get_contents($pipes[2]));
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[1]); fclose($pipes[2]); proc_terminate($process); proc_close($process);
            }
        }
    }
}
