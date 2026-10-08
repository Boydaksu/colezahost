<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters;

use Coleza\Domain\Automation\Actions\ActionRegistryInterface;
use Coleza\Domain\Automation\Adapters\Billing\InvoiceStatusActionHandler;
use Coleza\Domain\Automation\Adapters\Billing\RenewalInvoiceActionHandler;
use Coleza\Domain\Automation\Adapters\Notification\InAppNotificationActionHandler;
use Coleza\Domain\Automation\Adapters\Notification\NotificationActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceCancelActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceRenewActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceSuspendActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceTerminateActionHandler;
use Coleza\Domain\Automation\Adapters\Service\ServiceUnsuspendActionHandler;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Coleza\Domain\Notifications\NotificationEngine;

final class AutomationDomainAdapterRegistry
{
    /**
     * Register domain command adapters for available domain services.
     */
    public static function registerAll(
        ActionRegistryInterface $registry,
        ?ServiceService $serviceService = null,
        ?InvoiceService $invoiceService = null,
        ?RenewalInvoiceService $renewalInvoiceService = null,
        ?NotificationEngine $notificationEngine = null,
        ?NotificationCenterService $notificationCenter = null
    ): void {
        if ($serviceService !== null) {
            $registry->register(new ServiceSuspendActionHandler($serviceService));
            $registry->register(new ServiceUnsuspendActionHandler($serviceService));
            $registry->register(new ServiceTerminateActionHandler($serviceService));
            $registry->register(new ServiceCancelActionHandler($serviceService));
            $registry->register(new ServiceRenewActionHandler($serviceService));
        }

        if ($invoiceService !== null) {
            $registry->register(new InvoiceStatusActionHandler($invoiceService));
        }

        if ($renewalInvoiceService !== null && $serviceService !== null) {
            $registry->register(new RenewalInvoiceActionHandler($renewalInvoiceService, $serviceService));
        }

        if ($notificationEngine !== null) {
            $registry->register(new NotificationActionHandler($notificationEngine));
        }

        if ($notificationCenter !== null) {
            $registry->register(new InAppNotificationActionHandler($notificationCenter));
        }
    }
}
