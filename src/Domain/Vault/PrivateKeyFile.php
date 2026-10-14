<?php

declare(strict_types=1);
namespace Coleza\Domain\Vault;

/** Dedicated private directory and file; no secret ever travels on a subprocess command line. */
final class PrivateKeyFile
{
    private static function absolute(string $path): void
    {
        $pattern = PHP_OS_FAMILY === 'Windows' ? '~^[A-Za-z]:[\\\\/]~' : '~^/~';
        if (!preg_match($pattern, $path)) { throw new \RuntimeException('Use an absolute local private key path.'); }
    }
    private static function canonical(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
    private static function outside(string $directory, string $publicRoot): void
    {
        $root = realpath($publicRoot); $parent = realpath($directory);
        if ($root === false || $parent === false || is_link($directory)) { throw new \RuntimeException('Use real private/public directories.'); }
        $a = self::canonical($parent); $b = self::canonical($root);
        if ($a === $b || str_starts_with($a, $b . '/')) { throw new \RuntimeException('Application key must stay outside the served application directory.'); }
    }
    private static function policy(string $path, bool $protect, bool $directory): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ($directory ? !is_dir($path) : !is_file($path))) { throw new \RuntimeException('Key paths must be real directories and regular files.'); }
        if (PHP_OS_FAMILY !== 'Windows') {
            if ($protect && !chmod($path, $directory ? 0700 : 0600)) { throw new \RuntimeException('Cannot protect key storage.'); }
            clearstatcache(true, $path); $s = lstat($path);
            if (!function_exists('posix_geteuid') || $s === false || $s['uid'] !== posix_geteuid() || ($s['mode'] & 0077) !== 0) { throw new \RuntimeException('Private key storage owner/permissions are unsafe.'); }
            return;
        }
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
$taskPath = $env:COLEZA_KEY_POLICY_PATH
$taskSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$taskSystem = [System.Security.Principal.SecurityIdentifier]::new('S-1-5-18')
$taskItem = Get-Item -LiteralPath $taskPath -Force
if (($taskItem.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse key path refused' }
if ($env:COLEZA_KEY_POLICY_PROTECT -eq '1') {
    if ($env:COLEZA_KEY_POLICY_DIRECTORY -eq '1') {
        $taskAcl = [System.Security.AccessControl.DirectorySecurity]::new()
        $taskFlags = [System.Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    } else {
        $taskAcl = [System.Security.AccessControl.FileSecurity]::new()
        $taskFlags = [System.Security.AccessControl.InheritanceFlags]::None
    }
    $taskAcl.SetOwner($taskSid)
    $taskAcl.SetAccessRuleProtection($true, $false)
    foreach ($taskPrincipal in @($taskSid, $taskSystem)) {
        $taskRule = [System.Security.AccessControl.FileSystemAccessRule]::new($taskPrincipal, [System.Security.AccessControl.FileSystemRights]::FullControl, $taskFlags, [System.Security.AccessControl.PropagationFlags]::None, [System.Security.AccessControl.AccessControlType]::Allow)
        $taskAcl.AddAccessRule($taskRule)
    }
    Set-Acl -LiteralPath $taskPath -AclObject $taskAcl
}
$taskAcl = Get-Acl -LiteralPath $taskPath
$taskOwner = ([System.Security.Principal.NTAccount]::new($taskAcl.Owner)).Translate([System.Security.Principal.SecurityIdentifier]).Value
if ($taskOwner -ne $taskSid.Value -or !$taskAcl.AreAccessRulesProtected) { throw 'Unsafe key owner/inheritance' }
$taskOwnerAccess = $false
foreach ($taskRule in $taskAcl.Access) {
    $taskRuleSid = $taskRule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
    if ($taskRule.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Allow) {
        if ($taskRuleSid -notin @($taskSid.Value, 'S-1-5-18')) { throw 'Unsafe key access' }
        if ($taskRuleSid -eq $taskSid.Value -and ($taskRule.FileSystemRights -band [System.Security.AccessControl.FileSystemRights]::FullControl) -eq [System.Security.AccessControl.FileSystemRights]::FullControl) { $taskOwnerAccess = $true }
    }
}
if (!$taskOwnerAccess) { throw 'Key owner access missing' }
'OK'
PS;
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $process = proc_open([$systemRoot . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(iconv('UTF-8', 'UTF-16LE', $script))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['SystemRoot' => $systemRoot, 'COLEZA_KEY_POLICY_PATH' => $path, 'COLEZA_KEY_POLICY_PROTECT' => $protect ? '1' : '0', 'COLEZA_KEY_POLICY_DIRECTORY' => $directory ? '1' : '0'], ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot verify Windows key ACL.'); }
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
        if ($code !== 0 || trim($output) !== 'OK') { throw new \RuntimeException('Windows private key ACL verification failed.'); }
    }
    public static function provision(string $path, string $publicRoot): array
    {
        self::absolute($path);
        $directory = dirname($path); $parent = realpath(dirname($directory));
        if ($parent === false || !preg_match('/^[a-zA-Z0-9_.-]+\.key$/D', basename($path))) { throw new \RuntimeException('Use an explicit private key file in a dedicated directory.'); }
        $root = realpath($publicRoot);
        $candidate = self::canonical($parent . '/' . basename($directory));
        if ($root === false || $candidate === self::canonical($root) || str_starts_with($candidate, self::canonical($root) . '/')) { throw new \RuntimeException('Private key directory must be outside the served application directory.'); }
        return self::create($path, $publicRoot);
    }
    private static function create(string $path, string $publicRoot): array
    {
        $directory = dirname($path);
        if (!is_dir($directory)) { if (!mkdir($directory, 0700)) { throw new \RuntimeException('Cannot create private key directory.'); } self::policy($directory, true, true); }
        self::outside($directory, $publicRoot); self::policy($directory, false, true);
        $previous = umask(0077); $handle = @fopen($path, 'x+b'); umask($previous);
        if ($handle === false) { throw new \RuntimeException('Key file already exists or cannot be exclusively created.'); }
        try {
            self::policy($path, true, false); $key = ApplicationKey::generate();
            if (fwrite($handle, $key . "\n") !== strlen($key) + 1 || !fflush($handle)) { throw new \RuntimeException('Private key persistence failed.'); }
            if (function_exists('fsync') && !fsync($handle)) { throw new \RuntimeException('Private key persistence failed.'); }
        } finally { fclose($handle); }
        return ['key_id' => ApplicationKey::encryptor(self::load($path, $publicRoot))->fingerprint()];
    }
    public static function load(string $path, string $publicRoot): string
    {
        self::absolute($path);
        self::outside(dirname($path), $publicRoot); self::policy(dirname($path), false, true); self::policy($path, false, false);
        $data = file_get_contents($path, false, null, 0, 53);
        if ($data === false || !preg_match('/^base64:[A-Za-z0-9+\/]{43}=\n?$/D', $data)) { throw new \RuntimeException('Private key file is invalid.'); }
        $key = trim($data); ApplicationKey::encryptor($key); return $key;
    }
}
