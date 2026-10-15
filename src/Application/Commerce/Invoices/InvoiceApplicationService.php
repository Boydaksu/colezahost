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
    private \Coleza\Application\Identity\CommandAuthorizer $authorization;

    public function __construct(
        private InvoiceService $invoiceService
    ) {
        $this->authorization = new \Coleza\Application\Identity\CommandAuthorizer($this->invoiceService->connection());
    }

    public function createInvoice(CreateInvoiceCommand $command, ?int $authUserId = null, bool $isAdmin = false): Invoice
    {
        return $this->authorization->run($authUserId, function () use ($command, $authUserId, $isAdmin): Invoice {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'invoices.manage');
            $this->authorization->requireSystem($authUserId,'invoices.manage');
            if($command->orderId!==null){throw new ValidationException(['order'=>['Order invoices use the frozen-order command.']]);}
            $this->authorization->resource($authUserId, $command->userId, $command->organizationId, 'invoices.manage', 'org.billing.manage');

            // Invoice generation is an admin/system privileged action or client self-checkout action

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

        }, 'command.createInvoice');
    }

    public function createForOrder(int $orderId,int $actor): Invoice
    {
        return $this->authorization->run($actor,function()use($orderId,$actor):Invoice{
            $orders=new \Coleza\Domain\Commerce\Orders\OrderService($this->invoiceService->connection());$order=$orders->findOrderById($orderId);
            if($order===null){throw new ResourceNotFoundException('Order not found.');}
            $this->authorization->resource($actor,$order->getUserId(),$order->getOrganizationId(),'invoices.manage','org.billing.manage');
            return $this->invoiceService->createInvoiceFromOrder($order);
        },'command.order_invoice_created');
    }

    public function getInvoice(int $invoiceId, ?int $authUserId = null, bool $isAdmin = false): Invoice
    {
        return $this->authorization->run($authUserId, function () use ($invoiceId, $authUserId, $isAdmin): Invoice {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'invoices.view');

            $invoice = $this->invoiceService->findInvoiceById($invoiceId);
            if ($invoice === null) {
                throw new ResourceNotFoundException("Invoice {$invoiceId} not found.");
            }

            $this->authorization->resource($authUserId, $invoice->getUserId(), $invoice->getOrganizationId(), 'invoices.view', 'org.billing.view');

            // IDOR protection

            return $invoice;

        }, null);
    }

    /**
     * @return array<Invoice>
     */
    public function listUserInvoices(int $userId, ?int $authUserId = null, bool $isAdmin = false): array
    {
        return $this->authorization->run($authUserId, function () use ($userId, $authUserId, $isAdmin): array {
            $isAdmin = $this->authorization->hasSystemPermission($authUserId, 'invoices.view');

            if (!$isAdmin && $authUserId !== null && $userId !== $authUserId) {
                throw new AuthorizationException('Forbidden: Cannot list invoices for another user.');
            }

            $rows = $this->invoiceService->listInvoicesForUser($userId);
            return array_values(array_filter($rows, function ($row) use ($authUserId): bool {
                try { $this->authorization->resource($authUserId, $row->getUserId(), $row->getOrganizationId(), 'invoices.view', 'org.billing.view'); return true; }
                catch (AuthorizationException) { return false; }
            }));

        }, null);
    }
}
