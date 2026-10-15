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
$queue = new Coleza\Foundation\Queue\DatabaseQueue($db, new \Coleza\Domain\Vault\QueuePayloadStore($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()));
$queue->ensureTables();
$servers = new Coleza\Domain\Servers\Services\ServerService($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
$reservations = new Coleza\Domain\Servers\Capacity\Services\CapacityReservationService($db, $servers);
$action = $argv[3];
if (str_starts_with($action, 'fraudReview') && getenv('COLEZA_FRAUD_BASELINE') === '1') {
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FraudBaselineStatement::class]);
}
if (in_array($action, ['configurationBrandCreate', 'configurationCurrencyDefault'], true) && ($argv[5] ?? '') === 'baseline') {
    // Unmodified HEAD sources; widen only the old read/write gap to make the race reproducible.
    require dirname(__DIR__, 3) . '/remediation/runtime/baseline-configuration-brand.php';
    require dirname(__DIR__, 3) . '/remediation/runtime/baseline-configuration-currency.php';
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ConfigurationBaselineStatement::class]);
}
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
        case 'organizationDemoteInvite':
        case 'organizationRemoveSelect':
        case 'organizationAccept':
        case 'organizationInvite':
            $organization = new Coleza\Domain\Identity\Organization\OrganizationService($db, new Coleza\Domain\Identity\Session\DatabaseSessionHandler($db));
            try {
                if ($action === 'organizationDemoteInvite') {
                    if ((int) $argv[2] === 0) { $organization->changeMemberRole(1, $id, 4, 'member'); }
                    else { $organization->inviteMember(4, $id, 'member@example.test'); }
                }
                elseif ($action === 'organizationRemoveSelect') {
                    if ((int) $argv[2] === 0) { $organization->removeMember(1, $id, 2); }
                    else { $organization->switchOrganization('member-session', 2, $id); }
                }
                elseif ($action === 'organizationAccept') { $organization->acceptInvitation($id, 'member@example.test', $argv[5]); $result['accepted'] = true; }
                else { $result['token'] = $organization->inviteMember(1, $id, 'member@example.test'); }
            } catch (Coleza\Foundation\Exceptions\ValidationException) { $result['status'] = 'rejected'; $result['accepted'] = false; }
            break;
        case 'keyRotationRace':
        case 'keyStaleWriter':
            $result['kind'] = $action === 'keyRotationRace' && (int) $argv[2] === 0 ? 'rotate' : 'write';
            $result['accepted'] = false;
            try {
                $key = \Coleza\Tests\Support\SecretFixtureKey::encryptor();
                if ($result['kind'] === 'rotate') {
                    (new Coleza\Domain\Vault\KeyLifecycleService($db, $key))->rotateToFile($argv[5], dirname(__DIR__, 3), true);
                } else {
                    (new Coleza\Domain\Vault\VaultService($db, $key, new Coleza\Domain\Identity\Audit\AuditLogger($db)))->setSecret('provider:race', 'token', 'race-synthetic-' . $argv[2]);
                }
                $result['accepted'] = true;
            } catch (RuntimeException|LogicException) { $result['status'] = 'rejected'; }
            break;
        case 'vaultRotate':
            $vault = new Coleza\Domain\Vault\VaultService($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor(), new Coleza\Domain\Identity\Audit\AuditLogger($db));
            $vault->setSecret('provider:parallel', 'api_token', 'worker-synthetic-' . $argv[2]);
            $result['accepted'] = true;
            break;
        case 'identityReset':
        case 'identityVerify':
        case 'identityRecovery':
        case 'identityLogin':
        case 'identityConfirm':
        case 'identityReplaceReset':
        case 'identityRegister':
        case 'identityResetAndSessionWrite':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $sessions = new Coleza\Domain\Identity\Session\DatabaseSessionHandler($db);
            $hasher = new Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
            $auth = new Coleza\Domain\Identity\Auth\AuthService($db, $hasher, $sessions);
            $two = new Coleza\Domain\Identity\TwoFactor\TwoFactorService($db, new Coleza\Domain\Identity\TwoFactor\TotpEngine(), $hasher, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
            $result['accepted'] = false;
            if ($action === 'identityResetAndSessionWrite' && (int) $argv[2] % 2 === 1) {
                $sessions->write('original-session', ['user_id' => $id, 'old_writer' => true], $id); $result['accepted'] = true; break;
            }
            switch ($action) {
                case 'identityReset':
                case 'identityResetAndSessionWrite':
                    $auth->resetPassword('fixture@example.test', $argv[5], 'ConcurrentReplacement123!'); $result['accepted'] = true; break;
                case 'identityVerify': $result['accepted'] = $auth->verifyEmail($id, $argv[5]); break;
                case 'identityRecovery': $result['accepted'] = $two->redeemRecoveryCode($id, $argv[5]); break;
                case 'identityLogin': $result['accepted'] = $two->verifyLoginCode($id, $argv[5]); break;
                case 'identityConfirm': $result['codes'] = $two->confirmSetup($id, $argv[5]); $result['accepted'] = true; break;
                case 'identityReplaceReset': $result['token'] = $auth->createPasswordResetToken('fixture@example.test'); $result['accepted'] = true; break;
                case 'identityRegister': $result['user_id'] = $auth->register('parallel@example.test', 'Registration123!', 'Parallel'); $result['accepted'] = true; break;
            }
            break;
        case 'apiRateAdmission':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            try { $result['admission'] = (new Coleza\Api\RateLimit\RateLimiter($db))->hit('parallel', $id, 60); }
            catch (Coleza\Foundation\Exceptions\ValidationException $error) { $result = ['status' => 'rejected', 'class' => $error::class]; }
            break;
        case 'apiSessionWrite':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $worker = (int) $argv[2] + 1;
            (new Coleza\Domain\Identity\Session\DatabaseSessionHandler($db))->write('parallel', ['worker' => $worker], $worker, null, 'Worker ' . $worker);
            break;
        case 'supportRoundRobin':
        case 'supportLeastLoaded':
        case 'supportManualSame':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $departments = new Coleza\Domain\Support\Departments\DepartmentService($db);
            $tickets = new Coleza\Domain\Support\Tickets\TicketService($db, $departments);
            $assignments = new Coleza\Domain\Support\Assignments\TicketAssignmentService($db, $departments, $tickets);
            $ticketId = $action === 'supportManualSame' ? $id : $id + (int) $argv[2];
            $log = match ($action) {
                'supportRoundRobin' => $assignments->assignRoundRobin($ticketId, 99),
                'supportLeastLoaded' => $assignments->assignLeastLoaded($ticketId, 99),
                'supportManualSame' => $assignments->assignManual($ticketId, 10, 99),
            };
            $result['log_id'] = $log->getId(); $result['assigned_to'] = $log->getNewAssignedTo();
            break;
        case 'analyticsDay':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $result['aggregation_id'] = (new Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService($db))->aggregateDay('2026-10-13')->getId();
            break;
        case 'analyticsMonth':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $result['aggregation_id'] = (new Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService($db))->aggregateMonth('2026-10')->getId();
            break;
        case 'operationalModeTransition':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $modes = [Coleza\Domain\OperationalMode\OperationalMode::SAFE, Coleza\Domain\OperationalMode\OperationalMode::READ_ONLY, Coleza\Domain\OperationalMode\OperationalMode::RECOVERY, Coleza\Domain\OperationalMode\OperationalMode::FULL_MAINTENANCE];
            $worker = (int) $argv[2];
            (new Coleza\Domain\OperationalMode\OperationalModeManager($db))->transitionTo($modes[$worker], 'Worker ' . $worker, ['192.0.2.' . ($worker + 1)]);
            break;
        case 'fraudReviewCreate':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $score = new Coleza\Domain\Fraud\Risk\RiskScoreService($db);
            $review = new Coleza\Domain\Fraud\Review\RiskReviewService($db, $score);
            $result['review_id'] = $review->createReview($score->getEvaluation($id))->getId();
            break;
        case 'fraudReviewResolve':
        case 'fraudReviewMixed':
        case 'fraudReviewCreateResolve':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $score = new Coleza\Domain\Fraud\Risk\RiskScoreService($db);
            $review = new Coleza\Domain\Fraud\Review\RiskReviewService($db, $score);
            if ($action === 'fraudReviewCreateResolve' && (int) $argv[2] % 2 === 1) {
                $result['review_id'] = $review->createReview($score->getEvaluation($review->getReview($id)->getEvaluationId()))->getId(); break;
            }
            if ($action === 'fraudReviewMixed' && (int) $argv[2] === 1) { $review->claimReview($id, 41); break; }
            if ($action === 'fraudReviewMixed' && (int) $argv[2] === 3) { $review->escalateReview($id, 43); break; }
            if ((int) $argv[2] % 2 === 0 && (int) $argv[2] !== 2) { $review->approveReview($id, 40 + (int) $argv[2], 'Worker approved'); }
            else { $review->rejectReview($id, 40 + (int) $argv[2], 'Worker rejected'); }
            break;
        case 'configurationBrandCreate':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $result['brand_id'] = (new Coleza\Domain\Brand\BrandService($db))->createBrand('Worker ' . $argv[2], 'worker-' . $argv[2]);
            break;
        case 'configurationCurrencyDefault':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $code = ['USD', 'EUR', 'JPY', 'BHD'][(int) $argv[2]];
            (new Coleza\Domain\Finance\Currency\CurrencyService($db))->createCurrency(['code' => $code, 'name' => $code, 'symbol' => $code, 'is_default' => true]);
            break;
        case 'configurationFallback':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $result['brand_id'] = (new Coleza\Domain\Brand\BrandService($db))->getActiveBrand(['code' => 'worker-' . $argv[2]])['id'];
            $result['currency'] = (new Coleza\Domain\Finance\Currency\CurrencyService($db))->getDefaultCurrency()->getCode();
            break;
        case 'configurationMixedBrand':
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            if ((int) $argv[2] % 2 === 0) {
                $provider = (new Coleza\Domain\Installer\BrandSetupService())->configureBrand($db, new Coleza\Domain\Installer\BrandSetupDto('Company', 'Provider', 'support@example.test'), ownerUserId: $id);
                $result['brand_id'] = $provider['brand_id'];
            } else { $result['brand_id'] = (new Coleza\Domain\Brand\BrandService($db))->createBrand('Worker ' . $argv[2], 'worker-' . $argv[2]); }
            break;
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
            $operationService = new Coleza\Domain\Provisioning\Services\ProvisioningOperationService($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
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
            $engine = Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($db, $registry, encryptor: \Coleza\Tests\Support\SecretFixtureKey::encryptor());
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
                $engine = Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($db, new Coleza\Domain\Automation\Actions\ActionRegistry(), encryptor: \Coleza\Tests\Support\SecretFixtureKey::encryptor());
                $result['delay_id'] = $engine->executeApprovedRequest($argv[5], $action === 'approvalDelaySameActor' ? 'actor' : 'actor_' . $argv[2])->getDelayId();
            } catch (InvalidArgumentException $error) {
                $result = ['status' => 'rejected', 'reason' => $error->getMessage()];
            } catch (Throwable $error) {
                $result = ['status' => 'error', 'class' => $error::class, 'reason' => Coleza\Foundation\Security\SecretRedactor::text($error->getMessage())];
                if ($error instanceof PDOException) { $result['sqlstate'] = $error->errorInfo[0] ?? null; $result['driver_code'] = $error->errorInfo[1] ?? null; }
            }
            break;
        case 'domainSecretDuplicate':
        case 'domainSecretWrite':
            try {
                $domainKey = \Coleza\Tests\Support\SecretFixtureKey::encryptor();
                $domainService = new Coleza\Domain\Domains\DomainService($db, null, $domainKey);
                $domainOps = new Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($db, $domainKey);
                if ($action === 'domainSecretDuplicate') {
                    $op = $domainOps->create(['domain_id' => $id, 'operation_type' => 'transfer', 'idempotency_key' => 'native-duplicate', 'payload' => ['token' => 'synthetic-worker-input']]);
                    $result['operation_id'] = $op->getId();
                } else {
                    for ($iteration = 0; $iteration < 3; $iteration++) {
                        $domainService->setEppCode($id, 'synthetic-worker-epp-' . $argv[2]);
                        $domainOps->markUncertain((int) $argv[5], 'Timeout', 'TIMEOUT', ['marker' => (int) $argv[2], 'token' => 'synthetic-worker-result']);
                        $domainService->findDomainById($id); $domainOps->findById((int) $argv[5]);
                    }
                }
            } catch (PDOException $error) {
                $result = ['status' => $error->getCode() === '23000' ? 'rejected' : 'error', 'sqlstate' => $error->getCode(), 'driver_code' => $error->errorInfo[1] ?? null];
            } catch (Throwable $error) { $result = ['status' => 'error', 'class' => $error::class]; }
            break;
        case 'automationVersion':
            $rule = new Coleza\Domain\Automation\Engine\AutomationRule('parallel_rule', 'Rule', new Coleza\Domain\Automation\Triggers\EventTrigger('event'),
                new Coleza\Domain\Automation\Branching\IfElseBranch(new Coleza\Domain\Automation\Conditions\FieldCondition('value', '==', (int) $argv[2])));
            $version = (new Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()))->recordVersion($rule, 'worker_' . $argv[2], 'Concurrent publication');
            $result['version'] = $version->getVersionNumber();
            break;
        case 'automationClaim':
            $result['claimed'] = (int) (new Coleza\Domain\Automation\Delay\DatabaseDelayManager($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()))->claimForDispatch($argv[5]);
            break;
        case 'automationApprove':
            try {
                (new Coleza\Domain\Automation\Approval\DatabaseApprovalManager($db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()))->approve($argv[5], 'worker_' . $argv[2]);
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
        case 'stagingDuplicate':
            $staging=new Coleza\Domain\Migration\Staging\DatabaseStagingRepository($db,\Coleza\Tests\Support\SecretFixtureKey::encryptor());
            $staged=Coleza\Domain\Migration\Staging\StagingRecord::createNew('batch','csv','client','7',['password'=>'synthetic-worker-'.(int)$argv[2],'amount'=>1.0]);
            $staged->markTransformed('client',['token'=>'synthetic-canonical-'.(int)$argv[2]]);$staging->save($staged);
            $result['id']=$staged->getId();$result['canonical']=$staging->find($staged->getId())->getCanonicalPayload();
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

final class ConfigurationBaselineStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (preg_match('/^\s*SELECT COUNT\(\*\) as cnt FROM brands WHERE is_active = 1\s*$/i', $this->queryString)
            || preg_match('/^\s*UPDATE currencies SET is_default = 0\s*$/i', $this->queryString)) { usleep(300000); }
        return $result;
    }
}

final class FraudBaselineStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (preg_match('/^\s*SELECT \* FROM fraud_risk_reviews WHERE (?:id = :id|evaluation_id = :eval_id) LIMIT 1\s*$/i', $this->queryString)) { usleep(300000); }
        return $result;
    }
}
