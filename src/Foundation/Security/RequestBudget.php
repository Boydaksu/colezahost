<?php

declare(strict_types=1);

namespace Coleza\Foundation\Security;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\ApiSessionSchema;
use Coleza\Foundation\Exceptions\ValidationException;

class RequestBudget
{
    private string $table = 'api_rate_limits';

    public function __construct(private Connection $db)
    {
    }

    public function ensureTable(bool $installation = true): void
    {
        if (ApiSessionSchema::installed($this->db, $this->table, $installation)) { return; }
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                rate_key VARCHAR(128) PRIMARY KEY,
                hits INT NOT NULL,
                reset_at INT NOT NULL
            )',
            $this->table
        );
        $this->db->statement($sql . ($this->db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    /**
     * Hit rate limiter and enforce maximum requests per time window.
     *
     * @return array{limit: int, remaining: int, reset_at: int}
     */
    public function hit(string $key, int $maxRequests = 60, int $windowSeconds = 60): array
    {
        if ($key === '' || strlen($key) > 128 || str_contains($key, "\0") || $maxRequests < 1 || $maxRequests >= 2147483647 || $windowSeconds < 1 || $windowSeconds > 2147483647 - time()) {
            throw new \InvalidArgumentException('Invalid rate key, budget or window.');
        }
        // Admission must persist independently of the application operation it protects.
        // A caller rollback must never restore request budget.
        if ($this->db->inTransaction() || $this->db->getPdo()->inTransaction()) {
            throw new \LogicException('Perform rate admission before starting an application transaction.');
        }
        $this->ensureTable(installation: false);
        $record = $this->db->transaction(function () use ($key, $windowSeconds): array {
            $now = time(); $reset = $now + $windowSeconds;
            if ($this->db->getDriverName() === 'mysql') {
                // Update hits before reset_at: the expiry comparison must use the original window.
                $this->db->statement('INSERT INTO api_rate_limits (rate_key, hits, reset_at) VALUES (?, 1, ?)
                    ON DUPLICATE KEY UPDATE hits = IF(reset_at <= ?, 1, LEAST(hits + 1, 2147483647)),
                    reset_at = IF(reset_at <= ?, ?, reset_at)', [$key, $reset, $now, $now, $reset]);
            } else {
                $this->db->statement('INSERT INTO api_rate_limits (rate_key, hits, reset_at) VALUES (?, 1, ?)
                    ON CONFLICT(rate_key) DO UPDATE SET hits = CASE WHEN reset_at <= ? THEN 1 ELSE MIN(hits + 1, 2147483647) END,
                    reset_at = CASE WHEN reset_at <= ? THEN ? ELSE reset_at END', [$key, $reset, $now, $now, $reset]);
            }
            // The upsert holds the row write lock until this read and the commit finish.
            return $this->db->selectOne('SELECT hits, reset_at FROM api_rate_limits WHERE rate_key = ?', [$key]);
        });
        $hits = (int) $record['hits']; $resetAt = (int) $record['reset_at'];
        if ($hits > $maxRequests) {
            // Preserve the existing policy: rejected attempts are counted, and committed.
            throw new ValidationException(['rate_limit' => [sprintf('Too many requests. Rate limit exceeded. Try again after %d seconds.', max(0, $resetAt - time()))]], 'Rate limit exceeded.');
        }
        return ['limit' => $maxRequests, 'remaining' => max(0, $maxRequests - $hits), 'reset_at' => $resetAt];
    }
}
