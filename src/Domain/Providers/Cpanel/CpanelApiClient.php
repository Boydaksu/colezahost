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
        $version = $this->verifiedVersion($response);

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

        foreach (['one', 'five', 'fifteen'] as $key) {
            $value = $data[$key] ?? null;
            if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)
                || !is_finite((float)$value) || (float)$value < 0) {
                throw new ProviderException('Incomplete or invalid WHM load evidence.', 'INVALID_WHM_RESPONSE');
            }
        }
        $one = (float)$data['one'];
        $five = (float)$data['five'];
        $fifteen = (float)$data['fifteen'];

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
        return $this->verifiedVersion($res);
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

    /** Read-only, strict evidence for reconciliation. API errors are not authoritative absence. */
    public function getVerifiedAccountSummary(string $username): ?array
    {
        $response = $this->call('accountsummary', ['user' => $username], 'GET', throwOnError: false);
        if (($response['_meta']['status_code'] ?? null) !== 200) { throw new RuntimeException('Account probe HTTP response is not authoritative.'); }
        if (!in_array($response['metadata']['result'] ?? null, [1, '1'], true)) { return null; }
        $accounts = $response['data']['acct'] ?? null;
        if (!is_array($accounts) || !array_is_list($accounts) || count($accounts) !== 1 || !is_array($accounts[0])) {
            throw new RuntimeException('Account probe returned missing or ambiguous account data.');
        }
        $summary = $accounts[0];
        foreach (['user', 'domain', 'plan', 'ip'] as $key) {
            if (!is_string($summary[$key] ?? null) || trim($summary[$key]) === '') { throw new RuntimeException('Incomplete account identity evidence.'); }
        }
        if (strcasecmp($summary['user'], $username) !== 0 || !in_array($summary['suspended'] ?? null, [0, 1, '0', '1'], true)) {
            throw new RuntimeException('Account identity or state evidence is inconsistent.');
        }
        return array_intersect_key($summary, array_flip(['user', 'domain', 'plan', 'ip', 'suspended']));
    }

    /** Change hosting package via WHM changepackage. @return array<string, mixed> */
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
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $function) || !in_array(strtoupper($method), ['GET', 'POST'], true)) {
            throw new ProviderException('Invalid WHM function or method.', 'INVALID_WHM_REQUEST');
        }
        $url = $this->buildUrl($function);
        $headers = $this->buildHeaders();

        // WHM API 1 requires api.version=1
        $queryParams = ['api.version' => 1];
        $bodyParams = null;

        if (strtoupper($method) === 'GET') {
            $queryParams = array_replace($params, $queryParams);
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
                ]
            );
        }

        if ($statusCode !== 200) {
            throw new ProviderException('Unexpected cPanel/WHM HTTP status (' . $statusCode . ').', 'WHM_HTTP_ERROR', ['status_code' => $statusCode]);
        }
        $decoded = json_decode($body, true, 64);
        if (!is_array($decoded)) {
            throw new ProviderException(
                message: "Invalid non-JSON response received from cPanel/WHM (HTTP {$statusCode}).",
                errorCode: 'INVALID_JSON_RESPONSE',
                context: [
                    'hostname' => $this->config->getHostname(),
                    'function' => $function,
                ]
            );
        }

        $standard = isset($decoded['metadata']) && is_array($decoded['metadata'])
            && in_array($decoded['metadata']['result'] ?? null, [0, 1, '0', '1'], true);
        $legacy = !array_key_exists('metadata', $decoded) && is_array($decoded['result'][0] ?? null)
            && in_array($decoded['result'][0]['status'] ?? null, [0, 1, '0', '1'], true);
        if (($standard && isset($decoded['metadata']['reason']) && !is_string($decoded['metadata']['reason']))
            || ($legacy && (count($decoded['result']) !== 1 || (isset($decoded['result'][0]['statusmsg']) && !is_string($decoded['result'][0]['statusmsg']))))) {
            throw new ProviderException('Invalid WHM result detail.', 'INVALID_WHM_RESPONSE');
        }
        if (!$standard && !$legacy) {
            throw new ProviderException('Missing or invalid WHM result evidence.', 'INVALID_WHM_RESPONSE');
        }

        // WHM API 1 standard response checking
        if ($standard) {
            $result = (int)$decoded['metadata']['result'];
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
        if ($legacy && (int)$decoded['result'][0]['status'] === 0) {
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

    private function verifiedVersion(array $response): string
    {
        $version = $response['data']['version'] ?? null;
        if (!is_string($version) || !preg_match('/^[0-9]+(?:\.[0-9]+){1,4}(?:[-+][A-Za-z0-9._-]+)?$/D', $version)) {
            throw new ProviderException('Missing or invalid WHM version evidence.', 'INVALID_WHM_RESPONSE');
        }
        return $version;
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
