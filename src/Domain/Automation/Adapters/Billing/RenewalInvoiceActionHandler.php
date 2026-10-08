<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters\Billing;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Recurring\RenewalInvoiceService;
use Coleza\Domain\Commerce\Services\ServiceService;
use Throwable;

final class RenewalInvoiceActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly RenewalInvoiceService $renewalInvoiceService,
        private readonly ServiceService $serviceService
    ) {
    }

    public function getType(): string
    {
        return 'invoice.generate_renewal';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $rawId = $resolved['service_id'] ?? $context->get('service.id') ?? $context->get('service_id');
        if ($rawId === null || $rawId === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                "Missing required 'service_id' parameter",
                [],
                $durationMs
            );
        }

        $serviceId = (int) $rawId;
        $dueDate = isset($resolved['due_date']) ? (string) $resolved['due_date'] : null;

        try {
            $service = $this->serviceService->findServiceById($serviceId);
            if ($service === null) {
                $durationMs = (microtime(true) - $start) * 1000;
                return ActionResult::failed(
                    $action->getId(),
                    $this->getType(),
                    "Service with ID {$serviceId} not found",
                    [],
                    $durationMs
                );
            }

            $serviceData = [
                'id' => $service->getId(),
                'user_id' => $service->getUserId(),
                'organization_id' => $service->getOrganizationId(),
                'currency_code' => $service->getCurrencyCode(),
                'service_number' => $service->getServiceNumber(),
                'product_id' => $service->getProductId(),
                'domain' => $service->getDomain(),
                'billing_cycle' => $service->getBillingCycle(),
                'recurring_amount_minor' => $service->getRecurringAmountMinor(),
                'next_due_date' => $service->getNextDueDate(),
            ];

            $invoice = $this->renewalInvoiceService->generateRenewalInvoice($serviceData, $dueDate);
            $durationMs = (microtime(true) - $start) * 1000;

            return ActionResult::success($action->getId(), $this->getType(), [
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->getInvoiceNumber(),
                'total_minor' => $invoice->getTotalMinor(),
                'due_date' => $invoice->getDueDate(),
                'status' => $invoice->getStatus(),
                'service_id' => $serviceId,
            ], $durationMs);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'Renewal invoice generation failed: ' . $e->getMessage(),
                ['service_id' => $serviceId],
                $durationMs
            );
        }
    }
}
