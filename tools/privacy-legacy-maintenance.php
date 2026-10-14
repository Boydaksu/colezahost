<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
try {
    $options = getopt('', ['maintenance', 'confirmed-independent-evidence', 'manifest:', 'expected-sha256:', 'provenance:', 'legacy-tombstone-file:']);
    if (!is_array($options) || !isset($options['manifest'], $options['expected-sha256'], $options['provenance'])
        || !array_key_exists('maintenance', $options) || !array_key_exists('confirmed-independent-evidence', $options)) {
        throw new RuntimeException('Explicit independent-review maintenance admission required.');
    }
    foreach (['manifest', 'expected-sha256', 'provenance'] as $name) {
        if (!is_string($options[$name])) { throw new RuntimeException('Invalid maintenance argument.'); }
    }
    $store = \Coleza\Domain\Privacy\Integrity\PrivacyLegacyEvidenceStore::configured();
    if ($store === null) { throw new RuntimeException('Configure the independent evidence destination.'); }
    $manifest = file_get_contents($options['manifest']);
    if ($manifest === false) { throw new RuntimeException('Cannot read independently approved evidence.'); }
    $config = require dirname(__DIR__) . '/config/database.php';
    $db = \Coleza\Foundation\Database\ConnectionFactory::create($config['connections'][$config['default']]);
    $count = (new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($db, $store))->attest($manifest,
        $options['expected-sha256'], $options['provenance'], true, $options['legacy-tombstone-file'] ?? null);
    fwrite(STDOUT, json_encode(['attested_records' => $count], JSON_THROW_ON_ERROR) . PHP_EOL); exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Privacy legacy attestation refused. Keep writers stopped; verify independently reviewed source digest, provenance, keys, destination and exact current records.\n"); exit(1);
}
