<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

final class CpanelMemoryTransport implements CpanelHttpTransportInterface
{
    /** @var array<string, array{status_code: int, body: string, latency_ms: int}> */
    private array $stagedResponses = [];

    /** @var array{status_code: int, body: string, latency_ms: int}|null */
    private ?array $fallbackResponse = null;

    /** @var array<int, array<string, mixed>> */
    private array $recordedRequests = [];

    /**
     * Stage a mock response for a given WHM function name or partial URL.
     *
     * @param array<string, mixed>|string $body
     */
    public function stageResponse(string $functionOrPattern, int $statusCode, array|string $body, int $latencyMs = 12): void
    {
        $bodyString = is_array($body) ? json_encode($body) : (string)$body;
        $this->stagedResponses[strtolower($functionOrPattern)] = [
            'status_code' => $statusCode,
            'body' => $bodyString !== false ? $bodyString : '{}',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    public function setFallbackResponse(int $statusCode, array|string $body, int $latencyMs = 10): void
    {
        $bodyString = is_array($body) ? json_encode($body) : (string)$body;
        $this->fallbackResponse = [
            'status_code' => $statusCode,
            'body' => $bodyString !== false ? $bodyString : '{}',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed>|null $bodyParams
     * @return array{status_code: int, body: string, headers: array<string, string>, latency_ms: int}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        array $queryParams = [],
        ?array $bodyParams = null,
        int $timeout = 30
    ): array {
        $this->recordedRequests[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'query_params' => $queryParams,
            'body_params' => $bodyParams,
            'timeout' => $timeout,
            'timestamp' => microtime(true),
        ];

        // Search staged responses
        $lowerUrl = strtolower($url);
        foreach ($this->stagedResponses as $pattern => $resp) {
            if (str_contains($lowerUrl, $pattern)) {
                return [
                    'status_code' => $resp['status_code'],
                    'body' => $resp['body'],
                    'headers' => ['content-type' => 'application/json'],
                    'latency_ms' => $resp['latency_ms'],
                ];
            }
        }

        if ($this->fallbackResponse !== null) {
            return [
                'status_code' => $this->fallbackResponse['status_code'],
                'body' => $this->fallbackResponse['body'],
                'headers' => ['content-type' => 'application/json'],
                'latency_ms' => $this->fallbackResponse['latency_ms'],
            ];
        }

        // Default empty successful WHM response
        $defaultBody = json_encode([
            'metadata' => [
                'result' => 1,
                'reason' => 'OK (Mock default)',
                'version' => 1,
            ],
            'data' => [],
        ]);

        return [
            'status_code' => 200,
            'body' => $defaultBody !== false ? $defaultBody : '{}',
            'headers' => ['content-type' => 'application/json'],
            'latency_ms' => 5,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecordedRequests(): array
    {
        return $this->recordedRequests;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastRequest(): ?array
    {
        $count = count($this->recordedRequests);
        return $count > 0 ? $this->recordedRequests[$count - 1] : null;
    }

    public function clear(): void
    {
        $this->stagedResponses = [];
        $this->recordedRequests = [];
        $this->fallbackResponse = null;
    }
}
