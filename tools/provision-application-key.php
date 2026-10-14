<?php

declare(strict_types=1);

// Explicit CLI operation. Runtime bootstrap never generates or replaces a key.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    if ($argc !== 2) { throw new RuntimeException('Usage: php tools/provision-application-key.php <absolute-private-directory/application.key>'); }
    $path = $argv[1];
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) { throw new RuntimeException('An absolute private key path is required.'); }
    $result = \Coleza\Domain\Vault\PrivateKeyFile::provision($path, dirname(__DIR__));
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    // Never print exception traces, file content, or application keys.
    fwrite(STDERR, "Application key provisioning refused; verify the private path, ownership, permissions and that no key already exists.\n");
    exit(1);
}
