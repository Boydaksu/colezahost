<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

use RuntimeException;

/** Path-style S3/S3-compatible HTTPS, SigV4 and conditional writes. No ACL or public bucket changes. */
final class S3CurlBackupTransport implements RemoteBackupTransport
{
    private string $base;
    private string $prefix;
    private string $region;
    private string $access;
    private string $secret;

    public function __construct(private array $config)
    {
        foreach (['bucket', 'region', 'access_key', 'secret_key'] as $key) { BackupCurl::text($config, $key); }
        $this->region = $config['region']; $this->access = $config['access_key']; $this->secret = $config['secret_key'];
        if (!preg_match('/^[a-z0-9-]+$/D', $this->region) || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $config['bucket'])) { throw new RuntimeException('Invalid S3 bucket or region.'); }
        $endpoint = $config['endpoint'] ?? 'https://s3.' . $this->region . '.amazonaws.com';
        if (!is_string($endpoint) || preg_match('/[\x00-\x20\x7f]/', $endpoint)) { throw new RuntimeException('Invalid S3 HTTPS endpoint.'); }
        $url = parse_url($endpoint);
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) { throw new RuntimeException('S3 endpoint must be an HTTPS origin without credentials, query or path.'); }
        $this->base = rtrim($endpoint, '/') . '/' . $config['bucket'];
        $prefix = $config['prefix'] ?? '';
        if (!is_string($prefix)) { throw new RuntimeException('Invalid S3 prefix.'); }
        $this->prefix = trim(BackupCurl::path($prefix), '/');
        if ($this->prefix !== '') { $this->prefix .= '/'; }
    }

    public function read(string $name, string $destination): bool
    {
        $output = fopen($destination, 'xb');
        if ($output === false) { throw new RuntimeException('Cannot create private S3 download.'); }
        try { $status = $this->request('GET', $name, '', $output, BackupCurl::limit($this->config, $name)); }
        finally { fclose($output); }
        if ($status === 404) { unlink($destination); return false; }
        if ($status !== 200) { throw new RuntimeException('S3 download failed (HTTP ' . $status . ').'); }
        return true;
    }

    public function write(string $name, string $source): void
    {
        // Single PutObject supports at most 5 GiB; larger archives require a separate multipart implementation.
        if (filesize($source) > 5 * 1024 * 1024 * 1024) { throw new RuntimeException('S3 single-upload limit exceeded; source package retained.'); }
        $input = fopen($source, 'rb');
        if ($input === false) { throw new RuntimeException('Cannot open S3 upload source.'); }
        try { $status = $this->request('PUT', $name, '', null, 1048576, $input, (int) filesize($source), (string) hash_file('sha256', $source)); }
        finally { fclose($input); }
        if ($status !== 200) { throw new RuntimeException('S3 conditional upload failed (HTTP ' . $status . ').'); }
    }

    public function remove(string $name): void
    {
        $status = $this->request('DELETE', $name);
        if ($status !== 204 && $status !== 200) { throw new RuntimeException('S3 deletion failed (HTTP ' . $status . ').'); }
    }

    public function lock(string $id): void
    {
        BackupPackage::id($id);
        $status = $this->request('PUT', $id . '.lock', '', null, 1048576, bin2hex(random_bytes(16)));
        if ($status !== 200) { throw new RuntimeException('S3 backup mutation lock unavailable (HTTP ' . $status . '); reconciliation may be required.'); }
    }

    public function unlock(string $id): void { $this->remove($id . '.lock'); }

    public function names(): array
    {
        $names = []; $token = null; $seen = [];
        for ($page = 0; $page < 10000; $page++) {
            $query = ['list-type' => '2', 'prefix' => $this->prefix];
            if ($token !== null) { $query['continuation-token'] = $token; }
            ksort($query);
            $output = fopen('php://temp/maxmemory:1048576', 'w+b');
            try {
                $status = $this->request('GET', null, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $output, 8 * 1024 * 1024);
                rewind($output); $body = (string) stream_get_contents($output);
            } finally { fclose($output); }
            if ($status !== 200 || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) { throw new RuntimeException('Invalid S3 listing response.'); }
            $prior = libxml_use_internal_errors(true);
            try { $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
            if ($xml === false || $xml->getName() !== 'ListBucketResult') { throw new RuntimeException('Malformed S3 listing.'); }
            foreach ($xml->xpath('/*[local-name()="ListBucketResult"]/*[local-name()="Contents"]/*[local-name()="Key"]') as $key) {
                $key = (string) $key;
                if (!str_starts_with($key, $this->prefix)) { throw new RuntimeException('S3 returned a key outside the requested prefix.'); }
                $relative = substr($key, strlen($this->prefix));
                if (!str_contains($relative, '/')) { $names[] = $relative; }
                if (count($names) > 100000) { throw new RuntimeException('S3 listing exceeds safety limit.'); }
            }
            $truncated = $xml->xpath('/*/*[local-name()="IsTruncated"]');
            if (count($truncated) !== 1 || !in_array((string) $truncated[0], ['false', 'true'], true)) { throw new RuntimeException('Invalid S3 pagination state.'); }
            if ((string) $truncated[0] === 'false') { return array_values(array_unique($names)); }
            $next = $xml->xpath('/*/*[local-name()="NextContinuationToken"]');
            $token = count($next) === 1 ? (string) $next[0] : '';
            if ($token === '' || isset($seen[$token])) { throw new RuntimeException('S3 pagination did not advance.'); }
            $seen[$token] = true;
        }
        throw new RuntimeException('S3 pagination exceeds safety limit.');
    }

    /** @param resource|null $output @param resource|string|null $input */
    private function request(string $method, ?string $name, string $query = '', mixed $output = null, int $limit = 1048576, mixed $input = null, int $size = 0, ?string $sha256 = null): int
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { throw new RuntimeException('S3 requires cURL SigV4 support.'); }
        $handle = BackupCurl::handle($this->config, 'https');
        $url = $this->base;
        if ($name !== null) { $url .= '/' . implode('/', array_map('rawurlencode', explode('/', $this->prefix . $name))); }
        if ($query !== '') { $url .= '?' . $query; }
        // Older SigV4 signers include a suppressed "Expect:" header in SignedHeaders,
        // although libcurl never sends it. Let libcurl negotiate 100-continue itself.
        $headers = ['x-amz-content-sha256: ' . ($sha256 ?? hash('sha256', is_string($input) ? $input : ''))];
        if ($method === 'PUT') { $headers[] = 'If-None-Match: *'; $headers[] = 'Content-Type: application/octet-stream'; }
        if (isset($this->config['session_token'])) { $headers[] = 'x-amz-security-token: ' . BackupCurl::text($this->config, 'session_token'); }
        $options = [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_AWS_SIGV4 => 'aws:amz:' . $this->region . ':s3',
            CURLOPT_USERNAME => $this->access, CURLOPT_PASSWORD => $this->secret, CURLOPT_HTTPHEADER => $headers];
        if (isset($this->config['ca_bundle'])) { $options[CURLOPT_CAINFO] = BackupCurl::text($this->config, 'ca_bundle'); }
        if (is_resource($input)) { $options[CURLOPT_UPLOAD] = true; $options[CURLOPT_INFILE] = $input; $options[CURLOPT_INFILESIZE] = $size; }
        elseif (is_string($input)) { $options[CURLOPT_POSTFIELDS] = $input; }
        if (!curl_setopt_array($handle, $options)) { throw new RuntimeException('Cannot configure secure S3 request.'); }
        $discard = null;
        if ($output === null) { $discard = fopen('php://temp/maxmemory:1048576', 'w+b'); $output = $discard; }
        try { BackupCurl::sink($handle, $output, $limit); return BackupCurl::execute($handle); }
        finally { if (is_resource($discard)) { fclose($discard); } }
    }
}
