<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters\Service;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Services\ServiceService;
use Throwable;

final class ServiceRenewActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly ServiceService $serviceService
    ) {
    }

    public function getType(): string
    {
        return 'service.renew';
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
        $expectedLockVersion = isset($resolved['expected_lock_version']) ? (int) $resolved['expected_lock_version'] : null;

        try {
            $invoiceId=$resolved['invoice_id']??$context->get('invoice.id')??$context->get('invoice_id');
            if($invoiceId!==null||in_array($context->getEventName(),['invoice.paid','invoice_paid','payment.completed','payment_completed'],true)){
                $invoiceId=\Coleza\Domain\Commerce\CommerceAmounts::integer($invoiceId,'invoice_id',1);
                $db=$this->serviceService->getConnection();
                (new \Coleza\Domain\Commerce\Recurring\RenewalInvoiceService($db,new \Coleza\Domain\Commerce\Invoices\InvoiceService($db)))->settlePaidInvoice($invoiceId,expectedServiceId:$serviceId);
                $service=$this->serviceService->findServiceById($serviceId);
            }else{
                // Explicit non-payment automation retains the manual extension command.
                $service = $this->serviceService->renewService($serviceId, $expectedLockVersion);
            }
            $durationMs = (microtime(true) - $start) * 1000;

            return ActionResult::success($action->getId(), $this->getType(), [
                'service_id' => $service->getId(),
                'service_number' => $service->getServiceNumber(),
                'status' => $service->getStatus(),
                'next_due_date' => $service->getNextDueDate(),
            ], $durationMs);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'Service renewal command failed: ' . $e->getMessage(),
                ['service_id' => $serviceId],
                $durationMs
            );
        }
    }
}
