<?php

declare(strict_types=1);

namespace Coleza\Api\Auth;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class ApiKeyService
{
    private string $table = 'api_keys';

    public function __construct(private Connection $db)
    {
    }

    public function ensureTable(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                key_prefix VARCHAR(16) NOT NULL,
                key_hash VARCHAR(128) NOT NULL UNIQUE,
                scopes LONGTEXT NOT NULL,
                is_active INT NOT NULL DEFAULT 1,
                last_used_at TIMESTAMP NULL,
                expires_at INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Generate a new API Key with assigned scopes.
     *
     * @param array<int, string> $scopes
     * @return array{id: int, plain_key: string, key_prefix: string}
     */
    public function createKey(int $userId, string $name, array $scopes = ['*'], ?int $expiresAt = null): array
    {
        $this->ensureTable();

        $prefix = 'col_' . bin2hex(random_bytes(4));
        $secret = bin2hex(random_bytes(24));
        $plainKey = $prefix . '_' . $secret;
        $hash = hash('sha256', $plainKey);

        $id = $this->db->insert($this->table, [
            'user_id' => $userId,
            'name' => trim($name),
            'key_prefix' => $prefix,
            'key_hash' => $hash,
            'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => (int) $id,
            'plain_key' => $plainKey,
            'key_prefix' => $prefix,
        ];
    }

    /**
     * Authenticate plain API key and verify scope permission.
     *
     * @return array{user_id: int, key_id: int, name: string, scopes: array<int, string>}
     */
    public function authenticate(string $plainKey, ?string $requiredScope = null): array
    {
        $this->ensureTable();
        $hash = hash('sha256', trim($plainKey));

        $record = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE key_hash = :hash AND is_active = 1', $this->table),
            ['hash' => $hash]
        );

        if ($record === null) {
            throw new ValidationException(['auth' => ['Invalid API key.']], 'Unauthenticated API request.');
        }

        if ($record['expires_at'] !== null && (int) $record['expires_at'] < time()) {
            throw new ValidationException(['auth' => ['API key has expired.']], 'Expired API key.');
        }

        $scopes = json_decode((string) $record['scopes'], true) ?: [];

        // Verify scope
        if ($requiredScope !== null && !in_array('*', $scopes, true) && !in_array($requiredScope, $scopes, true)) {
            throw new ValidationException(
                ['scope' => [sprintf('API key lacks required scope [%s].', $requiredScope)]],
                'Unauthorized API scope.'
            );
        }

        // Update last used timestamp
        $this->db->update($this->table, ['last_used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $record['id']]);

        return [
            'user_id' => (int) $record['user_id'],
            'key_id' => (int) $record['id'],
            'name' => (string) $record['name'],
            'scopes' => $scopes,
        ];
    }
}
