<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$database = $argv[1] ?? '';
if (!preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Worker requires an isolated test database.');
}
$pdo = new PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('USE `' . $database . '`');
$db = new Coleza\Foundation\Database\Connection($pdo);
$db->statement('INSERT INTO worker_barrier (worker_id, ready) VALUES (?, 1)', [(int) $argv[2]]);
$deadline = microtime(true) + 15;
while ((int) $db->selectOne('SELECT started FROM worker_control WHERE id = 1')['started'] !== 1) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(10000);
}
$invoices = new Coleza\Domain\Commerce\Invoices\InvoiceService($db);
$numbers = [];
for ($i = 0; $i < 64; $i++) {
    $numbers[] = $invoices->nextInvoiceNumber();
}
echo json_encode($numbers, JSON_THROW_ON_ERROR);
