<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Adapters\Billing;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Throwable;

final class InvoiceStatusActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    public function getType(): string
    {
        return 'invoice.update_status';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $rawId = $resolved['invoice_id'] ?? $context->get('invoice.id') ?? $context->get('invoice_id');
        if ($rawId === null || $rawId === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                "Missing required 'invoice_id' parameter",
                [],
                $durationMs
            );
        }

        $invoiceId = (int) $rawId;
        $status = (string) ($resolved['status'] ?? '');
        if ($status === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                "Missing required 'status' parameter",
                [],
                $durationMs
            );
        }

        $paidAt = isset($resolved['paid_at']) ? (string) $resolved['paid_at'] : null;

        try {
            $invoice = $this->invoiceService->updateStatus($invoiceId, $status, $paidAt);
            $durationMs = (microtime(true) - $start) * 1000;

            return ActionResult::success($action->getId(), $this->getType(), [
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->getInvoiceNumber(),
                'status' => $invoice->getStatus(),
                'paid_at' => $invoice->getPaidAt(),
            ], $durationMs);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed(
                $action->getId(),
                $this->getType(),
                'Invoice status update failed: ' . $e->getMessage(),
                ['invoice_id' => $invoiceId],
                $durationMs
            );
        }
    }
}
