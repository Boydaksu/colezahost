<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Adapters\AutomationDomainAdapterRegistry;
use Coleza\Domain\Automation\Adapters\Billing\InvoiceStatusActionHandler;
use Coleza\Domain\Automation\Adapters\Billing\RenewalInvoiceActionHandler;
use Coleza\Domain\Automation\Adapters\Notification\InAppNotificationActionHandler;
use Coleza\Domain\Automation\Adapters\Notification\NotificationActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceCancelActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceRenewActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceSuspendActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceTerminateActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceUnsuspendActionHandler;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Engine\AutomationEngine;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\Service;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainCommandAdaptersTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private ServiceService $serviceService;
    private InvoiceService $invoiceService;
    private RenewalInvoiceService $renewalInvoiceService;
    private MemoryMailTransport $mailTransport;
    private NotificationEngine $notificationEngine;
    private NotificationCenterService $notificationCenter;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        // Service table
        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        // Invoice table
        $this->invoiceService = new InvoiceService($this->db);
        $this->invoiceService->ensureTables();

        // Renewal invoice service
        $this->renewalInvoiceService = new RenewalInvoiceService($this->db, $this->invoiceService);
        $this->renewalInvoiceService->ensureTables();

        // Notification components
        $this->mailTransport = new MemoryMailTransport();
        $templateEngine = new NotificationTemplateEngine();
        $this->notificationEngine = new NotificationEngine($this->mailTransport, $templateEngine);

        // In-app notification service
        $this->notificationCenter = new NotificationCenterService($this->pdo);
    }

    public function testServiceSuspendAndUnsuspendAdapters(): void
    {
        $service = $this->createTestService(ServiceStateMachine::STATUS_ACTIVE);
        $serviceId = $service->getId();

        $suspendHandler = new ServiceSuspendActionHandler($this->serviceService);
        $action = new ActionDefinition('act_suspend', 'service.suspend', [
            'service_id' => $serviceId,
            'reason' => 'Non-payment of overdue invoice',
        ]);
        $context = new TriggerContext('cron.check', []);

        $result = $suspendHandler->execute($action, $context);
        $this->assertTrue($result->isSuccess(), (string) $result->getErrorMessage());

        $updatedService = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $updatedService->getStatus());
        $this->assertSame('Non-payment of overdue invoice', $updatedService->getSuspensionReason());

        // Unsuspend
        $unsuspendHandler = new ServiceUnsuspendActionHandler($this->serviceService);
        $unsuspendAction = new ActionDefinition('act_unsuspend', 'service.unsuspend', [
            'service_id' => $serviceId,
        ]);

        $unsuspendResult = $unsuspendHandler->execute($unsuspendAction, $context);
        $this->assertTrue($unsuspendResult->isSuccess());

        $activeService = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_ACTIVE, $activeService->getStatus());
    }

    public function testServiceTerminateAndCancelAdapters(): void
    {
        $service1 = $this->createTestService(ServiceStateMachine::STATUS_ACTIVE);
        $service2 = $this->createTestService(ServiceStateMachine::STATUS_ACTIVE);

        $cancelHandler = new ServiceCancelActionHandler($this->serviceService);
        $cancelAction = new ActionDefinition('c1', 'service.cancel', [
            'service_id' => $service1->getId(),
            'reason' => 'Customer requested cancellation',
        ]);
        $resCancel = $cancelHandler->execute($cancelAction, new TriggerContext('ev', []));
        $this->assertTrue($resCancel->isSuccess());
        $this->assertSame(ServiceStateMachine::STATUS_CANCELLED, $this->serviceService->findServiceById($service1->getId())->getStatus());

        $termHandler = new ServiceTerminateActionHandler($this->serviceService);
        $termAction = new ActionDefinition('t1', 'service.terminate', [
            'service_id' => $service2->getId(),
            'reason' => 'Prolonged delinquency',
        ]);
        $resTerm = $termHandler->execute($termAction, new TriggerContext('ev', []));
        $this->assertTrue($resTerm->isSuccess());
        $this->assertSame(ServiceStateMachine::STATUS_TERMINATED, $this->serviceService->findServiceById($service2->getId())->getStatus());
    }

    public function testServiceRenewAdapter(): void
    {
        $service = $this->createTestService(ServiceStateMachine::STATUS_ACTIVE, '2026-11-01');
        $renewHandler = new ServiceRenewActionHandler($this->serviceService);
        $renewAction = new ActionDefinition('r1', 'service.renew', [
            'service_id' => $service->getId(),
        ]);

        $res = $renewHandler->execute($renewAction, new TriggerContext('ev', []));
        $this->assertTrue($res->isSuccess());

        $renewed = $this->serviceService->findServiceById($service->getId());
        $this->assertSame('2026-12-01', $renewed->getNextDueDate());
    }

    public function testRenewalInvoiceAdapter(): void
    {
        $service = $this->createTestService(ServiceStateMachine::STATUS_ACTIVE, '2026-12-15');
        $handler = new RenewalInvoiceActionHandler($this->renewalInvoiceService, $this->serviceService);

        $action = new ActionDefinition('inv_ren', 'invoice.generate_renewal', [
            'service_id' => $service->getId(),
            'due_date' => '2026-12-15',
        ]);

        $result = $handler->execute($action, new TriggerContext('ev', []));

        $this->assertTrue($result->isSuccess());
        $output = $result->getOutput();
        $this->assertArrayHasKey('invoice_id', $output);
        $this->assertArrayHasKey('invoice_number', $output);
        $this->assertSame(2999, $output['total_minor']);

        $createdInvoice = $this->invoiceService->findInvoiceById($output['invoice_id']);
        $this->assertNotNull($createdInvoice);
        $this->assertSame(Invoice::STATUS_UNPAID, $createdInvoice->getStatus());
    }

    public function testInvoiceStatusAdapter(): void
    {
        $invoice = $this->invoiceService->createInvoice([
            'user_id' => 1,
            'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD',
            'subtotal_minor' => 5000,
            'tax_total_minor' => 0,
            'total_minor' => 5000,
            'due_date' => '2026-11-10',
        ], [
            [
                'description' => 'Shared Hosting Package',
                'quantity' => 1,
                'unit_amount_minor' => 5000,
                'subtotal_minor' => 5000,
                'tax_amount_minor' => 0,
                'total_minor' => 5000,
            ],
        ]);

        $handler = new InvoiceStatusActionHandler($this->invoiceService);
        $action = new ActionDefinition('inv_stat', 'invoice.update_status', [
            'invoice_id' => $invoice->getId(),
            'status' => Invoice::STATUS_CANCELLED,
        ]);

        $result = $handler->execute($action, new TriggerContext('ev', []));
        $this->assertTrue($result->isSuccess());

        $updated = $this->invoiceService->findInvoiceById($invoice->getId());
        $this->assertSame(Invoice::STATUS_CANCELLED, $updated->getStatus());
    }

    public function testNotificationAdapters(): void
    {
        // 1. Email notification adapter
        $emailHandler = new NotificationActionHandler($this->notificationEngine);
        $emailAction = new ActionDefinition('notif_1', 'notification.send', [
            'template_key' => 'invoice_created',
            'recipient_email' => 'customer@domain.com',
            'recipient_name' => 'John Customer',
            'data' => [
                'invoice_number' => 'INV-2026-00001',
                'total_amount' => '$29.99',
                'due_date' => '2026-11-15',
            ],
        ]);

        $resEmail = $emailHandler->execute($emailAction, new TriggerContext('ev', []));
        $this->assertTrue($resEmail->isSuccess());
        $this->assertCount(1, $this->mailTransport->getSentMessages());
        $sent = $this->mailTransport->findLastByRecipient('customer@domain.com');
        $this->assertNotNull($sent);
        $this->assertSame('customer@domain.com', $sent->getRecipientEmail());

        // 2. In-App notification adapter
        $inAppHandler = new InAppNotificationActionHandler($this->notificationCenter);
        $inAppAction = new ActionDefinition('inapp_1', 'notification.in_app', [
            'user_id' => 42,
            'title' => 'Invoice Ready',
            'message' => 'Your invoice INV-2026-00001 is ready for payment.',
            'action_url' => '/billing/invoices/1',
            'type' => 'info',
        ]);

        $resInApp = $inAppHandler->execute($inAppAction, new TriggerContext('ev', []));
        $this->assertTrue($resInApp->isSuccess());

        $notifications = $this->notificationCenter->getUserNotifications(42);
        $this->assertCount(1, $notifications);
        $this->assertSame('Invoice Ready', $notifications[0]->getTitle());
    }

    public function testEndToEndAutomationRuleUsingDomainCommandAdapters(): void
    {
        $registry = new ActionRegistry();
        AutomationDomainAdapterRegistry::registerAll(
            registry: $registry,
            serviceService: $this->serviceService,
            invoiceService: $this->invoiceService,
            renewalInvoiceService: $this->renewalInvoiceService,
            notificationEngine: $this->notificationEngine,
            notificationCenter: $this->notificationCenter
        );

        $engine = new AutomationEngine($registry);

        $service = $this->createTestService(ServiceStateMachine::STATUS_ACTIVE);
        $serviceId = $service->getId();

        // Rule: If service days_overdue > 10, suspend service and send notification
        $rule = new AutomationRule(
            id: 'rule_auto_suspend',
            name: 'Overdue Suspension Automation',
            trigger: new EventTrigger('service.overdue'),
            branch: new IfElseBranch(
                condition: new FieldCondition('days_overdue', ConditionOperator::GREATER_THAN, 10),
                thenActions: [
                    new ActionDefinition('act_suspend', 'service.suspend', [
                        'service_id' => '{{ service.id }}',
                        'reason' => 'Auto-suspended: {{ days_overdue }} days past due date',
                    ]),
                    new ActionDefinition('act_notify', 'notification.send', [
                        'template_key' => 'invoice_created',
                        'recipient_email' => '{{ customer.email }}',
                        'data' => [
                            'invoice_number' => 'OVERDUE-1',
                            'total_amount' => '$29.99',
                            'due_date' => 'Immediate',
                        ],
                    ]),
                ]
            )
        );

        $context = new TriggerContext('service.overdue', [
            'service' => [
                'id' => $serviceId,
            ],
            'customer' => [
                'email' => 'client.auto@example.com',
            ],
            'days_overdue' => 14,
        ]);

        $report = $engine->processEvent($context, [$rule]);

        $this->assertSame(1, $report->count());
        $this->assertFalse($report->hasFailures());

        // Assert service was suspended via domain command
        $updated = $this->serviceService->findServiceById($serviceId);
        $this->assertSame(ServiceStateMachine::STATUS_SUSPENDED, $updated->getStatus());
        $this->assertSame('Auto-suspended: 14 days past due date', $updated->getSuspensionReason());

        // Assert notification was sent
        $this->assertCount(1, $this->mailTransport->getSentMessages());
        $sentMsg = $this->mailTransport->findLastByRecipient('client.auto@example.com');
        $this->assertNotNull($sentMsg);
        $this->assertSame('client.auto@example.com', $sentMsg->getRecipientEmail());
    }

    private function createTestService(string $initialStatus = ServiceStateMachine::STATUS_ACTIVE, string $dueDate = '2026-11-01'): Service
    {
        return $this->serviceService->createService([
            'user_id' => 1,
            'organization_id' => 1,
            'product_id' => 10,
            'domain' => 'test-domain.com',
            'billing_cycle' => 'monthly',
            'recurring_amount_minor' => 2999,
            'currency_code' => 'USD',
            'status' => $initialStatus,
            'next_due_date' => $dueDate,
        ]);
    }
}
