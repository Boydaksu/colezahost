<?php

declare(strict_types=1);

namespace Coleza\Api\RateLimit;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;

final class RateLimiter
{
    private string $table = 'api_rate_limits';

    public function __construct(private Connection $db)
    {
    }

    public function ensureTable(): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                rate_key VARCHAR(128) PRIMARY KEY,
                hits INT NOT NULL,
                reset_at INT NOT NULL
            )',
            $this->table
        );
        $this->db->statement($sql);
    }

    /**
     * Hit rate limiter and enforce maximum requests per time window.
     *
     * @return array{limit: int, remaining: int, reset_at: int}
     */
    public function hit(string $key, int $maxRequests = 60, int $windowSeconds = 60): array
    {
        $this->ensureTable();
        $now = time();

        $record = $this->db->selectOne(
            sprintf('SELECT hits, reset_at FROM %s WHERE rate_key = :k', $this->table),
            ['k' => $key]
        );

        if ($record === null || (int) $record['reset_at'] <= $now) {
            $resetAt = $now + $windowSeconds;
            $this->db->delete($this->table, 'rate_key = :k', ['k' => $key]);
            $this->db->insert($this->table, [
                'rate_key' => $key,
                'hits' => 1,
                'reset_at' => $resetAt,
            ]);

            return [
                'limit' => $maxRequests,
                'remaining' => $maxRequests - 1,
                'reset_at' => $resetAt,
            ];
        }

        $hits = (int) $record['hits'] + 1;
        $resetAt = (int) $record['reset_at'];

        $this->db->update(
            $this->table,
            ['hits' => $hits],
            'rate_key = :k',
            ['k' => $key]
        );

        if ($hits > $maxRequests) {
            throw new ValidationException(
                ['rate_limit' => [sprintf('Too many requests. Rate limit exceeded. Try again after %d seconds.', $resetAt - $now)]],
                'Rate limit exceeded.'
            );
        }

        return [
            'limit' => $maxRequests,
            'remaining' => max(0, $maxRequests - $hits),
            'reset_at' => $resetAt,
        ];
    }
}
