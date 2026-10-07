<?php

declare(strict_types=1);

namespace Coleza\Application\Commerce\Invoices;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Foundation\Exceptions\AuthorizationException;
use Coleza\Foundation\Exceptions\ResourceNotFoundException;
use Coleza\Foundation\Exceptions\ValidationException;

final class InvoiceApplicationService
{
    public function __construct(
        private InvoiceService $invoiceService
    ) {
    }

    public function createInvoice(CreateInvoiceCommand $command, ?int $authUserId = null, bool $isAdmin = false): Invoice
    {
        // Invoice generation is an admin/system privileged action or client self-checkout action
        if (!$isAdmin && $authUserId !== null && $command->userId !== $authUserId) {
            throw new AuthorizationException('Cannot generate invoice for another user.');
        }

        if (empty($command->items)) {
            throw new ValidationException(['items' => 'Invoice must have line items.'], 'Empty invoice items');
        }

        $invoiceData = [
            'user_id' => $command->userId,
            'order_id' => $command->orderId,
            'organization_id' => $command->organizationId,
            'currency_code' => $command->currencyCode,
            'due_date' => $command->dueDate,
            'notes' => $command->notes,
            'metadata' => $command->metadata,
        ];

        return $this->invoiceService->createInvoice($invoiceData, $command->items);
    }

    public function getInvoice(int $invoiceId, ?int $authUserId = null, bool $isAdmin = false): Invoice
    {
        $invoice = $this->invoiceService->findInvoiceById($invoiceId);
        if ($invoice === null) {
            throw new ResourceNotFoundException("Invoice {$invoiceId} not found.");
        }

        // IDOR protection
        if (!$isAdmin && $authUserId !== null && $invoice->getUserId() !== $authUserId) {
            throw new AuthorizationException('Forbidden: You do not have access to this invoice.');
        }

        return $invoice;
    }

    /**
     * @return array<Invoice>
     */
    public function listUserInvoices(int $userId, ?int $authUserId = null, bool $isAdmin = false): array
    {
        if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
            throw new AuthorizationException('Forbidden: Cannot list invoices for another user.');
        }

        return $this->invoiceService->listInvoicesForUser($userId);
    }
}
