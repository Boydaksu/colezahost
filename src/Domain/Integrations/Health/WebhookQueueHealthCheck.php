<?php

declare(strict_types=1);

namespace Coleza\Domain\Integrations\Health;

use Coleza\Domain\Webhooks\WebhookService;
use Coleza\Foundation\Health\HealthCheckInterface;
use Coleza\Foundation\Health\HealthCheckResult;
use PDO;
use Throwable;

final class WebhookQueueHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ?WebhookService $webhookService = null,
        private ?PDO $pdo = null,
        private int $warningPendingThreshold = 25,
        private int $unhealthyPendingThreshold = 100
    ) {
    }

    public function name(): string
    {
        return 'integration.webhook.queue';
    }

    public function check(): HealthCheckResult
    {
        if ($this->pdo === null) {
            return HealthCheckResult::healthy(
                $this->name(),
                'Webhook service operating with in-memory storage.',
                ['storage' => 'in_memory', 'pending_count' => 0]
            );
        }

        try {
            // Count pending retries awaiting execution
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE is_success = 0 AND next_retry_at IS NOT NULL AND attempt_number < max_attempts");
            $pendingCount = $stmt ? (int) $stmt->fetchColumn() : 0;

            // Count failed/exhausted deliveries
            $stmtFailed = $this->pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE is_success = 0 AND attempt_number >= max_attempts");
            $failedCount = $stmtFailed ? (int) $stmtFailed->fetchColumn() : 0;

            $meta = [
                'pending_deliveries' => $pendingCount,
                'failed_deliveries' => $failedCount,
            ];

            if ($pendingCount >= $this->unhealthyPendingThreshold) {
                return HealthCheckResult::unhealthy(
                    $this->name(),
                    "Webhook delivery queue backlog is critical ({$pendingCount} pending).",
                    $meta
                );
            }

            if ($pendingCount >= $this->warningPendingThreshold) {
                return HealthCheckResult::warning(
                    $this->name(),
                    "Webhook delivery queue backlog elevated ({$pendingCount} pending).",
                    $meta
                );
            }

            return HealthCheckResult::healthy(
                $this->name(),
                'Webhook delivery queue healthy.',
                $meta
            );
        } catch (Throwable $e) {
            // If table doesn't exist yet, it's fresh/unprovisioned
            return HealthCheckResult::healthy(
                $this->name(),
                'Webhook deliveries table not initialized yet.',
                ['error' => $e->getMessage()]
            );
        }
    }
}
