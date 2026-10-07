<?php

declare(strict_types=1);

namespace Coleza\Domain\Webhooks;

use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

final class WebhookService
{
    /**
     * @var array<int, WebhookEndpoint> In-memory cache when PDO is null
     */
    private array $memoryEndpoints = [];

    /**
     * @var array<int, WebhookDelivery>
     */
    private array $memoryDeliveries = [];

    public function __construct(
        private ?PDO $pdo = null
    ) {
        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    /**
     * @param string $targetUrl
     * @param array<string> $events
     * @param int|null $organizationId
     * @param int|null $userId
     * @param string|null $description
     * @return WebhookEndpoint
     */
    public function registerEndpoint(
        string $targetUrl,
        array $events = ['*'],
        ?int $organizationId = null,
        ?int $userId = null,
        ?string $description = null
    ): WebhookEndpoint {
        if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            throw new ValidationException(['target_url' => ['Invalid target URL format.']], 'Invalid URL');
        }

        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $now = date('c');

        $endpoint = new WebhookEndpoint(
            id: null,
            targetUrl: $targetUrl,
            secret: $secret,
            events: $events,
            isActive: true,
            organizationId: $organizationId,
            userId: $userId,
            description: $description,
            createdAt: $now,
            updatedAt: $now
        );

        return $this->persistEndpoint($endpoint);
    }

    /**
     * Dispatches an event payload to all active subscribed endpoints.
     *
     * @param string $eventType
     * @param array<string, mixed> $data
     * @param int|null $organizationId
     * @return array<WebhookDelivery>
     */
    public function dispatch(string $eventType, array $data, ?int $organizationId = null): array
    {
        $endpoints = $this->getActiveEndpointsForEvent($eventType, $organizationId);
        if (empty($endpoints)) {
            return [];
        }

        $eventId = 'evt_' . bin2hex(random_bytes(10));
        $eventPayload = json_encode([
            'id' => $eventId,
            'type' => $eventType,
            'created' => time(),
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        $deliveries = [];
        foreach ($endpoints as $endpoint) {
            $delivery = new WebhookDelivery(
                id: null,
                endpointId: $endpoint->getId() ?? 0,
                eventId: $eventId,
                eventType: $eventType,
                payload: $eventPayload,
                attemptNumber: 1,
                statusCode: null,
                responseBody: null,
                isSuccess: false,
                error: null,
                nextRetryAt: null,
                maxAttempts: 5
            );

            $deliveries[] = $this->persistDelivery($delivery);
        }

        return $deliveries;
    }

    /**
     * Executes the HTTP delivery attempt, signs the payload, and schedules backoff retry on failure.
     *
     * @param WebhookDelivery $delivery
     * @param callable|null $httpClient callable(string $url, string $payload, array $headers): array{code: int, body: string}
     * @return WebhookDelivery
     */
    public function deliver(WebhookDelivery $delivery, ?callable $httpClient = null): WebhookDelivery
    {
        $endpoint = $this->findEndpoint($delivery->getEndpointId());
        if ($endpoint === null) {
            throw new RuntimeException("Webhook endpoint #{$delivery->getEndpointId()} not found.");
        }

        $signatureHeader = WebhookSigner::sign($delivery->getPayload(), $endpoint->getSecret());
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Coleza-Webhook-Dispatcher/1.0',
            WebhookSigner::HEADER_NAME . ': ' . $signatureHeader,
        ];

        $now = date('c');
        $statusCode = null;
        $responseBody = null;
        $error = null;
        $isSuccess = false;

        try {
            if ($httpClient !== null) {
                $response = $httpClient($endpoint->getTargetUrl(), $delivery->getPayload(), $headers);
                $statusCode = (int) ($response['code'] ?? 0);
                $responseBody = substr((string) ($response['body'] ?? ''), 0, 1024);
                $isSuccess = $statusCode >= 200 && $statusCode < 300;
                if (!$isSuccess) {
                    $error = "HTTP response status code: {$statusCode}";
                }
            } else {
                // Native PHP stream client
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => implode("\r\n", $headers),
                        'content' => $delivery->getPayload(),
                        'timeout' => 10,
                        'ignore_errors' => true,
                    ],
                ]);

                $fp = fopen($endpoint->getTargetUrl(), 'r', false, $ctx);
                if ($fp !== false) {
                    $meta = stream_get_meta_data($fp);
                    $responseBody = substr(stream_get_contents($fp) ?: '', 0, 1024);
                    fclose($fp);

                    // Extract status code from response headers
                    $responseHeaders = $meta['wrapper_data'] ?? [];
                    if (!empty($responseHeaders) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', (string) $responseHeaders[0], $m)) {
                        $statusCode = (int) $m[1];
                        $isSuccess = $statusCode >= 200 && $statusCode < 300;
                    }
                } else {
                    $error = 'Connection failed or timed out.';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $isSuccess = false;
        }

        $nextRetryAt = null;
        if (!$isSuccess && $delivery->canRetry()) {
            $delay = $delivery->calculateBackoffSeconds();
            $nextRetryAt = date('c', time() + $delay);
        }

        $completedAt = $isSuccess || !$delivery->canRetry() ? $now : null;

        $updated = new WebhookDelivery(
            id: $delivery->getId(),
            endpointId: $delivery->getEndpointId(),
            eventId: $delivery->getEventId(),
            eventType: $delivery->getEventType(),
            payload: $delivery->getPayload(),
            attemptNumber: $delivery->getAttemptNumber(),
            statusCode: $statusCode,
            responseBody: $responseBody,
            isSuccess: $isSuccess,
            error: $error,
            nextRetryAt: $nextRetryAt,
            maxAttempts: $delivery->getMaxAttempts(),
            createdAt: $delivery->getCreatedAt(),
            completedAt: $completedAt
        );

        return $this->updateDelivery($updated);
    }

    /**
     * Manually redelivers an existing webhook delivery attempt.
     */
    public function retryDelivery(int $deliveryId, ?callable $httpClient = null): WebhookDelivery
    {
        $delivery = $this->findDelivery($deliveryId);
        if ($delivery === null) {
            throw new RuntimeException("Webhook delivery #{$deliveryId} not found.");
        }

        $nextAttempt = new WebhookDelivery(
            id: $delivery->getId(),
            endpointId: $delivery->getEndpointId(),
            eventId: $delivery->getEventId(),
            eventType: $delivery->getEventType(),
            payload: $delivery->getPayload(),
            attemptNumber: $delivery->getAttemptNumber() + 1,
            statusCode: null,
            responseBody: null,
            isSuccess: false,
            error: null,
            nextRetryAt: null,
            maxAttempts: $delivery->getMaxAttempts(),
            createdAt: $delivery->getCreatedAt(),
            completedAt: null
        );

        return $this->deliver($nextAttempt, $httpClient);
    }

    /**
     * Automated worker scanning for due retry deliveries.
     */
    public function processPendingRetries(?callable $httpClient = null, ?string $referenceTime = null): int
    {
        $ref = $referenceTime ?? date('c');
        $count = 0;

        if ($this->pdo === null) {
            foreach ($this->memoryDeliveries as $del) {
                if (!$del->isSuccess() && $del->getNextRetryAt() !== null && $del->getNextRetryAt() <= $ref) {
                    $this->retryDelivery($del->getId() ?? 0, $httpClient);
                    $count++;
                }
            }
            return $count;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM webhook_deliveries 
             WHERE is_success = 0 AND next_retry_at IS NOT NULL AND next_retry_at <= :ref'
        );
        $stmt->execute([':ref' => $ref]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $delivery = $this->mapDeliveryRow($row);
            $this->retryDelivery($delivery->getId() ?? 0, $httpClient);
            $count++;
        }

        return $count;
    }

    public function findEndpoint(int $id): ?WebhookEndpoint
    {
        if ($this->pdo === null) {
            return $this->memoryEndpoints[$id] ?? null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM webhook_endpoints WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapEndpointRow($row) : null;
    }

    public function findDelivery(int $id): ?WebhookDelivery
    {
        if ($this->pdo === null) {
            return $this->memoryDeliveries[$id] ?? null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM webhook_deliveries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapDeliveryRow($row) : null;
    }

    /**
     * @return array<WebhookEndpoint>
     */
    private function getActiveEndpointsForEvent(string $eventType, ?int $organizationId = null): array
    {
        if ($this->pdo === null) {
            return array_values(array_filter($this->memoryEndpoints, function (WebhookEndpoint $e) use ($eventType, $organizationId) {
                if (!$e->isActive()) {
                    return false;
                }
                if ($organizationId !== null && $e->getOrganizationId() !== null && $e->getOrganizationId() !== $organizationId) {
                    return false;
                }
                return $e->matchesEvent($eventType);
            }));
        }

        $sql = 'SELECT * FROM webhook_endpoints WHERE is_active = 1';
        $params = [];
        if ($organizationId !== null) {
            $sql .= ' AND (organization_id IS NULL OR organization_id = :org_id)';
            $params[':org_id'] = $organizationId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $matches = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $endpoint = $this->mapEndpointRow($row);
            if ($endpoint->matchesEvent($eventType)) {
                $matches[] = $endpoint;
            }
        }

        return $matches;
    }

    private function persistEndpoint(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        if ($this->pdo === null) {
            $id = count($this->memoryEndpoints) + 1;
            $saved = new WebhookEndpoint(
                id: $id,
                targetUrl: $endpoint->getTargetUrl(),
                secret: $endpoint->getSecret(),
                events: $endpoint->getEvents(),
                isActive: $endpoint->isActive(),
                organizationId: $endpoint->getOrganizationId(),
                userId: $endpoint->getUserId(),
                description: $endpoint->getDescription(),
                createdAt: $endpoint->getCreatedAt(),
                updatedAt: $endpoint->getUpdatedAt()
            );
            $this->memoryEndpoints[$id] = $saved;
            return $saved;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO webhook_endpoints (
                target_url, secret, events_json, is_active, organization_id, user_id, description, created_at, updated_at
            ) VALUES (
                :target_url, :secret, :events, :is_active, :org_id, :user_id, :description, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            ':target_url' => $endpoint->getTargetUrl(),
            ':secret' => $endpoint->getSecret(),
            ':events' => json_encode($endpoint->getEvents()),
            ':is_active' => $endpoint->isActive() ? 1 : 0,
            ':org_id' => $endpoint->getOrganizationId(),
            ':user_id' => $endpoint->getUserId(),
            ':description' => $endpoint->getDescription(),
            ':created_at' => $endpoint->getCreatedAt(),
            ':updated_at' => $endpoint->getUpdatedAt(),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new WebhookEndpoint(
            id: $id,
            targetUrl: $endpoint->getTargetUrl(),
            secret: $endpoint->getSecret(),
            events: $endpoint->getEvents(),
            isActive: $endpoint->isActive(),
            organizationId: $endpoint->getOrganizationId(),
            userId: $endpoint->getUserId(),
            description: $endpoint->getDescription(),
            createdAt: $endpoint->getCreatedAt(),
            updatedAt: $endpoint->getUpdatedAt()
        );
    }

    private function persistDelivery(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($this->pdo === null) {
            $id = count($this->memoryDeliveries) + 1;
            $saved = new WebhookDelivery(
                id: $id,
                endpointId: $delivery->getEndpointId(),
                eventId: $delivery->getEventId(),
                eventType: $delivery->getEventType(),
                payload: $delivery->getPayload(),
                attemptNumber: $delivery->getAttemptNumber(),
                statusCode: $delivery->getStatusCode(),
                responseBody: $delivery->getResponseBody(),
                isSuccess: $delivery->isSuccess(),
                error: $delivery->getError(),
                nextRetryAt: $delivery->getNextRetryAt(),
                maxAttempts: $delivery->getMaxAttempts(),
                createdAt: $delivery->getCreatedAt(),
                completedAt: $delivery->getCompletedAt()
            );
            $this->memoryDeliveries[$id] = $saved;
            return $saved;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO webhook_deliveries (
                endpoint_id, event_id, event_type, payload, attempt_number, status_code,
                response_body, is_success, error, next_retry_at, max_attempts, created_at, completed_at
            ) VALUES (
                :endpoint_id, :event_id, :event_type, :payload, :attempt_number, :status_code,
                :response_body, :is_success, :error, :next_retry_at, :max_attempts, :created_at, :completed_at
            )'
        );

        $stmt->execute([
            ':endpoint_id' => $delivery->getEndpointId(),
            ':event_id' => $delivery->getEventId(),
            ':event_type' => $delivery->getEventType(),
            ':payload' => $delivery->getPayload(),
            ':attempt_number' => $delivery->getAttemptNumber(),
            ':status_code' => $delivery->getStatusCode(),
            ':response_body' => $delivery->getResponseBody(),
            ':is_success' => $delivery->isSuccess() ? 1 : 0,
            ':error' => $delivery->getError(),
            ':next_retry_at' => $delivery->getNextRetryAt(),
            ':max_attempts' => $delivery->getMaxAttempts(),
            ':created_at' => $delivery->getCreatedAt(),
            ':completed_at' => $delivery->getCompletedAt(),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new WebhookDelivery(
            id: $id,
            endpointId: $delivery->getEndpointId(),
            eventId: $delivery->getEventId(),
            eventType: $delivery->getEventType(),
            payload: $delivery->getPayload(),
            attemptNumber: $delivery->getAttemptNumber(),
            statusCode: $delivery->getStatusCode(),
            responseBody: $delivery->getResponseBody(),
            isSuccess: $delivery->isSuccess(),
            error: $delivery->getError(),
            nextRetryAt: $delivery->getNextRetryAt(),
            maxAttempts: $delivery->getMaxAttempts(),
            createdAt: $delivery->getCreatedAt(),
            completedAt: $delivery->getCompletedAt()
        );
    }

    private function updateDelivery(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->getId() === null) {
            throw new RuntimeException('Cannot update delivery without ID.');
        }

        if ($this->pdo === null) {
            $this->memoryDeliveries[$delivery->getId()] = $delivery;
            return $delivery;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE webhook_deliveries SET
                attempt_number = :attempt_number,
                status_code = :status_code,
                response_body = :response_body,
                is_success = :is_success,
                error = :error,
                next_retry_at = :next_retry_at,
                completed_at = :completed_at
            WHERE id = :id'
        );

        $stmt->execute([
            ':attempt_number' => $delivery->getAttemptNumber(),
            ':status_code' => $delivery->getStatusCode(),
            ':response_body' => $delivery->getResponseBody(),
            ':is_success' => $delivery->isSuccess() ? 1 : 0,
            ':error' => $delivery->getError(),
            ':next_retry_at' => $delivery->getNextRetryAt(),
            ':completed_at' => $delivery->getCompletedAt(),
            ':id' => $delivery->getId(),
        ]);

        return $delivery;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapEndpointRow(array $row): WebhookEndpoint
    {
        return new WebhookEndpoint(
            id: (int) $row['id'],
            targetUrl: (string) $row['target_url'],
            secret: (string) $row['secret'],
            events: !empty($row['events_json']) ? json_decode((string) $row['events_json'], true) : ['*'],
            isActive: ((int) $row['is_active']) === 1,
            organizationId: $row['organization_id'] !== null ? (int) $row['organization_id'] : null,
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapDeliveryRow(array $row): WebhookDelivery
    {
        return new WebhookDelivery(
            id: (int) $row['id'],
            endpointId: (int) $row['endpoint_id'],
            eventId: (string) $row['event_id'],
            eventType: (string) $row['event_type'],
            payload: (string) $row['payload'],
            attemptNumber: (int) $row['attempt_number'],
            statusCode: $row['status_code'] !== null ? (int) $row['status_code'] : null,
            responseBody: $row['response_body'] !== null ? (string) $row['response_body'] : null,
            isSuccess: ((int) $row['is_success']) === 1,
            error: $row['error'] !== null ? (string) $row['error'] : null,
            nextRetryAt: $row['next_retry_at'] !== null ? (string) $row['next_retry_at'] : null,
            maxAttempts: (int) $row['max_attempts'],
            createdAt: (string) $row['created_at'],
            completedAt: $row['completed_at'] !== null ? (string) $row['completed_at'] : null
        );
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webhook_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                target_url VARCHAR(255) NOT NULL,
                secret VARCHAR(64) NOT NULL,
                events_json TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                organization_id INTEGER,
                user_id INTEGER,
                description TEXT,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            );
            CREATE TABLE IF NOT EXISTS webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                endpoint_id INTEGER NOT NULL,
                event_id VARCHAR(64) NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                payload TEXT NOT NULL,
                attempt_number INTEGER NOT NULL DEFAULT 1,
                status_code INTEGER,
                response_body TEXT,
                is_success INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                next_retry_at VARCHAR(64),
                max_attempts INTEGER NOT NULL DEFAULT 5,
                created_at VARCHAR(64) NOT NULL,
                completed_at VARCHAR(64)
            );'
        );
    }
}
