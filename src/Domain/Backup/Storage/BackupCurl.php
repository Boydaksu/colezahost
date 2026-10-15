<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

use CurlHandle;
use RuntimeException;

/** Shared bounded streaming and configuration checks; never includes credentials or peer replies in errors. */
final class BackupCurl
{
    public static function text(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Missing or invalid backup transport configuration: ' . $key); }
        return $value;
    }

    public static function path(string $path): string
    {
        if (!preg_match('//u', $path) || preg_match('/[\\\\"\x00-\x1f\x7f]/', $path)) { throw new RuntimeException('Invalid remote backup path.'); }
        foreach (explode('/', $path) as $segment) { if ($segment === '.' || $segment === '..') { throw new RuntimeException('Remote backup path traversal is forbidden.'); } }
        return $path;
    }

    public static function handle(array $config, string $protocol): CurlHandle
    {
        if (!extension_loaded('curl') || !in_array($protocol, curl_version()['protocols'], true)) { throw new RuntimeException('Required cURL backup protocol is unavailable: ' . $protocol); }
        $timeout = $config['timeout_seconds'] ?? 120;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 600) { throw new RuntimeException('Backup transport timeout must be between 1 and 600 seconds.'); }
        $handle = curl_init();
        if ($handle === false || !curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $protocol === 'https' ? CURLPROTO_HTTPS : CURLPROTO_SFTP,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ])) { throw new RuntimeException('Cannot initialize secure backup transport.'); }
        return $handle;
    }

    public static function limit(array $config, string $name): int
    {
        if (str_ends_with($name, '.manifest.json')) { return 4 * 1024 * 1024; }
        $limit = $config['max_archive_bytes'] ?? 10 * 1024 * 1024 * 1024;
        if (!is_int($limit) || $limit < 1) { throw new RuntimeException('Invalid backup download size limit.'); }
        return $limit;
    }

    /** @param resource $output */
    public static function sink(CurlHandle $handle, mixed $output, int $limit): void
    {
        $written = 0;
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function (CurlHandle $handle, string $bytes) use ($output, $limit, &$written): int {
            if (strlen($bytes) > $limit - $written) { return 0; }
            $count = fwrite($output, $bytes);
            if ($count === false) { return 0; }
            $written += $count;
            return $count;
        });
    }

    public static function execute(CurlHandle $handle): int
    {
        if (curl_exec($handle) === false) { throw new RuntimeException('Secure backup transport failed (cURL code ' . curl_errno($handle) . ').'); }
        return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    }
}
