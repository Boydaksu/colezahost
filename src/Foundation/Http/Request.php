<?php

declare(strict_types=1);

namespace Coleza\Foundation\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $server = [],
        private array $cookies = [],
        private array $files = [],
        private array $headers = [],
        private ?string $rawBody = null
    ) {
        $this->method = strtoupper($this->method);
        $this->path = '/' . trim($this->path, '/');
    }

    public static function createFromGlobals(): self
    {
        $server = $_SERVER;
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $uri = $server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = (string) $value;
            }
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $rawBody = null;
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            server: $server,
            cookies: $_COOKIE,
            files: $_FILES,
            headers: $headers,
            rawBody: $rawBody
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function getPost(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }

        return $this->post[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(): array
    {
        if ($this->rawBody === null || trim($this->rawBody) === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getClientIp(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function isJson(): bool
    {
        $contentType = $this->getHeader('content-type', '');
        return str_contains($contentType, 'application/json');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return !empty($https) && strtolower($https) !== 'off';
    }
}
