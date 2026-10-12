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
        case 'automationLocalLifecycle':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            try {
                (new Coleza\Domain\Commerce\Services\ServiceService($db))->executeLocalAutomationLifecycle($id, 'suspend', 'Local fixture', 1);
                $result['changed'] = 1;
            } catch (Coleza\Domain\Commerce\Services\Exceptions\ServiceConcurrencyException) { $result['changed'] = 0; }
            break;
        case 'automationAttemptStart':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $journal = new Coleza\Domain\Automation\Execution\DatabaseActionAttemptJournal($db);
            try {
                $journal->start($argv[5], new Coleza\Domain\Automation\Actions\ActionDefinition('a0', 'send', ['token' => '{{ token }}']),
                    new Coleza\Domain\Automation\Triggers\TriggerContext('event', ['ok' => true, 'token' => 'SECRET_NOT_FOR_JOURNAL'], correlationId: 'parallel'));
                $result['claimed'] = 1;
            } catch (LogicException $error) {
                if (!str_contains($error->getMessage(), 'Action attempt changed or missing')) { throw $error; }
                $result['claimed'] = 0;
            }
            break;
        case 'provisionReconcile':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $operationService = new Coleza\Domain\Provisioning\Services\ProvisioningOperationService($db);
            $operation = $operationService->findOperationByUuid($argv[5]);
            $transport = new Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport();
            $transport->stageResponse('accountsummary', 200, ['metadata' => ['result' => 1], 'data' => ['acct' => [[
                'user' => 'fixture', 'domain' => 'fixture.test', 'plan' => 'starter', 'ip' => '10.0.0.1', 'suspended' => 0]]]]);
            $probe = new Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService($servers,
                new Coleza\Domain\Providers\Registry\ProviderRegistry([new Coleza\Domain\Providers\Cpanel\CpanelProvider($transport)]),
                new Coleza\Domain\Commerce\Services\ServiceService($db), $operationService);
            try {
                $observation = $probe->reconcile($operation);
                $result['status'] = $observation->isExists() ? 'completed' : 'unresolved';
            } catch (LogicException $error) {
                if (!str_contains($error->getMessage(), 'inputs changed during remote probe')) { throw $error; }
                $result['status'] = 'stale';
            }
            break;
        case 'automationBlast':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $registry = new Coleza\Domain\Automation\Actions\ActionRegistry();
            $registry->register(new Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler('service.terminate', function () use ($db, &$calls, $id, $argv): array {
                $calls++;
                $db->insert('destructive_effect_fixture', ['worker_id' => (int) $argv[2]]);
                if ($id === 1) { throw new RuntimeException('Uncertain outcome after simulated effect'); }
                return [];
            }));
            $engine = Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($db, $registry);
            $rule = new Coleza\Domain\Automation\Engine\AutomationRule('parallel_blast', 'Parallel destructive', new Coleza\Domain\Automation\Triggers\EventTrigger('event'),
                new Coleza\Domain\Automation\Branching\IfElseBranch(new Coleza\Domain\Automation\Conditions\FieldCondition('ok', '==', true),
                    [new Coleza\Domain\Automation\Actions\ActionDefinition('a', 'service.terminate')]));
            $run = $engine->executeRule($rule, new Coleza\Domain\Automation\Triggers\TriggerContext('event', ['ok' => true]));
            $result['calls'] = $calls;
            $result['successful'] = (int) $run->isSuccessful();
            break;
        case 'approvalDelaySameActor':
        case 'approvalDelayDifferentActors':
            try {
                $engine = Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($db, new Coleza\Domain\Automation\Actions\ActionRegistry());
                $result['delay_id'] = $engine->executeApprovedRequest($argv[5], $action === 'approvalDelaySameActor' ? 'actor' : 'actor_' . $argv[2])->getDelayId();
            } catch (InvalidArgumentException $error) {
                $result = ['status' => 'rejected', 'reason' => $error->getMessage()];
            }
            break;
        case 'automationVersion':
            $rule = new Coleza\Domain\Automation\Engine\AutomationRule('parallel_rule', 'Rule', new Coleza\Domain\Automation\Triggers\EventTrigger('event'),
                new Coleza\Domain\Automation\Branching\IfElseBranch(new Coleza\Domain\Automation\Conditions\FieldCondition('value', '==', (int) $argv[2])));
            $version = (new Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($db))->recordVersion($rule, 'worker_' . $argv[2], 'Concurrent publication');
            $result['version'] = $version->getVersionNumber();
            break;
        case 'automationClaim':
            $result['claimed'] = (int) (new Coleza\Domain\Automation\Delay\DatabaseDelayManager($db))->claimForDispatch($argv[5]);
            break;
        case 'automationApprove':
            try {
                (new Coleza\Domain\Automation\Approval\DatabaseApprovalManager($db))->approve($argv[5], 'worker_' . $argv[2]);
            } catch (InvalidArgumentException $error) {
                $result = ['status' => 'rejected', 'reason' => $error->getMessage()];
            }
            break;
        case 'adoptService':
        case 'adoptDomain':
            $identityRepo = new Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($db);
            $resolver = new Coleza\Domain\Migration\Adoption\ProviderIdentityResolver(serverMap: ['fixture' => 3], productMap: ['product' => 5], clientMap: ['client' => 7]);
            if ($action === 'adoptService') {
                $adopter = new Coleza\Domain\Migration\Adoption\ServiceAdoptionService($db, $identityRepo, $resolver);
                $adopted = $adopter->adoptService(new Coleza\Domain\Migration\Canonical\CanonicalServiceDto('parallel-service', 'whmcs', 'client', 'product', 'parallel.test', 'parallel-user', registrationDate: '2025-01-01', nextDueDate: '2026-12-01'), 'parallel-batch', 'fixture');
                $result['entity_id'] = $adopted->getAdoptedServiceId();
            } else {
                $adopter = new Coleza\Domain\Migration\Adoption\DomainAdoptionService($db, $identityRepo, $resolver);
                $adopted = $adopter->adoptDomain(new Coleza\Domain\Migration\Canonical\CanonicalDomainDto('parallel-domain', 'whmcs', 'client', 'parallel.test', 'manual'), 'parallel-batch');
                $result['entity_id'] = $adopted->getAdoptedDomainId();
            }
            if (!$adopted->isSuccess()) { throw new RuntimeException('Parallel adoption failed.'); }
            break;
        case 'providerBrand':
            $provider = (new Coleza\Domain\Installer\BrandSetupService())->configureBrand($db, new Coleza\Domain\Installer\BrandSetupDto('Provider Company', 'Provider Brand', 'support@example.test'), ownerUserId: $id);
            $result['organization_id'] = $provider['organization_id'];
            $result['brand_id'] = $provider['brand_id'];
            break;
        case 'parallelQuotes':
        case 'parallelProformas':
            $service = $action === 'parallelQuotes'
                ? new Coleza\Domain\Documents\Quotes\QuoteService($pdo)
                : new Coleza\Domain\Documents\Proforma\ProformaService($pdo);
            $method = $action === 'parallelQuotes' ? 'createQuote' : 'createProforma';
            $numberMethod = $action === 'parallelQuotes' ? 'getQuoteNumber' : 'getProformaNumber';
            $result['numbers'] = [];
            for ($i = 0; $i < 16; $i++) {
                $document = $service->$method(7 + (int) $argv[2], [['description' => 'Concurrent hosting', 'unit_amount_minor' => 100]], organizationId: 7 + (int) $argv[2]);
                $result['numbers'][] = $document->$numberMethod();
            }
            break;
        case 'documentNumbers':
            $generator = new Coleza\Domain\Documents\Numbering\DocumentNumberGenerator($pdo);
            $result['numbers'] = [];
            for ($i = 0; $i < 64; $i++) {
                $result['numbers'][] = $generator->generateNextNumber(Coleza\Domain\Documents\DocumentType::QUOTE, $id, 2026);
            }
            break;
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
