<?php
declare(strict_types=1);
namespace Coleza\Domain\Backup\Storage;

use CurlHandle;
use RuntimeException;

/** SSH host pin is mandatory, acquired through an independent trusted channel. */
final class SftpCurlBackupTransport implements RemoteBackupTransport
{
    // libcurl CURLE_REMOTE_FILE_NOT_FOUND (78) is not exposed by every PHP cURL build.
    private const int REMOTE_FILE_NOT_FOUND = 78;
    private string $root;
    private string $base;

    public function __construct(private array $config)
    {
        $host = BackupCurl::text($config, 'host'); BackupCurl::text($config, 'username');
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)) { throw new RuntimeException('Invalid SFTP host.'); }
        $port = $config['port'] ?? 22;
        if (!is_int($port) || $port < 1 || $port > 65535) { throw new RuntimeException('Invalid SFTP port.'); }
        $root = BackupCurl::path(BackupCurl::text($config, 'remote_path'));
        if (!str_starts_with($root, '/') || str_starts_with($root, '/~')) { throw new RuntimeException('SFTP backup directory must be absolute without home expansion.'); }
        $this->root = rtrim($root, '/') . '/';
        $this->base = 'sftp://' . $host . ':' . $port;
        $pin = BackupCurl::text($config, 'host_key_sha256');
        if (str_starts_with($pin, 'SHA256:')) { $pin = substr($pin, 7); }
        $decoded = base64_decode($pin, true);
        if ($decoded === false || strlen($decoded) !== 32) { throw new RuntimeException('SFTP requires a SHA256 host public key pin.'); }
        $this->config['host_key_sha256'] = base64_encode($decoded);
        if (!isset($config['password'])) { BackupCurl::text($config, 'private_key'); }
        else { BackupCurl::text($config, 'password'); }
        self::assertRuntimeSupport();
    }

    /** A PHP constant and the SFTP protocol alone do not prove that this SSH backend implements pinning. */
    public static function assertRuntimeSupport(): void
    {
        if (!extension_loaded('curl') || !in_array('sftp', curl_version()['protocols'], true)
            || !defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) {
            throw new RuntimeException('SFTP requires cURL with SHA256 SSH host-key pinning support.');
        }
        $probe = curl_init();
        if ($probe === false || !curl_setopt($probe, CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256, base64_encode(str_repeat("\0", 32)))) {
            throw new RuntimeException('SFTP requires working SHA256 SSH host-key pinning in the cURL SSH backend (for example libssh2). No insecure fallback is allowed.');
        }
    }

    public function read(string $name, string $destination): bool
    {
        $handle = $this->handle($name);
        $output = fopen($destination, 'xb');
        if ($output === false) { throw new RuntimeException('Cannot create private SFTP download.'); }
        BackupCurl::sink($handle, $output, BackupCurl::limit($this->config, $name));
        try {
            if (curl_exec($handle) === false) {
                if (curl_errno($handle) === self::REMOTE_FILE_NOT_FOUND) { fclose($output); $output = null; unlink($destination); return false; }
                throw new RuntimeException('SFTP download failed (cURL code ' . curl_errno($handle) . ').');
            }
            return true;
        } finally { if (is_resource($output)) { fclose($output); } }
    }

    public function write(string $name, string $source): void
    {
        // Store holds an exclusive per-ID directory lock. Final paths never receive partial upload bytes.
        $temporary = '.upload_' . bin2hex(random_bytes(16)) . '_' . $name;
        $input = fopen($source, 'rb');
        if ($input === false) { throw new RuntimeException('Cannot open SFTP upload source.'); }
        $handle = $this->handle($temporary);
        curl_setopt_array($handle, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $input, CURLOPT_INFILESIZE => (int) filesize($source), CURLOPT_NEW_FILE_PERMS => 0600]);
        try {
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static fn (CurlHandle $h, string $bytes): int => strlen($bytes));
            BackupCurl::execute($handle);
            $this->command('rename ' . $this->quoted($temporary) . ' ' . $this->quoted($name));
        } finally {
            fclose($input);
            // On an interrupted upload the temporary file may exist too. Failure must not become success.
            try { $this->command('rm ' . $this->quoted($temporary)); } catch (RuntimeException) { /* An orphan temporary file is never a published backup. */ }
        }
    }

    public function remove(string $name): void { $this->command('rm ' . $this->quoted($name)); }
    public function lock(string $id): void { BackupPackage::id($id); $this->command('mkdir ' . $this->quoted($id . '.lock')); }
    public function unlock(string $id): void { $this->command('rmdir ' . $this->quoted($id . '.lock')); }

    public function names(): array
    {
        $output = fopen('php://temp/maxmemory:1048576', 'w+b'); $handle = $this->handle('');
        curl_setopt($handle, CURLOPT_DIRLISTONLY, true); BackupCurl::sink($handle, $output, 8 * 1024 * 1024);
        try { BackupCurl::execute($handle); rewind($output); $body = (string) stream_get_contents($output); }
        finally { fclose($output); }
        return array_values(array_filter(preg_split('/\r?\n/', $body), static fn (string $name): bool => $name !== '' && $name !== '.' && $name !== '..'));
    }

    private function handle(string $name): CurlHandle
    {
        if (!defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) { throw new RuntimeException('SFTP requires cURL SHA256 host pin support.'); }
        $handle = BackupCurl::handle($this->config, 'sftp');
        $options = [CURLOPT_URL => $this->base . implode('/', array_map('rawurlencode', explode('/', $this->root . $name))),
            CURLOPT_USERNAME => $this->config['username'], CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256 => $this->config['host_key_sha256']];
        if (isset($this->config['password'])) { $options[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PASSWORD; $options[CURLOPT_PASSWORD] = $this->config['password']; }
        else {
            $options[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PUBLICKEY; $options[CURLOPT_SSH_PRIVATE_KEYFILE] = $this->config['private_key'];
            if (isset($this->config['public_key'])) { $options[CURLOPT_SSH_PUBLIC_KEYFILE] = BackupCurl::text($this->config, 'public_key'); }
            if (isset($this->config['private_key_passphrase'])) { $options[CURLOPT_KEYPASSWD] = BackupCurl::text($this->config, 'private_key_passphrase'); }
        }
        if (!curl_setopt_array($handle, $options)) { throw new RuntimeException('Cannot configure secure SFTP request.'); }
        return $handle;
    }

    private function quoted(string $name): string { return '"' . $this->root . $name . '"'; }
    private function command(string $command): void
    {
        $handle = $this->handle(''); curl_setopt_array($handle, [CURLOPT_QUOTE => [$command], CURLOPT_NOBODY => true]);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static fn (CurlHandle $h, string $bytes): int => strlen($bytes));
        BackupCurl::execute($handle);
    }
}
