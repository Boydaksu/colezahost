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
     * Generate invoice from an existing Order, snapshotting currency and taxes.
     */
    public function createInvoiceFromOrder(Order $order, ?string $dueDate = null): Invoice
    {
        $currencyCode = $order->getCurrencyCode();
        $issueDate = date('Y-m-d');
        $due = $dueDate ?? date('Y-m-d', strtotime('+7 days'));

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

        // 2. Snapshot taxes
        $taxSnapshot = [
            'tax_total_minor' => $order->getTaxTotalMinor(),
            'captured_at' => date('Y-m-d H:i:s'),
        ];

        $invoiceNumber = $this->nextInvoiceNumber();

        $sql = sprintf(
            'INSERT INTO %s (invoice_number, user_id, organization_id, order_id, status, currency_code, subtotal_minor, tax_total_minor, total_minor, paid_amount_minor, issue_date, due_date, currency_snapshot_json, tax_snapshot_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->invoicesTable
        );

        $this->db->statement($sql, [
            $invoiceNumber,
            $order->getUserId(),
            $order->getOrganizationId(),
            $order->getId(),
            Invoice::STATUS_UNPAID,
            $currencyCode,
            $order->getSubtotalMinor(),
            $order->getTaxTotalMinor(),
            $order->getTotalMinor(),
            0,
            $issueDate,
            $due,
            json_encode($currencySnapshot),
            json_encode($taxSnapshot),
        ]);

        $invoiceId = (int)$this->db->getPdo()->lastInsertId();

        // Populate items
        $items = [];
        foreach ($order->getItems() as $oItem) {
            $itemSubtotal = $oItem->getTotalMinor();
            // Prorate item tax proportionally if order has tax
            $itemTax = 0;
            if ($order->getSubtotalMinor() > 0 && $order->getTaxTotalMinor() > 0) {
                $itemTax = (int)round(($itemSubtotal / $order->getSubtotalMinor()) * $order->getTaxTotalMinor());
            }
            $itemTotal = $itemSubtotal + $itemTax;

            $itemSql = sprintf(
                'INSERT INTO %s (invoice_id, description, quantity, unit_amount_minor, subtotal_minor, tax_amount_minor, total_minor, order_item_id, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->invoiceItemsTable
            );

            $this->db->statement($itemSql, [
                $invoiceId,
                $oItem->getProductName(),
                $oItem->getQuantity(),
                $oItem->getUnitPriceMinor() + $oItem->getUnitSetupFeeMinor(),
                $itemSubtotal,
                $itemTax,
                $itemTotal,
                $oItem->getId(),
                json_encode($oItem->getMetadata()),
            ]);

            $itemId = (int)$this->db->getPdo()->lastInsertId();
            $items[] = new InvoiceItem(
                id: $itemId,
                invoiceId: $invoiceId,
                description: $oItem->getProductName(),
                quantity: $oItem->getQuantity(),
                unitAmountMinor: $oItem->getUnitPriceMinor() + $oItem->getUnitSetupFeeMinor(),
                subtotalMinor: $itemSubtotal,
                taxAmountMinor: $itemTax,
                totalMinor: $itemTotal,
                orderItemId: $oItem->getId(),
                metadata: $oItem->getMetadata()
            );
        }

        return new Invoice(
            id: $invoiceId,
            invoiceNumber: $invoiceNumber,
            userId: $order->getUserId(),
            organizationId: $order->getOrganizationId(),
            orderId: $order->getId(),
            status: Invoice::STATUS_UNPAID,
            currencyCode: $currencyCode,
            subtotalMinor: $order->getSubtotalMinor(),
            taxTotalMinor: $order->getTaxTotalMinor(),
            totalMinor: $order->getTotalMinor(),
            paidAmountMinor: 0,
            issueDate: $issueDate,
            dueDate: $due,
            currencySnapshot: $currencySnapshot,
            taxSnapshot: $taxSnapshot,
            items: $items,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findInvoiceById(int $id): ?Invoice
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->invoicesTable), [$id]);
        return $row ? $this->hydrateInvoice($row) : null;
    }

    public function findInvoiceByNumber(string $invoiceNumber): ?Invoice
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE invoice_number = ?', $this->invoicesTable), [$invoiceNumber]);
        return $row ? $this->hydrateInvoice($row) : null;
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
     * Generate sequential invoice number (e.g. INV-2026-000001).
     */
    public function nextInvoiceNumber(): string
    {
        $year = date('Y');

        $this->db->statement(
            sprintf(
                'INSERT INTO %s (year_prefix, last_number) VALUES (?, 1)
                 ON CONFLICT(year_prefix) DO UPDATE SET last_number = last_number + 1',
                $this->numberSequenceTable
            ),
            [$year]
        );

        $row = $this->db->selectOne(
            sprintf('SELECT last_number FROM %s WHERE year_prefix = ?', $this->numberSequenceTable),
            [$year]
        );

        $num = $row ? (int)$row['last_number'] : 1;
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
