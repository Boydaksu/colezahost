<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

use RuntimeException;

final class Migrator
{
    private string $table = 'migrations';

    public function __construct(private Connection $db)
    {
    }

    public function ensureMigrationsTable(): void
    {
        $driver = $this->db->getDriverName();

        $autoIncrement = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoIncrement
        );

        $this->db->statement($sql);
    }

    /**
     * @return array<string>
     */
    public function getRanMigrations(): array
    {
        $this->ensureMigrationsTable();
        $records = $this->db->select(sprintf('SELECT migration FROM %s ORDER BY id ASC', $this->table));
        return array_column($records, 'migration');
    }

    public function getNextBatchNumber(): int
    {
        $record = $this->db->selectOne(sprintf('SELECT MAX(batch) as max_batch FROM %s', $this->table));
        return ($record && isset($record['max_batch']) && is_numeric($record['max_batch'])) ? ((int) $record['max_batch']) + 1 : 1;
    }

    /**
     * @return array<string> List of applied migration names
     */
    public function migrate(string $migrationsPath): array
    {
        $this->ensureMigrationsTable();

        $files = glob(rtrim($migrationsPath, '/\\') . '/*.php');
        if ($files === false) {
            return [];
        }

        sort($files);
        $ran = $this->getRanMigrations();
        $batch = $this->getNextBatchNumber();
        $applied = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (in_array($name, $ran, true)) {
                continue;
            }

            $migration = require $file;
            if (!$migration instanceof MigrationInterface) {
                throw new RuntimeException(sprintf('Migration in file "%s" must implement MigrationInterface.', $file));
            }

            $this->db->transaction(function (Connection $db) use ($migration, $name, $batch): void {
                $migration->up($db);
                $db->insert($this->table, [
                    'migration' => $name,
                    'batch' => $batch,
                ]);
            });

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Rollback the last migration batch.
     *
     * @return array<string> List of rolled-back migration names
     */
    public function rollback(string $migrationsPath): array
    {
        $this->ensureMigrationsTable();

        $lastBatchRecord = $this->db->selectOne(sprintf('SELECT MAX(batch) as max_batch FROM %s', $this->table));
        if (!$lastBatchRecord || $lastBatchRecord['max_batch'] === null) {
            return [];
        }

        $lastBatch = (int) $lastBatchRecord['max_batch'];
        $records = $this->db->select(
            sprintf('SELECT id, migration FROM %s WHERE batch = :batch ORDER BY id DESC', $this->table),
            ['batch' => $lastBatch]
        );

        $rolledBack = [];

        foreach ($records as $record) {
            $name = (string) $record['migration'];
            $filePath = rtrim($migrationsPath, '/\\') . DIRECTORY_SEPARATOR . $name . '.php';

            if (!file_exists($filePath)) {
                throw new RuntimeException(sprintf('Migration file "%s" not found for rollback.', $filePath));
            }

            $migration = require $filePath;
            if (!$migration instanceof MigrationInterface) {
                throw new RuntimeException(sprintf('Migration in file "%s" must implement MigrationInterface.', $filePath));
            }

            $this->db->transaction(function (Connection $db) use ($migration, $name): void {
                $migration->down($db);
                $db->delete($this->table, 'migration = :migration', ['migration' => $name]);
            });

            $rolledBack[] = $name;
        }

        return $rolledBack;
    }
}
