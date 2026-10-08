<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use RuntimeException;

final class CpanelCurlTransport implements CpanelHttpTransportInterface
{
    public function __construct(
        private bool $verifySsl = true
    ) {
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
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required to execute remote cPanel requests.');
        }

        $fullUrl = $url;
        if (!empty($queryParams)) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $fullUrl .= $sep . http_build_query($queryParams);
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($bodyParams !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bodyParams));
            }
        } elseif (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            if ($bodyParams !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bodyParams));
            }
        }

        $startTime = microtime(true);
        $responseBody = curl_exec($ch);
        $latencyMs = (int)round((microtime(true) - startTime) * 1000);

        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            $errorCode = curl_errno($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error ({$errorCode}): {$errorMsg}");
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status_code' => $statusCode,
            'body' => is_string($responseBody) ? $responseBody : '',
            'headers' => [],
            'latency_ms' => max(1, $latencyMs),
        ];
    }
}
