<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration;
use Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Services\ServicePlacement;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\Providers\Cpanel\CpanelMemoryTransport;
use Coleza\Domain\Providers\Cpanel\CpanelProvider;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Coordinators\AutomatedHostingOrderCoordinator;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow;
use Coleza\Domain\Servers\Capacity\Services\CapacityReservationService;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\PlacementEngine;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutomatedHostingGoldenE2ETest extends TestCase
{
    private Connection $db;
    private OrderService $orderService;
    private InvoiceService $invoiceService;
    private PaymentService $paymentService;
    private ServiceService $serviceService;
    private ServerService $serverService;
    private CapacityReservationService $reservationService;
    private PlacementEngine $placementEngine;
    private ProvisioningOperationService $operationService;
    private CpanelMemoryTransport $cpanelTransport;
    private CpanelProvider $cpanelProvider;
    private ProviderRegistry $providerRegistry;
    private HostingProvisioningWorkflow $workflow;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;
    private AutomatedHostingOrderCoordinator $coordinator;
    private Server $cpanelServer;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // 1. Commerce tables
        $this->orderService = new OrderService($this->db);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db);
        $this->invoiceService->ensureTables();

        $this->paymentService = new PaymentService($this->db, $this->invoiceService);
        $this->paymentService->ensureTables();

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        // 2. Server & Capacity tables
        $this->serverService = new ServerService($this->db);
        $this->serverService->ensureTables();

        $this->reservationService = new CapacityReservationService($this->db, $this->serverService);
        $this->reservationService->ensureTables();

        $this->placementEngine = new PlacementEngine($this->serverService);

        // 3. Provisioning & Providers
        $this->operationService = new ProvisioningOperationService($this->db);
        $this->operationService->ensureTables();

        $this->cpanelTransport = new CpanelMemoryTransport();
        $this->cpanelProvider = new CpanelProvider($this->cpanelTransport);
        $this->providerRegistry = new ProviderRegistry([$this->cpanelProvider]);

        $this->workflow = new HostingProvisioningWorkflow(
            serviceService: $this->serviceService,
            serverService: $this->serverService,
            placementEngine: $this->placementEngine,
            reservationService: $this->reservationService,
            providerRegistry: $this->providerRegistry,
            operationService: $this->operationService
        );

        // 4. Notifications
        $this->mailTransport = new MemoryMailTransport();
        $this->notificationEngine = new NotificationEngine(mailer: $this->mailTransport);

        // 5. Golden Coordinator
        $this->coordinator = new AutomatedHostingOrderCoordinator(
            serviceService: $this->serviceService,
            workflow: $this->workflow,
            notificationEngine: $this->notificationEngine,
            orderService: $this->orderService
        );

        // 6. Setup Active cPanel Infrastructure Node
        $this->cpanelServer = $this->serverService->createServer([
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
        $this->cpanelTransport->stageResponse('listpkgs', 200, [
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

        $this->cpanelTransport->stageResponse('createacct', 200, [
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

        $this->cpanelTransport->stageResponse('accountsummary', 200, [
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
    }

    public function testAutomatedHostingGoldenE2EJourney(): void
    {
        $userId = 42;
        $customerEmail = 'customer@goldensite.com';
        $customerName = 'Ahmet Yilmaz';

        // -------------------------------------------------------------
        // STEP 1: Customer checkout & Order Creation
        // -------------------------------------------------------------
        $order = $this->orderService->createOrder(
            orderData: [
                'user_id' => $userId,
                'currency_code' => 'TRY',
                'notes' => 'Golden E2E Test Order',
            ],
            itemsData: [
                [
                    'product_id' => 101,
                    'product_name' => 'cPanel Cloud Starter Hosting',
                    'cycle' => 'monthly',
                    'quantity' => 1,
                    'unit_price_minor' => 14900, // 149.00 TRY
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

        $this->assertNotNull($order->getId());
        $this->assertSame(14900, $order->getTotalMinor());
        $this->assertSame('pending_payment', $order->getStatus());

        // -------------------------------------------------------------
        // STEP 2: Invoice Generation
        // -------------------------------------------------------------
        $invoice = $this->invoiceService->createInvoiceFromOrder($order);
        $this->assertNotNull($invoice->getId());
        $this->assertSame(14900, $invoice->getTotalMinor());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());

        // -------------------------------------------------------------
        // STEP 3: iyzico Payment Processing
        // -------------------------------------------------------------
        $iyzicoConfig = new IyzicoConfiguration(
            apiKey: 'sandbox-api-key',
            secretKey: 'sandbox-secret-key',
            baseUrl: 'https://sandbox-api.iyzipay.com',
            isTestMode: true
        );

        $payment = $this->paymentService->recordPayment([
            'user_id' => $userId,
            'payment_method' => 'iyzico',
            'amount_minor' => 14900,
            'currency_code' => 'TRY',
            'invoice_id' => $invoice->getId(),
            'transaction_reference' => 'IYZICO-TX-987654321',
            'status' => Payment::STATUS_COMPLETED,
            'metadata' => ['iyzico_payment_id' => '12345678', 'gateway' => 'iyzico'],
        ]);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());
        $this->assertSame('IYZICO-TX-987654321', $payment->getTransactionReference());

        // Verify invoice is now fully paid
        $paidInvoice = $this->invoiceService->findInvoiceById((int)$invoice->getId());
        $this->assertNotNull($paidInvoice);
        $this->assertSame(Invoice::STATUS_PAID, $paidInvoice->getStatus());
        $this->assertSame(0, $paidInvoice->getBalanceDueMinor());

        // -------------------------------------------------------------
        // STEP 4: Automated Hosting Provisioning & Welcome Notification
        // -------------------------------------------------------------
        $coordResult = $this->coordinator->processPaidOrder(
            order: $order,
            customerEmail: $customerEmail,
            customerName: $customerName,
            customerLocale: 'tr'
        );

        $this->assertTrue($coordResult['all_successful']);
        $this->assertSame(1, $coordResult['services_created']);
        $this->assertCount(1, $coordResult['provisioned_services']);

        $serviceOutcome = $coordResult['provisioned_services'][0];
        $this->assertTrue($serviceOutcome['result']->isSuccess());
        $this->assertTrue($serviceOutcome['notification_sent']);

        // -------------------------------------------------------------
        // STEP 5: End-to-End Verification of Service & Placement
        // -------------------------------------------------------------
        $serviceId = $serviceOutcome['service_id'];
        $service = $this->serviceService->findServiceById($serviceId);
        $this->assertNotNull($service);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $service->getStatus());
        $this->assertSame('goldensite', $service->getUsername());
        $this->assertSame('goldensite.com', $service->getDomain());
        $this->assertSame('185.120.40.22', $service->getIpAddress());
        $this->assertSame('cPanel Production EU-01', $service->getServerName());

        // Placement entity verification
        $placement = $this->serviceService->getPlacementForService($serviceId);
        $this->assertNotNull($placement);
        $this->assertSame(ServicePlacement::STATUS_PLACED, $placement->getStatus());
        $this->assertSame('whm.eu01.colezahost.com', $placement->getHostname());
        $this->assertSame('185.120.40.22', $placement->getDedicatedIp());

        // Capacity reservation verification (committed)
        $reservationToken = $serviceOutcome['result']->getReservationToken();
        $this->assertNotNull($reservationToken);
        $reservation = $this->reservationService->findReservationByToken($reservationToken);
        $this->assertNotNull($reservation);
        $this->assertTrue($reservation->isCommitted());

        // Provisioning operation verification (completed)
        $opUuid = $serviceOutcome['result']->getOperationUuid();
        $this->assertNotNull($opUuid);
        $op = $this->operationService->findOperationByUuid($opUuid);
        $this->assertNotNull($op);
        $this->assertSame('completed', $op->getStatus());

        // -------------------------------------------------------------
        // STEP 6: Email Delivery Verification
        // -------------------------------------------------------------
        $sentEmails = $this->mailTransport->getSentMessages();
        $this->assertCount(1, $sentEmails);

        $welcomeEmail = $sentEmails[0];
        $this->assertSame($customerEmail, $welcomeEmail->getRecipientEmail());
        $this->assertStringContainsString('goldensite.com', $welcomeEmail->getSubject());
        $this->assertStringContainsString('goldensite', $welcomeEmail->getHtmlBody());
        $this->assertStringContainsString('185.120.40.22', $welcomeEmail->getHtmlBody());
        $this->assertStringContainsString('ns1.colezahost.com', $welcomeEmail->getHtmlBody());
        $this->assertStringContainsString('ns2.colezahost.com', $welcomeEmail->getHtmlBody());
    }
}
