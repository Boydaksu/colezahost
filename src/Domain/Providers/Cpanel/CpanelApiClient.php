<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use Coleza\Domain\Providers\Exceptions\ProviderException;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use RuntimeException;

final class CpanelApiClient
{
    private CpanelHttpTransportInterface $transport;

    public function __construct(
        private CpanelConfiguration $config,
        ?CpanelHttpTransportInterface $transport = null
    ) {
        $this->transport = $transport ?? new CpanelCurlTransport();
    }

    public function getConfiguration(): CpanelConfiguration
    {
        return $this->config;
    }

    public function getTransport(): CpanelHttpTransportInterface
    {
        return $this->transport;
    }

    /**
     * Test server connectivity and authentication by retrieving the server version.
     *
     * @return array{authenticated: bool, version: string, latency_ms: int}
     * @throws ProviderException
     */
    public function testAuthentication(): array
    {
        $response = $this->call('version');
        $version = (string)($response['data']['version'] ?? 'unknown');

        return [
            'authenticated' => true,
            'version' => $version,
            'latency_ms' => (int)($response['_meta']['latency_ms'] ?? 0),
        ];
    }

    /**
     * Get system 1m, 5m, 15m load averages from WHM.
     *
     * @return array{1m: float, 5m: float, 15m: float, raw: array<string, mixed>}
     * @throws ProviderException
     */
    public function getSystemLoad(): array
    {
        $response = $this->call('systemloadavg');
        $data = (array)($response['data'] ?? []);

        $one = isset($data['one']) ? (float)$data['one'] : 0.0;
        $five = isset($data['five']) ? (float)$data['five'] : 0.0;
        $fifteen = isset($data['fifteen']) ? (float)$data['fifteen'] : 0.0;

        return [
            '1m' => $one,
            '5m' => $five,
            '15m' => $fifteen,
            'raw' => $data,
        ];
    }

    /**
     * Retrieve WHM version string.
     */
    public function getVersion(): string
    {
        $res = $this->call('version');
        return (string)($res['data']['version'] ?? 'unknown');
    }

    /**
     * Retrieve all hosting packages configured on WHM server.
     *
     * @return array<int, array<string, mixed>>
     * @throws ProviderException
     */
    public function listPackagesRaw(): array
    {
        $response = $this->call('listpkgs');
        $data = $response['data'] ?? [];

        if (isset($data['pkg']) && is_array($data['pkg'])) {
            return $data['pkg'];
        }
        if (isset($data['package']) && is_array($data['package'])) {
            return $data['package'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Create a remote cPanel account via WHM createacct.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function createAccount(array $params): array
    {
        return $this->call('createacct', $params, 'POST', throwOnError: false);
    }

    /**
     * Suspend a remote cPanel account via WHM suspendacct.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function suspendAccount(string $username, string $reason = 'Overdue payment'): array
    {
        return $this->call('suspendacct', ['user' => $username, 'reason' => $reason], 'POST', throwOnError: false);
    }

    /**
     * Unsuspend a remote cPanel account via WHM unsuspendacct.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function unsuspendAccount(string $username): array
    {
        return $this->call('unsuspendacct', ['user' => $username], 'POST', throwOnError: false);
    }

    /**
     * Terminate and remove a remote cPanel account via WHM removeacct.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function terminateAccount(string $username, bool $keepDns = false): array
    {
        return $this->call('removeacct', ['user' => $username, 'keepdns' => $keepDns ? 1 : 0], 'POST', throwOnError: false);
    }

    /**
     * Retrieve remote cPanel account summary.
     *
     * @return array<string, mixed>|null
     * @throws ProviderException
     */
    public function getAccountSummary(string $username): ?array
    {
        $response = $this->call('accountsummary', ['user' => $username], 'GET', throwOnError: false);
        $result = (int)($response['metadata']['result'] ?? ($response['result'][0]['status'] ?? 0));
        if ($result === 0) {
            return null;
        }
        $data = $response['data']['acct'] ?? ($response['data'] ?? []);
        return is_array($data) ? (isset($data[0]) ? $data[0] : $data) : null;
    }

    /**
     * Change / upgrade / downgrade hosting package for a cPanel user via WHM changepackage.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function changePackage(string $username, string $newPackage): array
    {
        return $this->call('changepackage', ['user' => $username, 'pkg' => $newPackage], 'POST', throwOnError: false);
    }

    /**
     * Modify disk quota for a cPanel user via WHM editquota.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function editQuota(string $username, int $quotaMb): array
    {
        return $this->call('editquota', ['user' => $username, 'quota' => $quotaMb], 'POST', throwOnError: false);
    }

    /**
     * Modify monthly bandwidth limit for a cPanel user via WHM limitbw.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function limitBandwidth(string $username, int $bwlimitMb): array
    {
        return $this->call('limitbw', ['user' => $username, 'bwlimit' => $bwlimitMb], 'POST', throwOnError: false);
    }

    /**
     * Execute a WHM JSON-API 1 call.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function call(string $function, array $params = [], string $method = 'GET', bool $throwOnError = true): array
    {
        $url = $this->buildUrl($function);
        $headers = $this->buildHeaders();

        // WHM API 1 requires api.version=1
        $queryParams = ['api.version' => 1];
        $bodyParams = null;

        if (strtoupper($method) === 'GET') {
            $queryParams = array_merge($queryParams, $params);
        } else {
            $bodyParams = $params;
        }

        try {
            $httpResult = $this->transport->request(
                method: $method,
                url: $url,
                headers: $headers,
                queryParams: $queryParams,
                bodyParams: $bodyParams,
                timeout: $this->config->getTimeoutSeconds()
            );
        } catch (\Throwable $e) {
            $classification = ProvisioningErrorClassifier::classify(
                message: "WHM connection failed: {$e->getMessage()}",
                errorCode: 'WHM_CONNECTION_FAILED'
            );

            throw new ProviderException(
                message: "cPanel/WHM network failure for server '{$this->config->getHostname()}': {$e->getMessage()}",
                errorCode: 'PROVIDER_CONNECTION_FAILED',
                context: [
                    'hostname' => $this->config->getHostname(),
                    'function' => $function,
                    'category' => $classification->getCategory(),
                    'is_transient' => $classification->isRetryable(),
                ],
                previous: $e
            );
        }

        $statusCode = $httpResult['status_code'];
        $body = $httpResult['body'];

        if ($statusCode === 401 || $statusCode === 403) {
            $classification = ProvisioningErrorClassifier::classify(
                message: 'Invalid WHM credentials or unauthorized API access.',
                errorCode: 'AUTH_FAILED',
                httpStatusCode: $statusCode
            );

            throw new ProviderException(
                message: "cPanel/WHM authentication rejected (HTTP {$statusCode}) for user '{$this->config->getUsername()}'.",
                errorCode: 'AUTHENTICATION_FAILED',
                context: [
                    'hostname' => $this->config->getHostname(),
                    'username' => $this->config->getUsername(),
                    'auth_type' => $this->config->getAuthType(),
                    'category' => $classification->getCategory(),
                ]
            );
        }

        if ($statusCode >= 500) {
            throw new ProviderException(
                message: "cPanel/WHM server internal error (HTTP {$statusCode}).",
                errorCode: 'WHM_SERVER_ERROR',
                context: [
                    'hostname' => $this->config->getHostname(),
                    'status_code' => $statusCode,
                    'body_preview' => substr($body, 0, 200),
                ]
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ProviderException(
                message: "Invalid non-JSON response received from cPanel/WHM (HTTP {$statusCode}).",
                errorCode: 'INVALID_JSON_RESPONSE',
                context: [
                    'hostname' => $this->config->getHostname(),
                    'function' => $function,
                    'body_preview' => substr($body, 0, 200),
                ]
            );
        }

        // WHM API 1 standard response checking
        if (isset($decoded['metadata'])) {
            $result = (int)($decoded['metadata']['result'] ?? 1);
            if ($result === 0) {
                $reason = (string)($decoded['metadata']['reason'] ?? 'Unknown WHM error');
                $classification = ProvisioningErrorClassifier::classify(
                    message: $reason,
                    errorCode: 'WHM_FUNCTION_FAILED',
                    rawResponse: $decoded
                );

                if ($throwOnError) {
                    throw new ProviderException(
                        message: "cPanel/WHM API error ({$function}): {$reason}",
                        errorCode: 'WHM_API_ERROR',
                        context: [
                            'hostname' => $this->config->getHostname(),
                            'function' => $function,
                            'reason' => $reason,
                            'category' => $classification->getCategory(),
                            'is_transient' => $classification->isRetryable(),
                            'admin_advice' => $classification->getAdminActionableMessage(),
                        ]
                    );
                }
            }
        }

        // Legacy WHM response format fallback: [{ status: 0, statusmsg: "..." }]
        if (isset($decoded['result'][0]['status']) && (int)$decoded['result'][0]['status'] === 0) {
            $reason = (string)($decoded['result'][0]['statusmsg'] ?? 'Unknown legacy WHM error');
            $classification = ProvisioningErrorClassifier::classify($reason, 'WHM_LEGACY_FAILED', null, $decoded);

            if ($throwOnError) {
                throw new ProviderException(
                    message: "cPanel/WHM error ({$function}): {$reason}",
                    errorCode: 'WHM_API_ERROR',
                    context: [
                        'hostname' => $this->config->getHostname(),
                        'function' => $function,
                        'reason' => $reason,
                        'category' => $classification->getCategory(),
                    ]
                );
            }
        }

        $decoded['_meta'] = [
            'status_code' => $statusCode,
            'latency_ms' => $httpResult['latency_ms'],
        ];

        return $decoded;
    }

    private function buildUrl(string $function): string
    {
        $cleanFunc = ltrim($function, '/');
        return "{$this->config->getBaseUrl()}/json-api/{$cleanFunc}";
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'ColezaHost-CpanelClient/1.0',
        ];

        if ($this->config->getApiToken() !== null) {
            $headers['Authorization'] = sprintf(
                'whm %s:%s',
                $this->config->getUsername(),
                trim($this->config->getApiToken())
            );
        } elseif ($this->config->getAccessHash() !== null) {
            $cleanHash = preg_replace('/\s+/', '', (string)$this->config->getAccessHash());
            $headers['Authorization'] = sprintf(
                'WHM %s:%s',
                $this->config->getUsername(),
                $cleanHash
            );
        } elseif ($this->config->getPassword() !== null) {
            $credentials = base64_encode("{$this->config->getUsername()}:{$this->config->getPassword()}");
            $headers['Authorization'] = "Basic {$credentials}";
        }

        return $headers;
    }
}
