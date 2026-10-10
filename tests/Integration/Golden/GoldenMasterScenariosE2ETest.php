<?php

declare(strict_types=1);

namespace Coleza\Tests\Integration\Golden;

use Coleza\Domain\Backup\BackupManifest;
use Coleza\Domain\Backup\BackupScope;
use Coleza\Domain\Backup\EnterpriseBackupService;
use Coleza\Domain\Backup\RestoreResult;
use Coleza\Domain\Backup\RestoreWizardService;
use Coleza\Domain\Backup\Storage\LocalBackupStorageAdapter;
use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Recurring\Scheduler\RenewalPolicy;
use Coleza\Domain\Commerce\Recurring\Scheduler\ServiceRenewalScheduler;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueGracePolicy;
use Coleza\Domain\Commerce\Services\Lifecycle\OverdueLifecycleWorkflow;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\Catalog\TldPricing;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Registrar\Adapters\NameSilo\NameSiloRegistrarAdapter;
use Coleza\Domain\Domains\Registrar\DomainRegistrarService;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperation;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository;
use Coleza\Domain\Domains\Registrar\Reconciliation\IdempotentDomainOperationService;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Domain\Domains\Registrar\Vault\RegistrarConfiguration;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Finance\Fx\StaticFxRateProvider;
use Coleza\Domain\Health\HealthStatus;
use Coleza\Domain\Health\SystemDoctorService;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\OperationalMode\OperationalMode;
use Coleza\Domain\OperationalMode\OperationalModeManager;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Services\PricingService;
use Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService;
use Coleza\Domain\Privacy\Tombstone\FileTombstoneStore;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorCategory;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Coordinators\AutomatedHostingOrderCoordinator;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService;
use Coleza\Domain\Provisioning\Retry\ProvisioningRetryPolicy;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Services\ProvisioningRetryRunner;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Domain\Updater\RollbackManager;
use Coleza\Foundation\Database\Connection;
use Coleza\Tests\Unit\Migration\CutoverAndGoldenMigrationE2ETest;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Golden E2E Master Suite (P18.2).
 * Formally executes and certifies all 8 Golden Scenarios (G01–G08)
 * as defined in 05-testing/GOLDEN_SCENARIOS.md.
 */
final class GoldenMasterScenariosE2ETest extends TestCase
{
    private string $tempWorkDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempWorkDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'golden_master_' . bin2hex(random_bytes(6));
        mkdir($this->tempWorkDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempWorkDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createDb(): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        return new Connection($pdo, 'sqlite');
    }

    // =========================================================================
    // G01: Manual Commerce: Organization→Product→Order→Invoice→Manual Payment→Manual Service
    // =========================================================================
    public function testG01ManualCommerceScenario(): void
    {
        $db = $this->createDb();

        // 1. Currency & Pricing
        $fxProvider = new StaticFxRateProvider();
        $fxProvider->setRate('USD', 'TRY', 34.00);
        $currencyService = new CurrencyService($db);
        $currencyService->registerProvider($fxProvider);
        $currencyService->ensureTables();
        $currencyService->createCurrency([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'minor_units' => 2,
            'is_default' => true,
        ]);

        $taxService = new TaxService($db);
        $taxService->ensureTables();
        $taxClass = $taxService->createTaxClass(['code' => 'standard', 'name' => 'Standard Rate', 'is_default' => true]);
        $taxZone = $taxService->createTaxZone(['code' => 'TR', 'name' => 'Turkey Zone', 'country_codes' => ['TR']]);
        $taxService->createTaxRate([
            'tax_class_id' => $taxClass->getId(),
            'tax_zone_id' => $taxZone->getId(),
            'name' => 'KDV %20',
            'rate_percent' => 20.0,
        ]);

        $pricingService = new PricingService($db);
        $pricingService->ensureTables();
        $pricingService->setPricePoint([
            'target_type' => PricePoint::TARGET_PRODUCT,
            'target_id' => 101,
            'currency_code' => 'TRY',
            'cycle' => PriceCycle::MONTHLY,
            'price_minor' => 10000, // 100.00 TRY
            'setup_fee_minor' => 2000, // 20.00 TRY
        ]);

        // 2. Order Subsystem
        $orderService = new OrderService($db, $pricingService, $taxService);
        $orderService->ensureTables();

        $invoiceService = new InvoiceService($db);
        $invoiceService->ensureTables();

        $paymentService = new PaymentService($db, $invoiceService);
        $paymentService->ensureTables();

        $serviceService = new ServiceService($db);
        $serviceService->ensureTables();

        // Step 1: Create Organization & Order with Items
        $order = $orderService->createOrder(
            orderData: [
                'user_id' => 501,
                'organization_id' => 10,
                'currency_code' => 'TRY',
                'country_code' => 'TR',
                'notes' => 'G01 Manual Commerce Order',
            ],
            itemsData: [
                [
                    'product_id' => 101,
                    'product_name' => 'Dedicated Managed Hosting',
                    'cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price_minor' => 10000,
                    'unit_setup_fee_minor' => 2000,
                ],
            ]
        );

        $this->assertSame(OrderStateMachine::STATUS_PENDING_PAYMENT, $order->getStatus());
        $this->assertGreaterThan(0, $order->getTotalMinor());

        // Step 2: Generate Invoice from Order
        $invoice = $invoiceService->createInvoiceFromOrder($order);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());
        $this->assertSame($order->getTotalMinor(), $invoice->getTotalMinor());

        // Step 3: Record Manual Payment (Bank Transfer)
        $payment = $paymentService->recordPayment([
            'invoice_id' => $invoice->getId(),
            'user_id' => 501,
            'amount_minor' => $invoice->getTotalMinor(),
            'currency_code' => 'TRY',
            'gateway' => 'banktransfer',
            'transaction_reference' => 'TXN-BANK-G01-9988',
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());

        // Check invoice marked PAID
        $paidInvoice = $invoiceService->findInvoiceById((int) $invoice->getId());
        $this->assertNotNull($paidInvoice);
        $this->assertSame(Invoice::STATUS_PAID, $paidInvoice->getStatus());

        // Step 4: Provision Manual Service and Activate
        $service = $serviceService->createService([
            'user_id' => 501,
            'organization_id' => 10,
            'product_id' => 101,
            'domain' => 'manual-client.org',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 10000,
            'currency_code' => 'TRY',
            'status' => ServiceStateMachine::STATUS_PENDING,
            'next_due_date' => date('Y-m-d', strtotime('+30 days')),
        ]);

        $activatedService = $serviceService->activateService((int) $service->getId());
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $activatedService->getStatus());
        $this->assertSame('manual-client.org', $activatedService->getDomain());
    }

    // =========================================================================
    // G02: Automated Hosting: Client→Order→Risk→Invoice→iyzico→Webhook→Queue→Placement→cPanel→Active→Email
    // =========================================================================
    public function testG02AutomatedHostingScenario(): void
    {
        $db = $this->createDb();

        $orderService = new OrderService($db);
        $orderService->ensureTables();

        $invoiceService = new InvoiceService($db);
        $invoiceService->ensureTables();

        $paymentService = new PaymentService($db, $invoiceService);
        $paymentService->ensureTables();

        $serviceService = new ServiceService($db);
        $serviceService->ensureTables();

        $serverService = new ServerService($db);
        $serverService->ensureTables();

        $reservationService = new CapacityReservationService($db, $serverService);
        $reservationService->ensureTables();

        $placementEngine = new PlacementEngine($serverService);

        $operationService = new ProvisioningOperationService($db);
        $operationService->ensureTables();

        $cpanelTransport = new CpanelMemoryTransport();
        $cpanelProvider = new CpanelProvider($cpanelTransport);
        $providerRegistry = new ProviderRegistry([$cpanelProvider]);

        $workflow = new HostingProvisioningWorkflow(
            serviceService: $serviceService,
            serverService: $serverService,
            placementEngine: $placementEngine,
            reservationService: $reservationService,
            providerRegistry: $providerRegistry,
            operationService: $operationService
        );

        $mailTransport = new MemoryMailTransport();
        $notificationEngine = new NotificationEngine(mailer: $mailTransport);

        $coordinator = new AutomatedHostingOrderCoordinator(
            serviceService: $serviceService,
            workflow: $workflow,
            notificationEngine: $notificationEngine,
            orderService: $orderService
        );

        // Server setup with authoritative parameters
        $server = $serverService->createServer([
            'name' => 'cPanel Production EU-01',
            'hostname' => 'whm.eu01.colezahost.com',
            'ip_address' => '185.120.40.10',
            'provider_slug' => 'cpanel',
            'max_accounts' => 1000,
            'used_accounts' => 50,
            'disk_capacity_mb' => 20000000,
            'bandwidth_capacity_mb' => 100000000,
            'auth_type' => 'api_token',
            'auth_secret' => 'SECRET_WHM_PROD_TOKEN',
            'status' => 'active',
        ]);

        // Stage WHM endpoints
        $cpanelTransport->stageResponse('listpkgs', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'pkg' => [
                    [
                        'name' => 'cloud_starter',
                        'QUOTA' => '10240',
                        'BWLIMIT' => '102400',
                    ],
                ],
            ],
        ]);

        $cpanelTransport->stageResponse('createacct', 200, [
            'metadata' => [
                'result' => 1,
                'reason' => 'Account Creation Ok',
                'version' => 1,
            ],
            'data' => [
                'ip' => '185.120.40.22',
                'nameserver' => 'ns1.colezahost.com',
                'nameserver2' => 'ns2.colezahost.com',
                'package' => 'cloud_starter',
            ],
        ]);

        $cpanelTransport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'goldensite',
                        'domain' => 'goldensite.com',
                        'plan' => 'cloud_starter',
                        'ip' => '185.120.40.22',
                    ],
                ],
            ],
        ]);

        // Place and fulfill automated order
        $order = $orderService->createOrder(
            orderData: [
                'user_id' => 777,
                'currency_code' => 'TRY',
                'notes' => 'G02 Automated Hosting Order',
            ],
            itemsData: [
                [
                    'product_id' => 101,
                    'product_name' => 'cPanel Cloud Starter Hosting',
                    'cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price_minor' => 14900,
                    'unit_setup_fee_minor' => 0,
                    'metadata' => [
                        'domain' => 'goldensite.com',
                        'username' => 'goldensite',
                        'package_identifier' => 'cloud_starter',
                        'disk_limit_mb' => 10240,
                        'bandwidth_limit_mb' => 102400,
                    ],
                ],
            ]
        );

        $invoice = $invoiceService->createInvoiceFromOrder($order);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());

        // Process webhook / payment callback
        $payment = $paymentService->recordPayment([
            'user_id' => 777,
            'payment_method' => 'iyzico',
            'amount_minor' => 14900,
            'currency_code' => 'TRY',
            'invoice_id' => $invoice->getId(),
            'transaction_reference' => 'IYZICO_G02_HOOK_4455',
            'status' => Payment::STATUS_COMPLETED,
            'metadata' => ['gateway' => 'iyzico'],
        ]);
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());

        // Coordinate automated provisioning
        $coordResult = $coordinator->processPaidOrder(
            order: $order,
            customerEmail: 'client@goldensite.com',
            customerName: 'Ahmet Yilmaz',
            customerLocale: 'tr'
        );

        $this->assertTrue($coordResult['all_successful']);
        $this->assertSame(1, $coordResult['services_created']);
        $this->assertCount(1, $coordResult['provisioned_services']);

        $serviceOutcome = $coordResult['provisioned_services'][0];
        $this->assertTrue($serviceOutcome['result']->isSuccess());
        $this->assertTrue($serviceOutcome['notification_sent']);

        // Verify service state
        $service = $serviceService->findServiceById($serviceOutcome['service_id']);
        $this->assertNotNull($service);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $service->getStatus());
        $this->assertSame('goldensite', $service->getUsername());
        $this->assertSame('goldensite.com', $service->getDomain());

        // Confirm Welcome Email dispatched
        $lastMail = $mailTransport->findLastByRecipient('client@goldensite.com');
        $this->assertNotNull($lastMail);
        $this->assertStringContainsString('goldensite.com', $lastMail->getSubject());
    }

    // =========================================================================
    // G03: Renewal: Scheduler→Renewal Invoice→Notification→Payment OR overdue/grace→Suspend
    // =========================================================================
    public function testG03RenewalLifecycleScenario(): void
    {
        $db = $this->createDb();

        $serviceService = new ServiceService($db);
        $serviceService->ensureTables();

        $invoiceService = new InvoiceService($db);
        $invoiceService->ensureTables();

        $renewalInvoiceService = new RenewalInvoiceService($db, $invoiceService);
        $renewalInvoiceService->ensureTables();

        $mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $notificationEngine = new NotificationEngine($mailTransport, $templateEngine);

        $renewalScheduler = new ServiceRenewalScheduler(
            serviceService: $serviceService,
            renewalInvoiceService: $renewalInvoiceService,
            notificationEngine: $notificationEngine
        );

        $overdueWorkflow = new OverdueLifecycleWorkflow(
            serviceService: $serviceService,
            invoiceService: $invoiceService,
            notificationEngine: $notificationEngine
        );

        $service = $serviceService->createService([
            'user_id' => 888,
            'organization_id' => 1,
            'product_id' => 303,
            'domain' => 'client-golden.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 4500,
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_ACTIVE,
            'next_due_date' => '2026-11-01',
            'auto_renew' => 1,
            'metadata' => [
                'customer_email' => 'founder@client-golden.com',
                'plan_name' => 'Pro Cloud VPS',
            ],
        ]);
        $serviceId = (int) $service->getId();

        $renewalPolicy = new RenewalPolicy(leadDays: 14, invoiceDueDays: 14);
        $gracePolicy = new OverdueGracePolicy(gracePeriodDays: 7, reminderDays: [1, 3, 5], terminationGraceDays: 30);

        // Advance time to 2026-10-18 (14 days lead) -> Trigger Renewal
        $batch = $renewalScheduler->run($renewalPolicy, '2026-10-18');
        $this->assertSame(1, $batch->getGeneratedCount());
        $genResult = $batch->getResults()[0];
        $this->assertTrue($genResult->isGenerated());
        $invoice = $genResult->getInvoice();
        $this->assertNotNull($invoice);
        $this->assertSame(4500, $invoice->getTotalMinor());

        // Verify customer received renewal invoice notification
        $sentEmail = $mailTransport->findLastByRecipient('founder@client-golden.com');
        $this->assertNotNull($sentEmail);
        $this->assertStringContainsString($invoice->getInvoiceNumber(), $sentEmail->getHtmlBody());

        // Advance time to 2026-11-08 (7 days overdue) -> Auto-suspend
        $mailTransport->clear();
        $overdueReport = $overdueWorkflow->evaluateAndProcessOverdue($gracePolicy, '2026-11-08');
        $this->assertSame(1, $overdueReport->getSuspendedCount());

        $svcSuspended = $serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $svcSuspended->getStatus());

        // Payment settlement -> Auto-reactivate and cycle advance
        $mailTransport->clear();
        $invoiceService->applyPayment($invoice->getId(), 4500);

        $actions = $overdueWorkflow->handleInvoicePaid($invoice->getId(), $gracePolicy);
        $this->assertCount(2, $actions); // unsuspended + renewed

        $svcReactivated = $serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $svcReactivated->getStatus());
        $this->assertSame('2026-12-01', $svcReactivated->getNextDueDate()); // advanced by 1 month!
    }

    // =========================================================================
    // G04: Domain: Availability→Order→Payment→Registrar Register→Active→Reminder→Renewal
    // =========================================================================
    public function testG04DomainLifecycleScenario(): void
    {
        $db = $this->createDb();

        $catalogService = new DomainCatalogService($db);
        $catalogService->ensureTables();

        $tldCom = $catalogService->registerTld([
            'extension' => '.com',
            'is_active' => 1,
            'registrar_id' => 'namesilo',
            'grace_period_days' => 30,
            'redemption_period_days' => 30,
        ]);

        $catalogService->setTldPricing(
            tldId: $tldCom->getId(),
            operation: TldPricing::OPERATION_REGISTER,
            years: 1,
            priceMinor: 1299,
            currencyCode: 'USD'
        );

        $catalogService->setTldPricing(
            tldId: $tldCom->getId(),
            operation: TldPricing::OPERATION_RENEW,
            years: 1,
            priceMinor: 1499,
            currencyCode: 'USD'
        );

        $domainService = new DomainService($db, $catalogService);
        $domainService->ensureTables();

        $operationRepo = new DomainOperationRepository($db);
        $operationRepo->ensureTable();

        $vaultConfig = new RegistrarConfiguration(
            registrarId: 'namesilo',
            apiKey: 'SECRET-API-KEY-999',
            apiSecret: 'SECRET-HASH-888',
            endpoint: 'https://sandbox.namesilo.com/api',
            isSandbox: true
        );

        $namesiloAdapter = new NameSiloRegistrarAdapter($vaultConfig, function (string $operation, array $params) {
            return match ($operation) {
                'checkRegisterAvailability' => [
                    'reply' => [
                        'code' => 300,
                        'detail' => 'success',
                        'available' => [
                            'domain' => [
                                '@attributes' => ['price' => '12.99'],
                            ],
                        ],
                    ],
                ],
                'registerDomain' => [
                    'reply' => [
                        'code' => 300,
                        'detail' => 'success',
                        'order_id' => 'NAMESILO_ORD_8899',
                    ],
                ],
                'renewDomain' => [
                    'reply' => [
                        'code' => 300,
                        'detail' => 'success',
                        'order_id' => 'NAMESILO_ORD_9900',
                    ],
                ],
                default => ['reply' => ['code' => 300, 'detail' => 'success']],
            };
        });

        $registry = new RegistrarRegistry(['namesilo' => $namesiloAdapter]);

        $registrarService = new DomainRegistrarService($domainService, $registry, $catalogService);
        $idempotentService = new IdempotentDomainOperationService($operationRepo, $registrarService, $domainService);

        // Step 1: Availability
        $avail = $namesiloAdapter->checkAvailability('goldenbrand.com');
        $this->assertTrue($avail->isAvailable());

        // Step 2: Create Domain
        $domain = $domainService->createDomain([
            'user_id' => 12,
            'domain' => 'goldenbrand.com',
            'registration_period_years' => 1,
            'whois_privacy' => true,
            'nameservers' => ['ns1.colezahost.com', 'ns2.colezahost.com'],
            'registrar_id' => 'namesilo',
        ]);
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $domain->getStatus());

        $domainService->setContact(
            domainId: $domain->getId(),
            contactType: DomainContact::TYPE_REGISTRANT,
            data: [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company_name' => 'Brand Corp',
                'email' => 'jane@goldenbrand.com',
                'phone' => '+1.5551234567',
                'address_line_1' => '123 Tech Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ]
        );

        // Step 3: Register Domain via Idempotent Service
        $regResult = $idempotentService->executeRegister($domain->getId(), 'IDEMP_G04_REG_01');
        $this->assertTrue($regResult->isSuccessful());

        $activeDomain = $domainService->findDomainById($domain->getId());
        $this->assertNotNull($activeDomain);
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $activeDomain->getStatus());

        // Step 4: Renew Domain
        $renewResult = $idempotentService->executeRenew($domain->getId(), 'IDEMP_G04_REN_01', 1);
        $this->assertTrue($renewResult->isSuccessful());
    }

    // =========================================================================
    // G05: Failure: Payment succeeds, cPanel timeout/uncertain response, retry/reconcile, no duplicate account
    // =========================================================================
    public function testG05FailureAndIdempotencyScenario(): void
    {
        $db = $this->createDb();

        $serviceService = new ServiceService($db);
        $serviceService->ensureTables();

        $serverService = new ServerService($db);
        $serverService->ensureTables();

        $reservationService = new CapacityReservationService($db, $serverService);
        $reservationService->ensureTables();

        $placementEngine = new PlacementEngine($serverService);

        $operationService = new ProvisioningOperationService($db);
        $operationService->ensureTables();

        $transport = new CpanelMemoryTransport();
        $cpanelProvider = new CpanelProvider($transport);
        $providerRegistry = new ProviderRegistry([$cpanelProvider]);

        $workflow = new HostingProvisioningWorkflow(
            serviceService: $serviceService,
            serverService: $serverService,
            placementEngine: $placementEngine,
            reservationService: $reservationService,
            providerRegistry: $providerRegistry,
            operationService: $operationService
        );

        $reconciliationService = new UncertainResponseReconciliationService(
            serverService: $serverService,
            providerRegistry: $providerRegistry,
            serviceService: $serviceService,
            operationService: $operationService
        );

        $retryPolicy = new ProvisioningRetryPolicy();
        $retryRunner = new ProvisioningRetryRunner(
            operationService: $operationService,
            reconciliationService: $reconciliationService,
            provisioningWorkflow: $workflow,
            retryPolicy: $retryPolicy
        );

        $server = $serverService->createServer([
            'name' => 'cPanel Resilience Node',
            'hostname' => 'resilience.colezahost.com',
            'ip_address' => '10.50.0.1',
            'provider_slug' => 'cpanel',
            'status' => 'active',
            'max_accounts' => 50,
            'used_accounts' => 0,
            'disk_capacity_mb' => 20000000,
            'bandwidth_capacity_mb' => 100000000,
            'auth_type' => 'api_token',
            'auth_secret' => 'TOKEN_123',
        ]);

        $service = $serviceService->createService([
            'user_id' => 999,
            'organization_id' => 1,
            'product_id' => 505,
            'domain' => 'resilience-test.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 6000,
            'currency_code' => 'USD',
            'status' => ServiceStateMachine::STATUS_PENDING,
        ]);

        // Error classification of timeout as TRANSIENT_NETWORK
        $classifier = new ProvisioningErrorClassifier();
        $classification = $classifier->classify('Connection timed out after 30000ms');
        $this->assertSame(ProvisioningErrorCategory::TRANSIENT_NETWORK, $classification->getCategory());

        // Queue operation and simulate timeout state (STATUS_RETRYING)
        $op = $operationService->queueOperation(
            serviceId: (int) $service->getId(),
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: [
                'username' => 'reconusr',
                'domain' => 'resilience-test.com',
                'package' => 'starter',
            ],
            serverId: (int) $server->getId()
        );

        $db->statement(
            'UPDATE provisioning_operations SET status = ?, next_attempt_at = ? WHERE id = ?',
            [ProvisioningOperation::STATUS_RETRYING, date('Y-m-d H:i:s', time() - 100), $op->getId()]
        );

        // Remote node query during reconciliation discovers account was created during the timeout
        $transport->stageResponse('accountsummary', 200, [
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => [
                'acct' => [
                    [
                        'user' => 'reconusr',
                        'domain' => 'resilience-test.com',
                        'plan' => 'starter',
                        'ip' => '10.50.0.88',
                    ],
                ],
            ],
        ]);

        // Process due retries: reconciliation prevents duplicate account creation
        $results = $retryRunner->processDueRetries();

        $this->assertCount(1, $results);
        $this->assertSame('reconciled_exists', $results[0]['outcome']);

        // Assert createacct was NEVER called during retry!
        $history = $transport->getRecordedRequests();
        $createCalls = array_filter($history, fn($r) => str_contains($r['url'], 'createacct'));
        $this->assertEmpty($createCalls);
    }

    // =========================================================================
    // G06: Recovery: Backup→intentional broken module/update→Safe/Recovery→Rollback/Restore→Health PASS
    // =========================================================================
    public function testG06RecoveryAndHardeningScenario(): void
    {
        $db = $this->createDb();
        $db->statement("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, status TEXT)");
        $db->statement("INSERT INTO users (id, name, email, status) VALUES (1, 'Founder Admin', 'admin@colezahost.com', 'active')");

        $appDir = $this->tempWorkDir . '/app';
        $backupDir = $this->tempWorkDir . '/backups';
        mkdir($appDir, 0777, true);
        mkdir($backupDir, 0777, true);

        $configFile = $appDir . '/app_config.json';
        file_put_contents($configFile, json_encode(['version' => '1.0.0', 'integrity' => 'VALID']));

        $localAdapter = new LocalBackupStorageAdapter($backupDir);
        $backupService = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir . '/tmp',
            db: $db,
            appVersion: '1.0.0'
        );

        // Step 1: Create Verified Backup
        $backupResult = $backupService->createBackup(
            scope: BackupScope::FULL,
            destination: $localAdapter,
            filePaths: [$configFile]
        );
        $this->assertInstanceOf(BackupManifest::class, $backupResult['manifest']);
        $backupId = $backupResult['manifest']->getBackupId();

        // Step 2: Inject Intentional Broken State & Transition to RECOVERY mode
        file_put_contents($configFile, '{"broken": true, "corrupted_syntax": INVALID_JSON}');
        $db->statement("DELETE FROM users");

        $lockFile = $this->tempWorkDir . '/operational_mode.lock';
        $opManager = new OperationalModeManager($db, $lockFile);
        $opManager->transitionTo(OperationalMode::RECOVERY, 'Intentional failure recovery rehearsal');
        $this->assertSame(OperationalMode::RECOVERY, $opManager->getCurrentMode());

        // Step 3: Execute Rollback / Restore Wizard
        $restoreWizard = new RestoreWizardService($this->tempWorkDir . '/restore_extract');
        $rollbackManager = new RollbackManager($restoreWizard, $localAdapter);

        $restoreResult = $rollbackManager->executeRollback(
            preUpdateBackupId: $backupId,
            targetDb: $db,
            targetAppDir: $appDir
        );
        $this->assertTrue($restoreResult->isSuccess());

        // Confirm database restored
        $users = $db->select("SELECT * FROM users");
        $this->assertCount(1, $users);
        $this->assertSame('Founder Admin', $users[0]['name']);

        // Step 4: Return to NORMAL mode and run System Doctor Health checks
        $opManager->transitionTo(OperationalMode::NORMAL, 'Recovery completed successfully');
        $this->assertSame(OperationalMode::NORMAL, $opManager->getCurrentMode());

        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($db);
        $db->statement("INSERT INTO cron_runs (run_at, duration_ms, status) VALUES (?, 50, 'success')", [date('Y-m-d H:i:s')]);

        $doctor = new SystemDoctorService(
            db: $db,
            storageBasePath: $this->tempWorkDir,
            backupStorage: $localAdapter
        );

        $healthReport = $doctor->diagnoseAll();
        $this->assertSame(HealthStatus::WARNING, $healthReport->getOverallStatus());
        $this->assertSame(HealthStatus::WARNING, $healthReport->getComponents()['providers']->getStatus());
        foreach (['database', 'cron', 'queue', 'storage', 'modules', 'backup'] as $component) {
            $this->assertSame(HealthStatus::HEALTHY, $healthReport->getComponents()[$component]->getStatus());
        }
        $this->assertCount(7, $healthReport->getComponents());
    }

    // =========================================================================
    // G07: WHMCS Cutover: Scan→Map→Dry-run→Migrate→Reconcile→Migration Hold→Cutover→Seal
    // =========================================================================
    public function testG07WhmcsCutoverScenario(): void
    {
        // Executes authentic, comprehensive WHMCS Cutover Golden E2E journey
        $cutoverTest = new CutoverAndGoldenMigrationE2ETest('testGoldenMigrationEndToEndWithCutoverChecklistAndAuditSeal');
        $cutoverTest->setUp();
        $cutoverTest->testGoldenMigrationEndToEndWithCutoverChecklistAndAuditSeal();
        $cutoverTest->tearDown();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // G08: Privacy Restore: Backup user→Erase→Restore old backup→Apply tombstone→PII must not resurrect
    // =========================================================================
    public function testG08PrivacyTombstoneRestoreScenario(): void
    {
        $db = $this->createDb();
        $db->statement(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT,
                email TEXT,
                status TEXT
            )"
        );

        // Time T0: User exists in database
        $db->statement("INSERT INTO users (id, name, email, status) VALUES (42, 'Original PII Name', 'privacy.client@domain.com', 'active')");

        $appDir = $this->tempWorkDir . '/privacy_app';
        $backupDir = $this->tempWorkDir . '/privacy_backups';
        mkdir($appDir, 0777, true);
        mkdir($backupDir, 0777, true);

        $configFile = $appDir . '/version.txt';
        file_put_contents($configFile, '1.0.0');

        $localAdapter = new LocalBackupStorageAdapter($backupDir);
        $backupService = new EnterpriseBackupService(
            workingDirectory: $this->tempWorkDir . '/privacy_tmp',
            db: $db,
            appVersion: '1.0.0'
        );

        // Take pre-erasure backup containing User 42's PII
        $backupResult = $backupService->createBackup(
            scope: BackupScope::FULL,
            destination: $localAdapter,
            filePaths: [$configFile]
        );
        $backupId = $backupResult['manifest']->getBackupId();

        // Time T1: User exercises GDPR erasure request
        $tombstoneStorePath = $this->tempWorkDir . '/tombstones.json';
        $fileStore = new FileTombstoneStore($tombstoneStorePath);
        $tombstoneService = new PrivacyTombstoneService(
            db: $db,
            persistentStore: $fileStore,
            secretKey: 'g08_tombstone_secret_key',
            salt: 'g08_tombstone_salt'
        );
        $tombstoneService->ensureTables();

        $tombstone = $tombstoneService->recordTombstone(
            userId: 42,
            email: 'privacy.client@domain.com',
            erasureType: 'ANONYMIZE',
            reason: 'GDPR Right to be Forgotten',
            erasureChecksum: 'sha256_checksum_g08',
            metadata: ['executed_by' => 'DPO_Admin']
        );

        // User is anonymized in live database at T1
        $db->statement("UPDATE users SET name = '[Anonymized User #42]', email = 'anon42@scrubbed.local', status = 'erased' WHERE id = 42");

        // Time T2: Disaster strikes! Live database is destroyed and restored from T0 backup
        $restoreWizard = new RestoreWizardService(
            temporaryExtractDir: $this->tempWorkDir . '/restore_t2_tmp'
        );

        $restoreResult = $restoreWizard->executeRestore(
            backupId: $backupId,
            sourceStorage: $localAdapter,
            targetDb: $db,
            targetAppDir: $appDir
        );
        $this->assertTrue($restoreResult->isSuccess());

        // Immediately after DB restore (before reconciliation), User 42 would be resurrected with PII:
        $resurrectedUsers = $db->select("SELECT * FROM users WHERE id = 42");
        $this->assertSame('Original PII Name', $resurrectedUsers[0]['name']);

        // Time T3: Mandatory Post-Restore Reconciliation Engine runs
        $reconService = new BackupRestoreReconciliationService(
            db: $db,
            tombstoneService: $tombstoneService
        );
        $report = $reconService->reconcileAfterBackupRestore();
        $this->assertSame(1, $report->getResurrectedUsersDetected());
        $this->assertSame(1, $report->getResurrectedUsersReScrubbed());

        // Post-reconciliation verification: PII was scrubbed, tombstone upheld!
        $scrubbedUsers = $db->select("SELECT * FROM users WHERE id = 42");
        $this->assertSame('[Anonymized User #42]', $scrubbedUsers[0]['name']);
        $this->assertSame('erased', $scrubbedUsers[0]['status']);
        $this->assertStringNotContainsString('privacy.client@domain.com', $scrubbedUsers[0]['email']);
    }
}
