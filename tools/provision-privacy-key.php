<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    if ($argc !== 3 || !isset(\Coleza\Domain\Privacy\Integrity\PrivacySigningKey::FILES[$argv[1]])) {
        throw new RuntimeException('Usage: provision-privacy-key.php <tombstone|redaction|email> <absolute-private-key-path>');
    }
    $result = \Coleza\Domain\Vault\PrivateKeyFile::provision($argv[2], dirname(__DIR__));
    $result['purpose'] = $argv[1];
    $result['configuration_variable'] = \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::FILES[$argv[1]];
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Privacy key provisioning refused; use a supported purpose and an absolute protected path outside the application, with no existing key.\n");
    exit(1);
}
