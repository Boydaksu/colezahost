<?php

declare(strict_types=1);

namespace Coleza\Api\Controllers\Commerce;

use Coleza\Api\Response\ApiResponse;
use Coleza\Application\Commerce\Invoices\CreateInvoiceCommand;
use Coleza\Application\Commerce\Invoices\InvoiceApplicationService;
use Coleza\Foundation\Exceptions\ValidationException;

final class InvoiceApiController
{
    public function __construct(
        private InvoiceApplicationService $invoiceAppService
    ) {
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function create(array $requestData, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $targetUserId = isset($requestData['user_id']) && $isAdmin
            ? (int) $requestData['user_id']
            : $userId;

        if ($targetUserId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id required.'], 'Authentication required');
        }

        $items = (array) ($requestData['items'] ?? []);
        $currencyCode = (string) ($requestData['currency_code'] ?? 'TRY');
        $orderId = isset($requestData['order_id']) ? (int) $requestData['order_id'] : null;
        $orgId = isset($requestData['organization_id']) ? (int) $requestData['organization_id'] : null;
        $dueDate = isset($requestData['due_date']) ? (string) $requestData['due_date'] : null;
        $notes = isset($requestData['notes']) ? (string) $requestData['notes'] : null;
        $metadata = (array) ($requestData['metadata'] ?? []);

        $command = new CreateInvoiceCommand(
            userId: $targetUserId,
            items: $items,
            currencyCode: $currencyCode,
            orderId: $orderId,
            organizationId: $orgId,
            dueDate: $dueDate,
            notes: $notes,
            metadata: $metadata
        );

        $invoice = $this->invoiceAppService->createInvoice($command, $userId, $isAdmin);

        return ApiResponse::success([
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'status' => $invoice->getStatus(),
            'total_minor' => $invoice->getTotalMinor(),
            'balance_due_minor' => $invoice->getBalanceDueMinor(),
            'currency_code' => $invoice->getCurrencyCode(),
        ], ['action' => 'invoice_created'], 201);
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function show(int $invoiceId, array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $invoice = $this->invoiceAppService->getInvoice($invoiceId, $userId, $isAdmin);

        return ApiResponse::success([
            'id' => $invoice->getId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'user_id' => $invoice->getUserId(),
            'status' => $invoice->getStatus(),
            'total_minor' => $invoice->getTotalMinor(),
            'balance_due_minor' => $invoice->getBalanceDueMinor(),
            'currency_code' => $invoice->getCurrencyCode(),
            'issue_date' => $invoice->getIssueDate(),
            'due_date' => $invoice->getDueDate(),
        ]);
    }

    /**
     * @param array<string, mixed> $authContext
     * @return array<string, mixed>
     */
    public function list(array $authContext): array
    {
        $userId = (int) ($authContext['user_id'] ?? 0);
        $isAdmin = (bool) ($authContext['is_admin'] ?? false);

        $invoices = $this->invoiceAppService->listUserInvoices($userId, $userId, $isAdmin);

        $data = array_map(fn($inv) => [
            'id' => $inv->getId(),
            'invoice_number' => $inv->getInvoiceNumber(),
            'status' => $inv->getStatus(),
            'total_minor' => $inv->getTotalMinor(),
            'balance_due_minor' => $inv->getBalanceDueMinor(),
            'currency_code' => $inv->getCurrencyCode(),
        ], $invoices);

        return ApiResponse::success($data, ['total' => count($data)]);
    }
}
