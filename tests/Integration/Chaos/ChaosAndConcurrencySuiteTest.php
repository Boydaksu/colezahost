<?php

declare(strict_types=1);

namespace Tests\Integration\Chaos;

use Coleza\Domain\Commerce\Credit\CreditEntry;
use Coleza\Domain\Commerce\Credit\CreditService;
use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookEvent;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookHandler;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookResult;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Recurring\Scheduler\MissedSchedulerCatchupService;
use Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy;
use Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler;
use Coleza\Domain\Commerce\Services\Exceptions\ServiceConcurrencyException;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRegistrarService;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperation;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationReconciliationService;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository;
use Coleza\Domain\Domains\Registrar\Reconciliation\IdempotentDomainOperationService;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorCategory;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Reconciliation\ReconciliationStatus;
use Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService;
use Coleza\Domain\Provisioning\Retry\ProvisioningRetryPolicy;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Services\ProvisioningRetryRunner;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningRequest;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningResult;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use Coleza\Domain\Servers\Capacity\Entities\CapacityReservation;
use Coleza\Domain\Servers\Capacity\Exceptions\CapacityExceededException;
use Coleza\Domain\Servers\Capacity\Exceptions\ReservationException;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\PlacementDecision;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Placement\PlacementRequest;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ConflictException;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Idempotency\IdempotencyManager;
use Coleza\Foundation\Lock\DatabaseLock;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * P18.4 Concurrency, Failure, Chaos, and Provider Uncertainty Integration Suite.
 */
final class ChaosAndConcurrencySuiteTest extends TestCase
{
    private Connection $db;
    private OrderService $orderService;
    private InvoiceService $invoiceService;
    private PaymentService $paymentService;
    private CreditService $creditService;
    private PaymentWebhookHandler $webhookHandler;
    private ServerService $serverService;
    private CapacityReservationService $reservationService;
    private PlacementEngine $placementEngine;
    private ServiceService $serviceService;
    private DatabaseLock $lock;
    private IdempotencyManager $idempotencyManager;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = new Connection($pdo, 'sqlite');

        $this->orderService = new OrderService($this->db);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db, null, null, $this->orderService);
        $this->invoiceService->ensureTables();

        $this->paymentService = new PaymentService($this->db, $this->invoiceService, $this->orderService);
        $this->paymentService->ensureTables();

        $this->creditService = new CreditService($this->db, $this->invoiceService, $this->paymentService);
        $this->creditService->ensureTables();

        $this->webhookHandler = new PaymentWebhookHandler(
            $this->db,
            $this->paymentService,
            $this->invoiceService,
            $this->orderService
        );

        $this->serverService = new ServerService($this->db);
        $this->serverService->ensureTables();

        $this->reservationService = new CapacityReservationService($this->db, $this->serverService);
        $this->reservationService->ensureTables();

        $this->placementEngine = new PlacementEngine($this->serverService);

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        $this->lock = new DatabaseLock($this->db);
        $this->lock->ensureLocksTable();

        $this->idempotencyManager = new IdempotencyManager($this->db);
        $this->idempotencyManager->ensureTable();
    }

    /**
     * Scenario 1: Double-Payment Webhook Delivery Chaos & Replay Idempotency.
     */
    public function testDoublePaymentWebhookDeliveryConcurrentChaos(): void
    {
        $order = $this->orderService->createOrder(
            ['user_id' => 42, 'currency_code' => 'TRY'],
            [
                [
                    'product_type' => 'shared_hosting',
                    'product_id' => 10,
                    'product_name' => 'Gold Cloud',
                    'billing_cycle' => 'monthly',
                    'currency_code' => 'TRY',
                    'price_minor' => 25000,
                ],
            ]
        );

        $invoice = $this->invoiceService->createInvoice(
            [
                'order_id' => $order->getId(),
                'user_id' => 42,
                'currency_code' => 'TRY',
            ],
            [
                [
                    'description' => 'Gold Cloud Hosting',
                    'unit_amount_minor' => 25000,
                    'tax_rate_percent' => 0.0,
                ],
            ]
        );

        $checkoutToken = 'token_race_condition_test_999';
        $payment = $this->paymentService->recordPayment([
            'user_id' => 42,
            'invoice_id' => $invoice->getId(),
            'amount_minor' => 25000,
            'currency_code' => 'TRY',
            'payment_method' => 'iyzico',
            'status' => Payment::STATUS_PENDING,
            'metadata' => [
                'checkout_token' => $checkoutToken,
            ],
        ]);

        $config = new IyzicoConfiguration(
            apiKey: 'key_mock',
            secretKey: 'sec_mock',
            baseUrl: 'https://mock.iyzipay.com',
            isTestMode: true
        );

        $gatewayVerificationCalls = 0;
        $mockHttpClient = function (string $url, string $payload, array $headers) use ($payment, $invoice, &$gatewayVerificationCalls): array {
            $gatewayVerificationCalls++;
            return [
                'code' => 200,
                'body' => (string) json_encode([
                    'status' => 'success',
                    'paymentStatus' => 'SUCCESS',
                    'paymentId' => 'iyz_pay_race_777',
                    'conversationId' => $payment->getPaymentNumber(),
                    'paidPrice' => '250.00',
                    'currency' => 'TRY',
                    'cardAssociation' => 'MASTERCARD',
                    'cardFamily' => 'Bonus',
                    'installment' => 1,
                    'itemTransactions' => [
                        [
                            'paymentTransactionId' => 'txn_race_111',
                            'itemId' => 'inv_' . $invoice->getId(),
                            'paidPrice' => '250.00',
                        ],
                    ],
                ]),
            ];
        };

        $gateway = new IyzicoPaymentGateway($config, httpClient: $mockHttpClient);
        $payload = ['token' => $checkoutToken, 'conversationId' => $payment->getPaymentNumber()];

        // Webhook Delivery 1: Primary notification arrives
        $result1 = $this->webhookHandler->handleIyzicoCallback($gateway, $payload);
        $this->assertTrue($result1->isProcessed());
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, $result1->getStatus());

        // Verify invoice and order state after Delivery 1
        $refreshedInvoice1 = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertNotNull($refreshedInvoice1);
        $this->assertTrue($refreshedInvoice1->isPaid());
        $this->assertSame(0, $refreshedInvoice1->getBalanceDueMinor());

        $refreshedOrder1 = $this->orderService->findOrderById($order->getId());
        $this->assertNotNull($refreshedOrder1);
        $this->assertSame(OrderStateMachine::STATUS_ACTIVE, $refreshedOrder1->getStatus());

        // Webhook Delivery 2: Concurrent/Replayed webhook notification arrives with identical token
        $result2 = $this->webhookHandler->handleIyzicoCallback($gateway, $payload);
        $this->assertFalse($result2->isProcessed());
        $this->assertTrue($result2->isDuplicate());
        $this->assertStringContainsString('idempotent duplicate', $result2->getMessage());

        // Invariant: Remote gateway verification was called only once; duplicate was absorbed locally
        $this->assertSame(1, $gatewayVerificationCalls);

        // Invariant: Total recorded payments for this invoice is strictly 1
        $payments = $this->paymentService->listPaymentsForInvoice($invoice->getId());
        $this->assertCount(1, $payments);
        $this->assertSame(Payment::STATUS_COMPLETED, $payments[0]->getStatus());
    }

    /**
     * Scenario 2: Concurrent Credit Ledger Debit Competition & Negative Balance Prevention.
     */
    public function testConcurrentCreditLedgerDebitCompetition(): void
    {
        $userId = 88;
        $currency = 'USD';

        // Customer deposits $100.00
        $this->creditService->addCredit(
            userId: $userId,
            amountMinor: 10000,
            currencyCode: $currency,
            reason: 'Account pre-funding'
        );

        $initialBalance = $this->creditService->getBalance($userId, $currency);
        $this->assertSame(10000, $initialBalance);

        // Process A tries to debit $70.00 protected by lock
        $lockKey = "credit:user:{$userId}";
        $workerASucceeded = false;
        $workerBSucceeded = false;
        $workerBFailureReason = '';

        // Worker A executes debit
        $this->lock->synchronized($lockKey, function () use ($userId, $currency, &$workerASucceeded) {
            $this->creditService->deductCredit(
                userId: $userId,
                amountMinor: 7000,
                currencyCode: $currency,
                reason: 'Order #A100 Settlement'
            );
            $workerASucceeded = true;
        });
        $this->assertTrue($workerASucceeded);

        // Balance after Worker A is $30.00
        $balanceAfterA = $this->creditService->getBalance($userId, $currency);
        $this->assertSame(3000, $balanceAfterA);

        // Worker B attempts to debit $70.00 concurrently for Order #B200
        try {
            $this->lock->synchronized($lockKey, function () use ($userId, $currency, &$workerBSucceeded) {
                $this->creditService->deductCredit(
                    userId: $userId,
                    amountMinor: 7000,
                    currencyCode: $currency,
                    reason: 'Order #B200 Settlement'
                );
                $workerBSucceeded = true;
            });
        } catch (ValidationException $e) {
            $workerBFailureReason = $e->getMessage();
        }

        // Invariant: Worker B failed due to insufficient funds; balance never went negative
        $this->assertFalse($workerBSucceeded);
        $this->assertStringContainsString('Insufficient credit', $workerBFailureReason);

        $finalBalance = $this->creditService->getBalance($userId, $currency);
        $this->assertSame(3000, $finalBalance);
        $this->assertGreaterThanOrEqual(0, $finalBalance);
    }

    /**
     * Scenario 3: Server Placement & Capacity Contention Race Condition.
     */
    public function testServerPlacementAndCapacityRaceConditionUnderContention(): void
    {
        // Server with maximum capacity of 2 accounts, 1 already used. Exactly 1 slot left.
        $server = $this->serverService->createServer([
            'name' => 'Edge-Node-01',
            'hostname' => 'edge01.host.local',
            'ip_address' => '192.168.10.1',
            'provider_slug' => 'cpanel',
            'max_accounts' => 2,
            'used_accounts' => 1,
            'disk_capacity_mb' => 20000,
            'disk_used_mb' => 5000,
            'bandwidth_capacity_mb' => 100000,
            'bandwidth_used_mb' => 10000,
        ]);

        $serverId = $server->getId();

        // 1. Worker 1 reserves the last remaining slot
        $res1 = $this->reservationService->reserve(
            serverId: $serverId,
            accountsCount: 1,
            diskMb: 1000,
            bandwidthMb: 2000,
            serviceId: 501,
            orderId: 601
        );

        $this->assertTrue($res1->isReserved());
        $this->assertSame(CapacityReservation::STATUS_RESERVED, $res1->getStatus());

        // Server usage should now be at maximum (2/2)
        $serverAfterRes1 = $this->serverService->findServerById($serverId);
        $this->assertNotNull($serverAfterRes1);
        $this->assertSame(2, $serverAfterRes1->getCapacity()->getUsedAccounts());
        $this->assertFalse($serverAfterRes1->getCapacity()->hasAccountHeadroom(1));

        // 2. Worker 2 attempts concurrent reservation on the exact same server
        $worker2FailedWithReservationException = false;
        try {
            $this->reservationService->reserve(
                serverId: $serverId,
                accountsCount: 1,
                diskMb: 1000,
                bandwidthMb: 2000,
                serviceId: 502,
                orderId: 602
            );
        } catch (ReservationException $e) {
            $worker2FailedWithReservationException = true;
            $this->assertTrue(
                str_contains($e->getMessage(), 'not active') || str_contains($e->getMessage(), 'exceeded')
            );
        }

        $this->assertTrue($worker2FailedWithReservationException);

        // 3. Placement Engine evaluation verifies the server is rejected for any new service
        $placementReq = new PlacementRequest(
            serviceId: 503,
            productId: 1,
            providerSlug: 'cpanel',
            requiredDiskMb: 1000,
            requiredBandwidthMb: 2000
        );
        $decision = $this->placementEngine->selectServer($placementReq);

        $this->assertFalse($decision->isSuccessful());
        $this->assertNull($decision->getSelectedServer());
        $reasons = $decision->getRejectionReasons();
        $this->assertArrayHasKey($serverId, $reasons);

        // 4. Invariant: Used accounts never exceeds max accounts (remains exactly 2)
        $finalServer = $this->serverService->findServerById($serverId);
        $this->assertNotNull($finalServer);
        $this->assertSame(2, $finalServer->getCapacity()->getUsedAccounts());
        $this->assertLessThanOrEqual($finalServer->getCapacity()->getMaxAccounts(), $finalServer->getCapacity()->getUsedAccounts());
    }

    /**
     * Scenario 4: Provider 429 Rate Limiting, Exponential Backoff, & Jitter Bounds.
     */
    public function testProvider429RateLimitExponentialBackoffAndJitterBounds(): void
    {
        $policyWithJitter = new ProvisioningRetryPolicy(
            baseDelaySeconds: 10,
            multiplier: 2.0,
            maxDelaySeconds: 120,
            jitterFactor: 0.1, // +/- 10%
            maxAttempts: 4
        );

        // Attempt 1: base = 10s -> range [10, 11]
        $delay1 = $policyWithJitter->calculateDelaySeconds(1, applyJitter: true);
        $this->assertGreaterThanOrEqual(10, $delay1);
        $this->assertLessThanOrEqual(12, $delay1);

        // Attempt 2: base = 20s -> range [20, 22]
        $delay2 = $policyWithJitter->calculateDelaySeconds(2, applyJitter: true);
        $this->assertGreaterThanOrEqual(20, $delay2);
        $this->assertLessThanOrEqual(24, $delay2);

        // Attempt 3: base = 40s -> range [40, 44]
        $delay3 = $policyWithJitter->calculateDelaySeconds(3, applyJitter: true);
        $this->assertGreaterThanOrEqual(40, $delay3);
        $this->assertLessThanOrEqual(48, $delay3);

        // Deterministic check without jitter:
        $this->assertSame(10, $policyWithJitter->calculateDelaySeconds(1, applyJitter: false));
        $this->assertSame(20, $policyWithJitter->calculateDelaySeconds(2, applyJitter: false));
        $this->assertSame(40, $policyWithJitter->calculateDelaySeconds(3, applyJitter: false));
        $this->assertSame(80, $policyWithJitter->calculateDelaySeconds(4, applyJitter: false));

        // Max delay cap test (120)
        $delay5 = $policyWithJitter->calculateDelaySeconds(5, applyJitter: false);
        $this->assertSame(120, $delay5);

        // Max attempts verification
        $rateLimitClass = ProvisioningErrorClassification::rateLimited('Rate limited', 60);
        $this->assertTrue($policyWithJitter->isRetryable($rateLimitClass, 1));
        $this->assertTrue($policyWithJitter->isRetryable($rateLimitClass, 2));
        $this->assertTrue($policyWithJitter->isRetryable($rateLimitClass, 3));
        $this->assertFalse($policyWithJitter->isRetryable($rateLimitClass, 4));
        $this->assertFalse($policyWithJitter->isRetryable($rateLimitClass, 5));
    }

    /**
     * Scenario 5: Error Classification: Transient Network vs Permanent Rejected.
     */
    public function testTransientVsPermanentErrorClassificationChaos(): void
    {
        $classifier = new ProvisioningErrorClassifier();

        // Transient errors must be classified as TRANSIENT_NETWORK and retryable
        $transientCases = [
            '429 Too Many Requests from cPanel server' => 429,
            'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received' => 504,
            '502 Bad Gateway: Upstream proxy connection reset' => 502,
            '503 Service Unavailable: Server busy' => 503,
            '504 Gateway Time-out' => 504,
        ];

        foreach ($transientCases as $message => $httpCode) {
            $classification = $classifier->classify(
                message: $message,
                errorCode: 'HTTP_' . $httpCode,
                httpStatusCode: $httpCode
            );
            $this->assertTrue(
                $classification->isRetryable(),
                "Expected retryable=true for '{$message}'"
            );
        }

        // Permanent errors must be classified as non-retryable categories (AUTHENTICATION, VALIDATION, etc.)
        $permanentCases = [
            '401 Unauthorized: Invalid access token or WHM credentials' => 401,
            '403 Forbidden: Insufficient reseller privileges' => 403,
            'Package "unlimited_gold" does not exist on target node' => 404,
            'Domain example.com is already configured on this server' => 422,
        ];

        foreach ($permanentCases as $message => $httpCode) {
            $classification = $classifier->classify(
                message: $message,
                errorCode: 'HTTP_' . $httpCode,
                httpStatusCode: $httpCode
            );
            $this->assertFalse(
                $classification->isRetryable(),
                "Expected retryable=false for '{$message}'"
            );
        }
    }

    /**
     * Scenario 6: Domain Registrar Socket Timeout Uncertainty & Batch Reconciliation.
     */
    public function testDomainRegistrarNetworkTimeoutUncertaintyReconciliation(): void
    {
        $catalog = new DomainCatalogService($this->db);
        $catalog->ensureTables();
        $catalog->registerTld([
            'extension' => '.com',
            'is_active' => 1,
            'registrar_id' => 'chaos_registrar',
        ]);

        $domainService = new DomainService($this->db, $catalog);
        $domainService->ensureTables();

        $registry = new RegistrarRegistry();
        $operationRepo = new DomainOperationRepository($this->db);
        $operationRepo->ensureTable();

        $registrarService = new DomainRegistrarService($domainService, $registry, $catalog);
        $idempotentService = new IdempotentDomainOperationService($operationRepo, $registrarService, $domainService);
        $reconciliationService = new DomainOperationReconciliationService($operationRepo, $domainService, $registry);

        $domain = $domainService->createDomain([
            'user_id' => 10,
            'domain' => 'chaos-network-test.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'chaos_registrar',
            'registration_period_years' => 1,
        ]);

        $registerAttempts = 0;
        $mockRegistrar = new class($registerAttempts) implements RegistrarProviderInterface {
            public int $registerAttempts;
            public array $nameservers = [];

            public function __construct(int &$registerAttempts)
            {
                $this->registerAttempts = &$registerAttempts;
            }

            public function getRegistrarId(): string
            {
                return 'chaos_registrar';
            }

            public function getName(): string
            {
                return 'Chaos Registrar';
            }

            public function supportsCapability(string $capability): bool
            {
                return true;
            }

            public function getSupportedCapabilities(): array
            {
                return RegistrarCapability::all();
            }

            public function checkAvailability(string $domain): DomainAvailabilityResult
            {
                return empty($this->nameservers)
                    ? DomainAvailabilityResult::available($domain)
                    : DomainAvailabilityResult::unavailable($domain, 'Taken');
            }

            public function registerDomain(DomainRegistrationCommand $command): RegistrarOperationResult
            {
                $this->registerAttempts++;
                // Simulate upstream socket timeout
                throw new RuntimeException('Connection timed out after 30000ms: remote registrar socket dropped.');
            }

            public function renewDomain(\Coleza\Domain\Domains\Registrar\DomainRenewalCommand $command): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('renew', $command->getDomain(), 'DEF-REN');
            }

            public function transferDomain(\Coleza\Domain\Domains\Registrar\DomainTransferCommand $command): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('transfer', $command->getDomain(), 'DEF-TRF');
            }

            public function getNameservers(string $domain): array
            {
                return $this->nameservers;
            }

            public function updateNameservers(string $domain, array $nameservers): RegistrarOperationResult
            {
                $this->nameservers = $nameservers;
                return RegistrarOperationResult::success('update_nameservers', $domain);
            }

            public function getRegistrarLock(string $domain): bool
            {
                return false;
            }

            public function setRegistrarLock(string $domain, bool $locked): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('set_lock', $domain);
            }

            public function getEppCode(string $domain): ?string
            {
                return 'EPP-CHAOS';
            }

            public function getContacts(string $domain): array
            {
                return [];
            }

            public function updateContacts(string $domain, array $contacts): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('update_contacts', $domain);
            }
        };

        $registry->register($mockRegistrar);

        // 1. Initial attempt: upstream network times out
        $key = 'idemp_key_chaos_timeout_001';
        $result = $idempotentService->executeRegister(
            domainId: $domain->getId(),
            idempotencyKey: $key
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('UNCERTAIN_TIMEOUT', $result->getErrorCode());
        $this->assertSame(1, $mockRegistrar->registerAttempts);

        // Verify operation is flagged as UNCERTAIN in the repository
        $ops = $operationRepo->listUncertainOperations();
        $this->assertCount(1, $ops);
        $this->assertTrue($ops[0]->isUncertain());

        // Domain locally is still pending registration
        $localDomain = $domainService->findDomainById($domain->getId());
        $this->assertNotNull($localDomain);
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $localDomain->getStatus());

        // 2. Scheduled Reconciliation Job executes
        // Remote query finds nameservers registered upstream (meaning the registration actually took place upstream before the socket dropped)
        $mockRegistrar->nameservers = ['ns1.chaosdns.com', 'ns2.chaosdns.com'];
        $reconciledOperations = $reconciliationService->reconcileAllUncertain();

        $this->assertCount(1, $reconciledOperations);
        $this->assertSame(DomainOperation::STATUS_SUCCEEDED, $reconciledOperations[0]->getStatus());

        // 3. Invariants verified:
        // - No duplicate registration command was issued to the registrar (registerAttempts is still strictly 1)
        $this->assertSame(1, $mockRegistrar->registerAttempts);

        // - Domain is settled to ACTIVE locally
        $reconciledDomain = $domainService->findDomainById($domain->getId());
        $this->assertNotNull($reconciledDomain);
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $reconciledDomain->getStatus());

        // - Operation status in uncertain pool is cleared
        $remainingUncertainOps = $operationRepo->listUncertainOperations();
        $this->assertCount(0, $remainingUncertainOps);
    }

    /**
     * Scenario 7: Missed Scheduler Catchup & Distributed Worker Collision Prevention.
     */
    public function testMissedSchedulerCatchupDistributedWorkerCollisionPrevention(): void
    {
        $renewalInvoiceService = new RenewalInvoiceService($this->db, $this->invoiceService);
        $renewalInvoiceService->ensureTables();

        $renewalScheduler = new ServiceRenewalScheduler(
            serviceService: $this->serviceService,
            renewalInvoiceService: $renewalInvoiceService
        );

        $overdueWorkflow = new OverdueLifecycleWorkflow(
            serviceService: $this->serviceService,
            invoiceService: $this->invoiceService
        );

        $catchupService = new MissedSchedulerCatchupService(
            db: $this->db,
            renewalScheduler: $renewalScheduler,
            overdueWorkflow: $overdueWorkflow
        );
        $catchupService->ensureTables();

        // Initialize checkpoint at 2026-10-01
        $catchupService->recordCheckpoint('cron_daily', '2026-10-01');

        $renewalPolicy = new RenewalPolicy();
        $gracePolicy = new OverdueGracePolicy();
        $resourceKey = 'scheduler:catchup:cron_daily';

        $workerAProcessedDays = 0;

        // Worker A acquires lock and performs catchup for 3 days missed (Oct 02, 03, 04)
        $lockAcquiredA = $this->lock->acquire($resourceKey, ttlSeconds: 30, owner: 'worker_node_alpha');
        $this->assertTrue($lockAcquiredA);

        if ($lockAcquiredA) {
            $reportA = $catchupService->catchup(
                schedulerName: 'cron_daily',
                currentDate: '2026-10-04',
                renewalPolicy: $renewalPolicy,
                gracePolicy: $gracePolicy
            );
            $workerAProcessedDays = $reportA->getMissedDaysCount();
            $this->lock->release($resourceKey, owner: 'worker_node_alpha');
        }

        $this->assertSame(3, $workerAProcessedDays);
        $this->assertSame('2026-10-04', $catchupService->getLastCheckpoint('cron_daily'));

        // Worker B attempts to run immediately after. Checkpoint is up to date, 0 days to catchup.
        $reportB = $catchupService->catchup(
            schedulerName: 'cron_daily',
            currentDate: '2026-10-04',
            renewalPolicy: $renewalPolicy,
            gracePolicy: $gracePolicy
        );

        $this->assertSame(0, $reportB->getMissedDaysCount());
    }

    /**
     * Scenario 8: Service State Machine Optimistic Concurrency Control.
     */
    public function testServiceOptimisticConcurrencyControlUnderStateMismatches(): void
    {
        $service = $this->serviceService->createService([
            'user_id' => 15,
            'product_id' => 2,
            'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => PriceCycle::MONTHLY,
            'recurring_amount_minor' => 2000,
            'currency_code' => 'USD',
            'registration_date' => '2026-10-01',
            'next_due_date' => '2026-11-01',
            'domain' => 'concurrency-chaos.com',
            'username' => 'chaosuser',
        ]);

        $this->assertSame(1, $service->getLockVersion());

        // Process 1 updates notes with expectedLockVersion: 1 -> increments to 2
        $updated1 = $this->serviceService->updateService(
            $service->getId(),
            ['notes' => 'Worker 1 updated provision notes'],
            expectedLockVersion: 1
        );
        $this->assertSame(2, $updated1->getLockVersion());

        // Process 2 (which read service concurrently at version 1) attempts update with stale version 1
        $this->expectException(ServiceConcurrencyException::class);
        $this->expectExceptionMessage('could not be updated due to a concurrent modification');

        $this->serviceService->updateService(
            $service->getId(),
            ['notes' => 'Worker 2 stale update attempt'],
            expectedLockVersion: 1
        );
    }
}
