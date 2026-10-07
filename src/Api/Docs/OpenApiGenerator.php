<?php

declare(strict_types=1);

namespace Coleza\Api\Docs;

final class OpenApiGenerator
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $paths = [];

    public function __construct(
        private string $title = 'Coleza Host REST API',
        private string $version = '1.0.0',
        private string $baseUrl = 'https://api.colezahost.com/api/v1'
    ) {
    }

    /**
     * Register an endpoint in OpenAPI schema.
     *
     * @param array<int, string> $scopes
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $responses
     */
    public function addEndpoint(
        string $path,
        string $method,
        string $summary,
        array $scopes = [],
        array $parameters = [],
        array $responses = []
    ): self {
        $methodLower = strtolower($method);

        $endpointDef = [
            'summary' => $summary,
            'security' => !empty($scopes) ? [['ApiKeyAuth' => $scopes]] : [],
            'parameters' => $parameters,
            'responses' => !empty($responses) ? $responses : [
                '200' => ['description' => 'Successful operation'],
                '401' => ['description' => 'Unauthorized or missing API Key'],
                '422' => ['description' => 'Validation error'],
            ],
        ];

        $this->paths[$path][$methodLower] = $endpointDef;
        return $this;
    }

    /**
     * Generate OpenAPI 3.1.0 compliant JSON schema.
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
                'description' => 'Official Coleza Host REST API documentation.',
            ],
            'servers' => [
                ['url' => $this->baseUrl, 'description' => 'Production API'],
            ],
            'paths' => $this->paths,
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-KEY',
                        'description' => 'Coleza API Key token (prefix col_...)',
                    ],
                ],
            ],
        ];
    }
}
