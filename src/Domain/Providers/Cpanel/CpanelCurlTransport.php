<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use CurlHandle;
use RuntimeException;

/** One bounded request, with no redirects or automatic replay of remote mutations. */
final class CpanelCurlTransport implements CpanelHttpTransportInterface
{
    private const RESPONSE_LIMIT = 2097152;

    public function __construct(bool $verifySsl = true, private ?string $caBundle = null)
    {
        if (!$verifySsl) {
            throw new RuntimeException('cPanel TLS certificate verification cannot be disabled.');
        }
        if ($caBundle !== null && (!is_file($caBundle) || !is_readable($caBundle))) {
            throw new RuntimeException('cPanel CA bundle must be a readable file.');
        }
    }

    public function request(string $method, string $url, array $headers = [], array $queryParams = [], ?array $bodyParams = null, int $timeout = 30): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required to execute remote cPanel requests.');
        }
        $parts = parse_url($url);
        $method = strtoupper($method);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !in_array($method, ['GET', 'POST'], true) || $timeout < 1 || $timeout > 120
            || ($method === 'GET' && $bodyParams !== null)) {
            throw new RuntimeException('Invalid HTTPS cPanel request or timeout.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D', $name) || !is_string($value)
                || preg_match('/[\x00-\x1f\x7f]/', $value)
                || in_array(strtolower($name), ['content-length', 'transfer-encoding'], true)) {
                throw new RuntimeException('Invalid cPanel request header.');
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $query = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $fullUrl = $url . ($query !== '' ? (str_contains($url, '?') ? '&' : '?') . $query : '');
        $body = $bodyParams === null ? '' : http_build_query($bodyParams, '', '&', PHP_QUERY_RFC3986);
        if (strlen($fullUrl) + strlen($body) + strlen(implode('', $headerLines)) > 1048576) {
            throw new RuntimeException('cPanel request size budget exceeded.');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Failed to initialize cPanel cURL handle.');
        }
        $response = '';
        $responseHeaders = [];
        $headerBytes = 0;
        $limitExceeded = false;
        try {
            $options = [
                CURLOPT_URL => $fullUrl,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $bytes) use (&$response, &$limitExceeded): int {
                    if (strlen($response) + strlen($bytes) > self::RESPONSE_LIMIT) {
                        $limitExceeded = true;
                        return 0;
                    }
                    $response .= $bytes;
                    return strlen($bytes);
                },
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$responseHeaders, &$headerBytes, &$limitExceeded): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 65536) {
                        $limitExceeded = true;
                        return 0;
                    }
                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
            ];
            if ($this->caBundle !== null) {
                $options[CURLOPT_CAINFO] = $this->caBundle;
            }
            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Cannot configure secure cPanel request.');
            }
            $started = hrtime(true);
            $ok = curl_exec($handle);
            $latency = max(1, (int) round((hrtime(true) - $started) / 1000000));
            if ($limitExceeded) {
                throw new RuntimeException('cPanel response size budget exceeded.');
            }
            if ($ok === false || curl_errno($handle) !== 0) {
                // Do not include request credentials or response bodies in transport exceptions.
                throw new RuntimeException('cPanel cURL request failed (' . curl_errno($handle) . ').');
            }
            return ['status_code' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $response,
                'headers' => $responseHeaders, 'latency_ms' => $latency];
        } finally {
            curl_close($handle);
        }
    }
}
