<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Database;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ConnectionFactory;
use Exception;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->statement('CREATE TABLE test_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100),
            email VARCHAR(100),
            is_active INTEGER DEFAULT 1
        )');
    }

    public function testInsertAndSelect(): void
    {
        $id = $this->db->insert('test_users', [
            'name' => 'Alice Boydak',
            'email' => 'alice@example.com',
            'is_active' => 1,
        ]);

        $this->assertSame(1, $id);

        $row = $this->db->selectOne('SELECT * FROM test_users WHERE id = :id', ['id' => 1]);
        $this->assertNotNull($row);
        $this->assertSame('Alice Boydak', $row['name']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    public function testUpdateAndDelete(): void
    {
        $this->db->insert('test_users', ['name' => 'John', 'email' => 'john@example.com']);

        $affected = $this->db->update(
            'test_users',
            ['name' => 'John Doe'],
            'email = :where_email',
            ['where_email' => 'john@example.com']
        );
        $this->assertSame(1, $affected);

        $updated = $this->db->selectOne('SELECT * FROM test_users WHERE email = :email', ['email' => 'john@example.com']);
        $this->assertSame('John Doe', $updated['name']);

        $deleted = $this->db->delete('test_users', 'id = :id', ['id' => 1]);
        $this->assertSame(1, $deleted);
        $this->assertNull($this->db->selectOne('SELECT * FROM test_users WHERE id = :id', ['id' => 1]));
    }

    public function testTransactionRollbackOnException(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->insert('test_users', ['name' => 'Rollback User', 'email' => 'rollback@example.com']);
                throw new Exception('Intended failure');
            });
        } catch (Exception) {
            // Expected
        }

        $row = $this->db->selectOne('SELECT * FROM test_users WHERE email = :email', ['email' => 'rollback@example.com']);
        $this->assertNull($row, 'Record should be rolled back when transaction throws exception');
    }

    public function testNestedTransactionWithSavepoints(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->insert('test_users', ['name' => 'Outer User', 'email' => 'outer@example.com']);

            // Nested inner transaction
            $db->transaction(function (Connection $innerDb): void {
                $innerDb->insert('test_users', ['name' => 'Inner User', 'email' => 'inner@example.com']);
            });
        });

        $this->assertCount(2, $this->db->select('SELECT * FROM test_users'));
    }
}
