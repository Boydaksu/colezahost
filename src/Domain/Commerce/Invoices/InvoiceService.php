<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Invoices;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class InvoiceService
{
    private string $invoicesTable = 'invoices';
    private string $invoiceItemsTable = 'invoice_items';
    private string $numberSequenceTable = 'invoice_sequences';

    public function __construct(
        private Connection $db,
        private ?CurrencyService $currencyService = null,
        private ?TaxService $taxService = null
    ) {
    }

    public function ensureTables(): void
    {
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) {
            throw new RuntimeException('Initialize invoice schema before starting an application transaction.');
        }
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Invoices table
        $sqlInvoices = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                invoice_number VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                organization_id INT NULL,
                order_id INT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "unpaid",
                currency_code VARCHAR(3) NOT NULL,
                subtotal_minor INT NOT NULL DEFAULT 0,
                tax_total_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL DEFAULT 0,
                paid_amount_minor INT NOT NULL DEFAULT 0,
                issue_date VARCHAR(20) NOT NULL,
                due_date VARCHAR(20) NOT NULL,
                paid_at TIMESTAMP NULL,
                currency_snapshot_json TEXT NULL,
                tax_snapshot_json TEXT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->invoicesTable,
            $autoInc
        );
        $this->db->statement($sqlInvoices);

        // Invoice Items table
        $sqlItems = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                invoice_id INT NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_amount_minor INT NOT NULL DEFAULT 0,
                subtotal_minor INT NOT NULL DEFAULT 0,
                tax_amount_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL DEFAULT 0,
                order_item_id INT NULL,
                service_id INT NULL,
                tax_snapshot_json TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->invoiceItemsTable,
            $autoInc
        );
        $this->db->statement($sqlItems);

        // Sequential Numbering table
        $sqlSeq = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                year_prefix VARCHAR(4) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->numberSequenceTable
        );
        $this->db->statement($sqlSeq);
    }

    /**
     * Create an invoice with items, computing and freezing currency and tax snapshots.
     *
     * @param array<string, mixed> $invoiceData
     * @param array<array<string, mixed>> $itemsData
     */
    public function createInvoice(array $invoiceData, array $itemsData): Invoice
    {
        $userId = (int)($invoiceData['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id is required.'], 'Invalid invoice user');
        }

        if (empty($itemsData)) {
            throw new ValidationException(['items' => 'Invoice must have at least one line item.'], 'No invoice items');
        }

        $currencyCode = strtoupper(trim((string)($invoiceData['currency_code'] ?? 'USD')));
        $orgId = isset($invoiceData['organization_id']) && $invoiceData['organization_id'] !== null ? (int)$invoiceData['organization_id'] : null;
        $orderId = isset($invoiceData['order_id']) && $invoiceData['order_id'] !== null ? (int)$invoiceData['order_id'] : null;
        $issueDate = (string)($invoiceData['issue_date'] ?? date('Y-m-d'));
        $dueDate = (string)($invoiceData['due_date'] ?? date('Y-m-d', strtotime('+7 days')));
        $notes = isset($invoiceData['notes']) ? (string)$invoiceData['notes'] : null;

        // 1. Snapshot currency metadata
        $currencySnapshot = [
            'code' => $currencyCode,
            'captured_at' => date('Y-m-d H:i:s'),
        ];
        if ($this->currencyService !== null) {
            $currObj = $this->currencyService->findCurrency($currencyCode);
            if ($currObj !== null) {
                $currencySnapshot = $currObj->toArray();
            }
            $fxSnapshot = $this->currencyService->getLatestFxSnapshot();
            if ($fxSnapshot !== null) {
                $currencySnapshot['fx_snapshot_id'] = $fxSnapshot->getId();
                $currencySnapshot['fx_rate'] = $fxSnapshot->getRate($currencyCode);
            }
        }

        // Calculate totals across items
        $subtotalMinor = 0;
        $taxTotalMinor = 0;
        $processedItems = [];

        foreach ($itemsData as $itemData) {
            $desc = trim((string)($itemData['description'] ?? 'Item'));
            $qty = max(1, (int)($itemData['quantity'] ?? 1));
            $unitAmount = (int)($itemData['unit_amount_minor'] ?? 0);
            $subtotal = isset($itemData['subtotal_minor']) ? (int)$itemData['subtotal_minor'] : ($qty * $unitAmount);
            $taxAmount = max(0, (int)($itemData['tax_amount_minor'] ?? 0));
            $total = isset($itemData['total_minor']) ? (int)$itemData['total_minor'] : ($subtotal + $taxAmount);

            $subtotalMinor += $subtotal;
            $taxTotalMinor += $taxAmount;

            $processedItems[] = [
                'description' => $desc,
                'quantity' => $qty,
                'unit_amount_minor' => $unitAmount,
                'subtotal_minor' => $subtotal,
                'tax_amount_minor' => $taxAmount,
                'total_minor' => $total,
                'order_item_id' => $itemData['order_item_id'] ?? null,
                'service_id' => $itemData['service_id'] ?? null,
                'tax_snapshot' => $itemData['tax_snapshot'] ?? [],
                'metadata' => (array)($itemData['metadata'] ?? []),
            ];
        }

        $totalMinor = $subtotalMinor + $taxTotalMinor;

        // 2. Snapshot taxes
        $taxSnapshot = [
            'tax_total_minor' => $taxTotalMinor,
            'captured_at' => date('Y-m-d H:i:s'),
        ];

        $invoiceNumber = $this->nextInvoiceNumber();

        $sql = sprintf(
            'INSERT INTO %s (invoice_number, user_id, organization_id, order_id, status, currency_code, subtotal_minor, tax_total_minor, total_minor, paid_amount_minor, issue_date, due_date, currency_snapshot_json, tax_snapshot_json, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->invoicesTable
        );

        $this->db->statement($sql, [
            $invoiceNumber,
            $userId,
            $orgId,
            $orderId,
            Invoice::STATUS_UNPAID,
            $currencyCode,
            $subtotalMinor,
            $taxTotalMinor,
            $totalMinor,
            0,
            $issueDate,
            $dueDate,
            json_encode($currencySnapshot),
            json_encode($taxSnapshot),
            $notes,
        ]);

        $invoiceId = (int)$this->db->getPdo()->lastInsertId();

        $items = [];
        foreach ($processedItems as $pItem) {
            $itemSql = sprintf(
                'INSERT INTO %s (invoice_id, description, quantity, unit_amount_minor, subtotal_minor, tax_amount_minor, total_minor, order_item_id, service_id, tax_snapshot_json, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->invoiceItemsTable
            );

            $this->db->statement($itemSql, [
                $invoiceId,
                $pItem['description'],
                $pItem['quantity'],
                $pItem['unit_amount_minor'],
                $pItem['subtotal_minor'],
                $pItem['tax_amount_minor'],
                $pItem['total_minor'],
                $pItem['order_item_id'],
                $pItem['service_id'],
                json_encode($pItem['tax_snapshot']),
                json_encode($pItem['metadata']),
            ]);

            $itemId = (int)$this->db->getPdo()->lastInsertId();
            $items[] = new InvoiceItem(
                id: $itemId,
                invoiceId: $invoiceId,
                description: $pItem['description'],
                quantity: $pItem['quantity'],
                unitAmountMinor: $pItem['unit_amount_minor'],
                subtotalMinor: $pItem['subtotal_minor'],
                taxAmountMinor: $pItem['tax_amount_minor'],
                totalMinor: $pItem['total_minor'],
                orderItemId: $pItem['order_item_id'],
                serviceId: $pItem['service_id'],
                taxSnapshot: $pItem['tax_snapshot'],
                metadata: $pItem['metadata']
            );
        }

        return new Invoice(
            id: $invoiceId,
            invoiceNumber: $invoiceNumber,
            userId: $userId,
            organizationId: $orgId,
            orderId: $orderId,
            status: Invoice::STATUS_UNPAID,
            currencyCode: $currencyCode,
            subtotalMinor: $subtotalMinor,
            taxTotalMinor: $taxTotalMinor,
            totalMinor: $totalMinor,
            paidAmountMinor: 0,
            issueDate: $issueDate,
            dueDate: $dueDate,
            currencySnapshot: $currencySnapshot,
            taxSnapshot: $taxSnapshot,
            notes: $notes,
            items: $items,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * Generate invoice from an existing Order, snapshotting currency and taxes.
     */
    public function createInvoiceFromOrder(Order $order, ?string $dueDate = null): Invoice
    {
        $itemsData = [];
        foreach ($order->getItems() as $oItem) {
            $itemSubtotal = $oItem->getTotalMinor();
            $itemTax = 0;
            if ($order->getSubtotalMinor() > 0 && $order->getTaxTotalMinor() > 0) {
                $itemTax = (int)round(($itemSubtotal / $order->getSubtotalMinor()) * $order->getTaxTotalMinor());
            }

            $itemsData[] = [
                'description' => $oItem->getProductName(),
                'quantity' => $oItem->getQuantity(),
                'unit_amount_minor' => $oItem->getUnitPriceMinor() + $oItem->getUnitSetupFeeMinor(),
                'subtotal_minor' => $itemSubtotal,
                'tax_amount_minor' => $itemTax,
                'total_minor' => $itemSubtotal + $itemTax,
                'order_item_id' => $oItem->getId(),
                'metadata' => $oItem->getMetadata(),
            ];
        }

        return $this->createInvoice(
            [
                'user_id' => $order->getUserId(),
                'organization_id' => $order->getOrganizationId(),
                'order_id' => $order->getId(),
                'currency_code' => $order->getCurrencyCode(),
                'due_date' => $dueDate ?? date('Y-m-d', strtotime('+7 days')),
            ],
            $itemsData
        );
    }

    public function findInvoiceById(int $id): ?Invoice
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->invoicesTable) . $this->db->forUpdate(), [$id]);
        return $row ? $this->hydrateInvoice($row) : null;
    }

    public function findInvoiceByNumber(string $invoiceNumber): ?Invoice
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE invoice_number = ?', $this->invoicesTable), [$invoiceNumber]);
        return $row ? $this->hydrateInvoice($row) : null;
    }

    /**
     * @return array<Invoice>
     */
    public function listInvoicesForUser(int $userId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE user_id = ? ORDER BY id DESC', $this->invoicesTable),
            [$userId]
        );

        return array_map([$this, 'hydrateInvoice'], $rows);
    }

    /**
     * Mark invoice as finalized / updated status.
     */
    public function updateStatus(int $invoiceId, string $status, ?string $paidAt = null): Invoice
    {
        $invoice = $this->findInvoiceById($invoiceId);
        if ($invoice === null) {
            throw new RuntimeException("Invoice {$invoiceId} not found.");
        }

        $sql = sprintf('UPDATE %s SET status = ?, paid_at = ? WHERE id = ?', $this->invoicesTable);
        $this->db->statement($sql, [$status, $paidAt, $invoiceId]);

        return $this->findInvoiceById($invoiceId);
    }

    /**
     * Increment paid amount and adjust invoice status (partially_paid / paid).
     */
    public function applyPayment(int $invoiceId, int $amountMinor, ?string $paidAt = null): Invoice
    {
        return $this->db->transaction(function () use ($invoiceId, $amountMinor, $paidAt) {
            $this->db->lockRow($this->invoicesTable, $invoiceId);
            return $this->applyPaymentLocked($invoiceId, $amountMinor, $paidAt);
        });
    }

    private function applyPaymentLocked(int $invoiceId, int $amountMinor, ?string $paidAt = null): Invoice
    {
        $invoice = $this->findInvoiceById($invoiceId);
        if ($invoice === null) {
            throw new RuntimeException("Invoice {$invoiceId} not found.");
        }

        if ($amountMinor <= 0 || $amountMinor > $invoice->getBalanceDueMinor()) {
            throw new ValidationException(['amount_minor' => 'Payment exceeds invoice balance or is not positive.'], 'Invalid payment amount');
        }
        $newPaidAmount = $invoice->getPaidAmountMinor() + $amountMinor;
        $isFullyPaid = $newPaidAmount >= $invoice->getTotalMinor();
        $newStatus = $isFullyPaid ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;
        $resolvedPaidAt = $isFullyPaid ? ($paidAt ?? date('Y-m-d H:i:s')) : $invoice->getPaidAt();

        $sql = sprintf('UPDATE %s SET paid_amount_minor = ?, status = ?, paid_at = ? WHERE id = ?', $this->invoicesTable);
        $this->db->statement($sql, [$newPaidAmount, $newStatus, $resolvedPaidAt, $invoiceId]);

        return $this->findInvoiceById($invoiceId);
    }

    /**
     * Decrement paid amount and adjust invoice status (partially_paid / unpaid / refunded).
     */
    public function applyRefund(int $invoiceId, int $refundAmountMinor): Invoice
    {
        return $this->db->transaction(function () use ($invoiceId, $refundAmountMinor) {
            $this->db->lockRow($this->invoicesTable, $invoiceId);
            return $this->applyRefundLocked($invoiceId, $refundAmountMinor);
        });
    }

    private function applyRefundLocked(int $invoiceId, int $refundAmountMinor): Invoice
    {
        $invoice = $this->findInvoiceById($invoiceId);
        if ($invoice === null) {
            throw new RuntimeException("Invoice {$invoiceId} not found.");
        }

        if ($refundAmountMinor <= 0 || $refundAmountMinor > $invoice->getPaidAmountMinor()) {
            throw new ValidationException(['amount_minor' => 'Refund exceeds paid invoice balance or is not positive.'], 'Invalid refund amount');
        }
        $newPaidAmount = $invoice->getPaidAmountMinor() - $refundAmountMinor;
        $newStatus = match (true) {
            $newPaidAmount === 0 && $invoice->getPaidAmountMinor() > 0 => Invoice::STATUS_REFUNDED,
            $newPaidAmount > 0 => Invoice::STATUS_PARTIALLY_PAID,
            default => Invoice::STATUS_UNPAID,
        };

        $sql = sprintf('UPDATE %s SET paid_amount_minor = ?, status = ? WHERE id = ?', $this->invoicesTable);
        $this->db->statement($sql, [$newPaidAmount, $newStatus, $invoiceId]);

        return $this->findInvoiceById($invoiceId);
    }

    /**
     * Generate sequential invoice number (e.g. INV-2026-000001).
     */
    public function nextInvoiceNumber(): string
    {
        $year = date('Y');

        $num = $this->db->nextSequence($this->numberSequenceTable, 'year_prefix', $year);
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "INV-{$year}-{$formattedNum}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateInvoice(array $row): Invoice
    {
        $invoiceId = (int)$row['id'];
        $itemRows = $this->db->select(sprintf('SELECT * FROM %s WHERE invoice_id = ?', $this->invoiceItemsTable), [$invoiceId]);

        $items = [];
        foreach ($itemRows as $iRow) {
            $taxSnap = !empty($iRow['tax_snapshot_json']) ? json_decode((string)$iRow['tax_snapshot_json'], true) : [];
            $meta = !empty($iRow['metadata_json']) ? json_decode((string)$iRow['metadata_json'], true) : [];

            $items[] = new InvoiceItem(
                id: (int)$iRow['id'],
                invoiceId: (int)$iRow['invoice_id'],
                description: (string)$iRow['description'],
                quantity: (int)$iRow['quantity'],
                unitAmountMinor: (int)$iRow['unit_amount_minor'],
                subtotalMinor: (int)$iRow['subtotal_minor'],
                taxAmountMinor: (int)$iRow['tax_amount_minor'],
                totalMinor: (int)$iRow['total_minor'],
                orderItemId: $iRow['order_item_id'] !== null ? (int)$iRow['order_item_id'] : null,
                serviceId: $iRow['service_id'] !== null ? (int)$iRow['service_id'] : null,
                taxSnapshot: is_array($taxSnap) ? $taxSnap : [],
                metadata: is_array($meta) ? $meta : []
            );
        }

        $currencySnapshot = !empty($row['currency_snapshot_json']) ? json_decode((string)$row['currency_snapshot_json'], true) : [];
        $taxSnapshot = !empty($row['tax_snapshot_json']) ? json_decode((string)$row['tax_snapshot_json'], true) : [];

        return new Invoice(
            id: $invoiceId,
            invoiceNumber: (string)$row['invoice_number'],
            userId: (int)$row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            orderId: $row['order_id'] !== null ? (int)$row['order_id'] : null,
            status: (string)$row['status'],
            currencyCode: (string)$row['currency_code'],
            subtotalMinor: (int)$row['subtotal_minor'],
            taxTotalMinor: (int)$row['tax_total_minor'],
            totalMinor: (int)$row['total_minor'],
            paidAmountMinor: (int)$row['paid_amount_minor'],
            issueDate: (string)$row['issue_date'],
            dueDate: (string)$row['due_date'],
            paidAt: $row['paid_at'] !== null ? (string)$row['paid_at'] : null,
            currencySnapshot: is_array($currencySnapshot) ? $currencySnapshot : [],
            taxSnapshot: is_array($taxSnapshot) ? $taxSnapshot : [],
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            items: $items,
            createdAt: (string)$row['created_at']
        );
    }
}
