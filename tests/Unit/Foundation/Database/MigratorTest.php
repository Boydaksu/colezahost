<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Database;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ConnectionFactory;
use Coleza\Foundation\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private string $tempMigrationsDir;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->migrator = new Migrator($this->db);

        $this->tempMigrationsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_mig_' . uniqid();
        mkdir($this->tempMigrationsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $files = glob($this->tempMigrationsDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempMigrationsDir);
        parent::tearDown();
    }

    public function testMigrateAndRollbackWorkflow(): void
    {
        // 1. Create a dummy migration file
        $migCode = <<<'PHP'
<?php
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(Connection $db): void {
        $db->statement('CREATE TABLE dummy_items (id INTEGER PRIMARY KEY, title VARCHAR(50))');
    }
    public function down(Connection $db): void {
        $db->statement('DROP TABLE dummy_items');
    }
};
PHP;

        file_put_contents($this->tempMigrationsDir . '/2026_10_07_000001_create_dummy_items_table.php', $migCode);

        // 2. Run migrate
        $applied = $this->migrator->migrate($this->tempMigrationsDir);
        $this->assertSame(['2026_10_07_000001_create_dummy_items_table'], $applied);

        // Verify table was created
        $this->db->insert('dummy_items', ['title' => 'Test Item']);
        $item = $this->db->selectOne('SELECT * FROM dummy_items WHERE id = 1');
        $this->assertSame('Test Item', $item['title']);

        // Running migrate again should apply 0 files (idempotency)
        $reapplied = $this->migrator->migrate($this->tempMigrationsDir);
        $this->assertEmpty($reapplied);

        // 3. Rollback
        $rolledBack = $this->migrator->rollback($this->tempMigrationsDir);
        $this->assertSame(['2026_10_07_000001_create_dummy_items_table'], $rolledBack);

        // Verify migrations table is now empty
        $this->assertEmpty($this->migrator->getRanMigrations());
    }
}
