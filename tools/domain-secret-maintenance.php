<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
try {
    if ($argc !== 3 || $argv[1] !== '--maintenance' || $argv[2] !== '--confirmed-legacy-data') { throw new RuntimeException('Invalid maintenance admission.'); }
    $key = \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::configured());
    $config = require dirname(__DIR__) . '/config/database.php';
    $db = \Coleza\Foundation\Database\ConnectionFactory::create($config['connections'][$config['default']]);
    $count = (new \Coleza\Domain\Domains\DomainSecretStore($db, $key))->upgrade(true);
    fwrite(STDOUT, json_encode(['upgraded_fields' => $count], JSON_THROW_ON_ERROR) . PHP_EOL); exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Domain secret upgrade refused. Keep writers stopped; verify schema, key, trusted legacy provenance and owner integrity.\n"); exit(1);
}
