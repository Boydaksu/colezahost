<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
try {
    $action = $argv[1] ?? '';
    $valid = ($action === 'verify' && $argc === 2)
        || ($action === 'bind' && $argc === 3 && $argv[2] === '--maintenance')
        || ($action === 'rotate' && $argc === 4 && $argv[3] === '--maintenance');
    if (!$valid) { throw new RuntimeException('Invalid operation.'); }
    $key = \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::configured());
    $config = require dirname(__DIR__) . '/config/database.php';
    $db = \Coleza\Foundation\Database\ConnectionFactory::create($config['connections'][$config['default']]);
    $service = new \Coleza\Domain\Vault\KeyLifecycleService($db, $key);
    $result = match ($action) { 'verify' => $service->verify(), 'bind' => $service->initialize(true), 'rotate' => $service->rotateToFile($argv[2], dirname(__DIR__), true) };
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL); exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Application key operation refused. Keep writers stopped; verify schema, key, protected replacement file and secret provenance.\n"); exit(1);
}
