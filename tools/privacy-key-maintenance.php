<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
try {
    $action = $argv[1] ?? '';
    if (!(($action === 'verify' && $argc === 2) || ($action === 'bind' && $argc === 3 && $argv[2] === '--maintenance'))) { throw new RuntimeException('Invalid operation.'); }
    $config = require dirname(__DIR__) . '/config/database.php';
    $db = \Coleza\Foundation\Database\ConnectionFactory::create($config['connections'][$config['default']]);
    $service = new \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier($db);
    $result = $action === 'bind' ? $service->bind(true) : $service->verify();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL); exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Privacy key operation refused. Keep maintenance writers stopped; verify installed schema, independent protected keys, original tombstones and approved legacy evidence.\n"); exit(1);
}
