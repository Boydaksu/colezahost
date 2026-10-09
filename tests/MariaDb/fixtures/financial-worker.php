<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$database = $argv[1] ?? '';
if (!preg_match('/^colezahost_d02_test_[a-f0-9]{16}$/D', $database)) { throw new RuntimeException('Isolated test database required.'); }
$pdo = new PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('USE `' . $database . '`');
$db = new Coleza\Foundation\Database\Connection($pdo);
$invoices = new Coleza\Domain\Commerce\Invoices\InvoiceService($db);
$payments = new Coleza\Domain\Commerce\Payments\PaymentService($db, $invoices);
$credit = new Coleza\Domain\Commerce\Credit\CreditService($db, $invoices, $payments);
$queue = new Coleza\Foundation\Queue\DatabaseQueue($db);
$queue->ensureTables();
$servers = new Coleza\Domain\Servers\Services\ServerService($db);
$reservations = new Coleza\Domain\Servers\Capacity\Services\CapacityReservationService($db, $servers);
$action = $argv[3];
$id = (int) $argv[4];
$number = $action === 'callback' ? $payments->findPaymentById($id)->getPaymentNumber() : '';
$calls = 0;
$gateway = new Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway(
    new Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration('test-key', 'test-secret'),
    static function () use (&$calls, $action, $number): array {
        $calls++;
        usleep(100000);
        return ['code' => 200, 'body' => json_encode($action === 'callback'
            ? ['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => 'provider-payment', 'conversationId' => $number, 'paidPrice' => '100.00', 'currency' => 'TRY']
            : ['status' => 'success', 'paymentId' => 'provider-refund'])];
    });
$db->statement('INSERT INTO worker_barrier VALUES (?, 1)', [(int) $argv[2]]);
$deadline = microtime(true) + 15;
while ((int) $db->selectOne('SELECT started FROM worker_control WHERE id = 1')['started'] !== 1) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Worker barrier timeout.'); }
    usleep(10000);
}
$result = ['status' => 'ok'];
try {
    switch ($action) {
        case 'settle': $payments->completePaymentFromGateway($id, 'provider-payment'); break;
        case 'invoice': $invoices->applyPayment($id, 6000); break;
        case 'credit': $credit->deductCredit(7, 6000, 'TRY', 'Concurrent debit'); break;
        case 'creditInvoice': $credit->applyCreditToInvoice(7, $id, 6000); break;
        case 'refund': $payments->recordRefund(['payment_id' => $id, 'amount_minor' => 6000]); break;
        case 'gatewayRefund': $payments->refundViaGateway($id, 6000, 'Concurrent refund', $gateway); break;
        case 'callback':
            $result['callback'] = (new Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookHandler($db, $payments))->handleIyzicoCallback($gateway, ['token' => 'parallel-token'])->getStatus();
            break;
        case 'queue':
            $result['jobs'] = [];
            for ($i = 0; $i < 8; $i++) {
                $job = $queue->pop();
                if ($job === null) { break; }
                $result['jobs'][] = $job->getId();
            }
            break;
        case 'reserve': $result['token'] = $reservations->reserve($id)->getToken(); break;
        case 'reserveResource': $result['token'] = $reservations->reserve($id, diskMb: 600, bandwidthMb: 600)->getToken(); break;
        case 'sameScope': $result['token'] = $reservations->reserve($id, serviceId: 77)->getToken(); break;
        case 'releaseCapacity': $reservations->release($argv[5]); break;
        case 'commitExpire':
            if ((int) $argv[2] % 2 === 0) { $reservations->commit($argv[5]); }
            else { $reservations->expireStaleReservations('2030-01-01 00:00:00'); }
            break;
        default: throw new RuntimeException('Unknown worker action.');
    }
} catch (Coleza\Foundation\Exceptions\ValidationException|Coleza\Domain\Servers\Capacity\Exceptions\ReservationException $error) {
    $result = ['status' => 'rejected'];
}
$result['provider_calls'] = $calls;
echo json_encode($result, JSON_THROW_ON_ERROR);
