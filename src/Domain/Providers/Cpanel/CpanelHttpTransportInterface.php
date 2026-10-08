<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

interface CpanelHttpTransportInterface
{
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
    ): array;
}
