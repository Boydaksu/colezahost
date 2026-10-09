<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Proforma;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Numbering\DocumentNumberGenerator;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Database\PdoSchema;
use PDO;
use RuntimeException;

final class ProformaService
{
    private DocumentNumberGenerator $numberGenerator;

    /**
     * @var array<int, ProformaInvoice> In-memory cache when PDO is null
     */
    private array $memoryProformas = [];

    public function __construct(
        private ?PDO $pdo = null,
        ?DocumentNumberGenerator $numberGenerator = null
    ) {
        if ($this->pdo !== null) { PdoSchema::autoIncrement($this->pdo); }
        $this->numberGenerator = $numberGenerator ?? new DocumentNumberGenerator($this->pdo);

        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    /**
     * @param int $userId
     * @param array<array<string, mixed>> $itemsData
     * @param string $currencyCode
     * @param string|null $issueDate
     * @param string|null $dueDate
     * @param int|null $organizationId
     * @param int|null $orderId
     * @param string|null $notes
     * @return ProformaInvoice
     */
    public function createProforma(
        int $userId,
        array $itemsData,
        string $currencyCode = 'TRY',
        ?string $issueDate = null,
        ?string $dueDate = null,
        ?int $organizationId = null,
        ?int $orderId = null,
        ?string $notes = null
    ): ProformaInvoice {
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['Valid user ID is required.']], 'Invalid user');
        }

        if (empty($itemsData)) {
            throw new ValidationException(['items' => ['At least one item is required.']], 'Empty items');
        }

        $formattedIssueDate = $issueDate ?? date('Y-m-d');
        $formattedDueDate = $dueDate ?? date('Y-m-d', strtotime('+7 days'));

        $proformaNumber = $this->numberGenerator->generateNextNumber(
            DocumentType::PROFORMA,
            $organizationId !== null ? (string) $organizationId : '1'
        );

        $computedItems = [];
        $subtotalMinor = 0;
        $taxTotalMinor = 0;

        foreach ($itemsData as $data) {
            $qty = max(1, (int) ($data['quantity'] ?? 1));
            $unitAmount = (int) ($data['unit_amount_minor'] ?? 0);
            $lineSubtotal = $qty * $unitAmount;
            $taxRate = (float) ($data['tax_rate'] ?? 0.0);
            $lineTax = (int) round(($lineSubtotal * $taxRate) / 100.0);
            $lineTotal = $lineSubtotal + $lineTax;

            $computedItems[] = new ProformaItem(
                id: null,
                proformaId: null,
                description: (string) ($data['description'] ?? 'Item'),
                quantity: $qty,
                unitAmountMinor: $unitAmount,
                subtotalMinor: $lineSubtotal,
                taxRate: $taxRate,
                taxAmountMinor: $lineTax,
                totalMinor: $lineTotal,
                metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
            );

            $subtotalMinor += $lineSubtotal;
            $taxTotalMinor += $lineTax;
        }

        $totalMinor = $subtotalMinor + $taxTotalMinor;
        $now = date('c');

        $proforma = new ProformaInvoice(
            id: null,
            proformaNumber: $proformaNumber,
            userId: $userId,
            organizationId: $organizationId,
            orderId: $orderId,
            status: ProformaStatus::DRAFT,
            currencyCode: strtoupper($currencyCode),
            subtotalMinor: $subtotalMinor,
            taxTotalMinor: $taxTotalMinor,
            totalMinor: $totalMinor,
            paidAmountMinor: 0,
            issueDate: $formattedIssueDate,
            dueDate: $formattedDueDate,
            paidAt: null,
            convertedInvoiceId: null,
            convertedInvoiceNumber: null,
            notes: $notes,
            items: $computedItems,
            createdAt: $now,
            updatedAt: $now
        );

        return $this->persistProforma($proforma);
    }

    public function issueProforma(int $id): ProformaInvoice
    {
        $proforma = $this->find($id);
        if ($proforma === null) {
            throw new RuntimeException("Proforma invoice #{$id} not found.");
        }

        if ($proforma->getStatus() !== ProformaStatus::DRAFT) {
            throw new RuntimeException("Only draft proformas can be issued.");
        }

        $now = date('c');
        $updated = new ProformaInvoice(
            id: $proforma->getId(),
            proformaNumber: $proforma->getProformaNumber(),
            userId: $proforma->getUserId(),
            organizationId: $proforma->getOrganizationId(),
            orderId: $proforma->getOrderId(),
            status: ProformaStatus::ISSUED,
            currencyCode: $proforma->getCurrencyCode(),
            subtotalMinor: $proforma->getSubtotalMinor(),
            taxTotalMinor: $proforma->getTaxTotalMinor(),
            totalMinor: $proforma->getTotalMinor(),
            paidAmountMinor: $proforma->getPaidAmountMinor(),
            issueDate: $proforma->getIssueDate(),
            dueDate: $proforma->getDueDate(),
            paidAt: $proforma->getPaidAt(),
            convertedInvoiceId: $proforma->getConvertedInvoiceId(),
            convertedInvoiceNumber: $proforma->getConvertedInvoiceNumber(),
            notes: $proforma->getNotes(),
            items: $proforma->getItems(),
            createdAt: $proforma->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateProforma($updated);
    }

    public function recordPayment(int $id, int $amountMinor, ?string $paidAt = null): ProformaInvoice
    {
        $proforma = $this->find($id);
        if ($proforma === null) {
            throw new RuntimeException("Proforma invoice #{$id} not found.");
        }

        if ($proforma->getStatus() === ProformaStatus::CANCELLED) {
            throw new RuntimeException("Cannot apply payment to a cancelled proforma invoice.");
        }

        if ($amountMinor <= 0) {
            throw new ValidationException(['amount' => ['Payment amount must be greater than zero.']], 'Invalid amount');
        }

        $newPaidMinor = $proforma->getPaidAmountMinor() + $amountMinor;
        $isPaid = $newPaidMinor >= $proforma->getTotalMinor();
        $now = date('c');
        $resolvedPaidAt = $isPaid ? ($paidAt ?? $now) : $proforma->getPaidAt();
        $newStatus = $isPaid ? ProformaStatus::PAID : $proforma->getStatus();

        $updated = new ProformaInvoice(
            id: $proforma->getId(),
            proformaNumber: $proforma->getProformaNumber(),
            userId: $proforma->getUserId(),
            organizationId: $proforma->getOrganizationId(),
            orderId: $proforma->getOrderId(),
            status: $newStatus,
            currencyCode: $proforma->getCurrencyCode(),
            subtotalMinor: $proforma->getSubtotalMinor(),
            taxTotalMinor: $proforma->getTaxTotalMinor(),
            totalMinor: $proforma->getTotalMinor(),
            paidAmountMinor: $newPaidMinor,
            issueDate: $proforma->getIssueDate(),
            dueDate: $proforma->getDueDate(),
            paidAt: $resolvedPaidAt,
            convertedInvoiceId: $proforma->getConvertedInvoiceId(),
            convertedInvoiceNumber: $proforma->getConvertedInvoiceNumber(),
            notes: $proforma->getNotes(),
            items: $proforma->getItems(),
            createdAt: $proforma->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateProforma($updated);
    }

    /**
     * Converts a settled or issued Proforma Invoice into a legal fiscal Tax Invoice via InvoiceService.
     *
     * @param int $id
     * @param InvoiceService $invoiceService
     * @return array{proforma: ProformaInvoice, invoice: Invoice}
     */
    public function convertToTaxInvoice(int $id, InvoiceService $invoiceService): array
    {
        $proforma = $this->find($id);
        if ($proforma === null) {
            throw new RuntimeException("Proforma invoice #{$id} not found.");
        }

        if ($proforma->getStatus() === ProformaStatus::CANCELLED) {
            throw new RuntimeException("Cannot convert cancelled proforma invoice.");
        }

        if ($proforma->getStatus() === ProformaStatus::CONVERTED) {
            throw new RuntimeException("Proforma invoice #{$id} is already converted to Invoice #{$proforma->getConvertedInvoiceNumber()}.");
        }

        // Map proforma items to Invoice items data
        $invoiceItemsData = [];
        foreach ($proforma->getItems() as $item) {
            $invoiceItemsData[] = [
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit_amount_minor' => $item->getUnitAmountMinor(),
                'tax_rate' => $item->getTaxRate(),
            ];
        }

        $invoiceData = [
            'user_id' => $proforma->getUserId(),
            'organization_id' => $proforma->getOrganizationId(),
            'order_id' => $proforma->getOrderId(),
            'currency_code' => $proforma->getCurrencyCode(),
            'issue_date' => date('Y-m-d'),
            'due_date' => $proforma->getDueDate(),
            'notes' => "Converted from Proforma Invoice {$proforma->getProformaNumber()}",
        ];

        $invoice = $invoiceService->createInvoice($invoiceData, $invoiceItemsData);

        // Update proforma status to CONVERTED
        $now = date('c');
        $updated = new ProformaInvoice(
            id: $proforma->getId(),
            proformaNumber: $proforma->getProformaNumber(),
            userId: $proforma->getUserId(),
            organizationId: $proforma->getOrganizationId(),
            orderId: $proforma->getOrderId(),
            status: ProformaStatus::CONVERTED,
            currencyCode: $proforma->getCurrencyCode(),
            subtotalMinor: $proforma->getSubtotalMinor(),
            taxTotalMinor: $proforma->getTaxTotalMinor(),
            totalMinor: $proforma->getTotalMinor(),
            paidAmountMinor: $proforma->getPaidAmountMinor(),
            issueDate: $proforma->getIssueDate(),
            dueDate: $proforma->getDueDate(),
            paidAt: $proforma->getPaidAt(),
            convertedInvoiceId: $invoice->getId(),
            convertedInvoiceNumber: $invoice->getInvoiceNumber(),
            notes: $proforma->getNotes(),
            items: $proforma->getItems(),
            createdAt: $proforma->getCreatedAt(),
            updatedAt: $now
        );

        $savedProforma = $this->updateProforma($updated);

        return [
            'proforma' => $savedProforma,
            'invoice' => $invoice,
        ];
    }

    public function cancelProforma(int $id, ?string $reason = null): ProformaInvoice
    {
        $proforma = $this->find($id);
        if ($proforma === null) {
            throw new RuntimeException("Proforma invoice #{$id} not found.");
        }

        if ($proforma->getStatus() === ProformaStatus::CONVERTED) {
            throw new RuntimeException("Cannot cancel an already converted proforma invoice.");
        }

        $now = date('c');
        $notes = $proforma->getNotes();
        if ($reason !== null) {
            $notes = ($notes ? $notes . ' | ' : '') . "Cancellation reason: {$reason}";
        }

        $updated = new ProformaInvoice(
            id: $proforma->getId(),
            proformaNumber: $proforma->getProformaNumber(),
            userId: $proforma->getUserId(),
            organizationId: $proforma->getOrganizationId(),
            orderId: $proforma->getOrderId(),
            status: ProformaStatus::CANCELLED,
            currencyCode: $proforma->getCurrencyCode(),
            subtotalMinor: $proforma->getSubtotalMinor(),
            taxTotalMinor: $proforma->getTaxTotalMinor(),
            totalMinor: $proforma->getTotalMinor(),
            paidAmountMinor: $proforma->getPaidAmountMinor(),
            issueDate: $proforma->getIssueDate(),
            dueDate: $proforma->getDueDate(),
            paidAt: $proforma->getPaidAt(),
            convertedInvoiceId: $proforma->getConvertedInvoiceId(),
            convertedInvoiceNumber: $proforma->getConvertedInvoiceNumber(),
            notes: $notes,
            items: $proforma->getItems(),
            createdAt: $proforma->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateProforma($updated);
    }

    public function find(int $id): ?ProformaInvoice
    {
        if ($this->pdo === null) {
            return $this->memoryProformas[$id] ?? null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM proforma_invoices WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapProformaRow($row) : null;
    }

    public function findByProformaNumber(string $number): ?ProformaInvoice
    {
        if ($this->pdo === null) {
            foreach ($this->memoryProformas as $p) {
                if ($p->getProformaNumber() === $number) {
                    return $p;
                }
            }
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM proforma_invoices WHERE proforma_number = :number');
        $stmt->execute([':number' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapProformaRow($row) : null;
    }

    private function persistProforma(ProformaInvoice $proforma): ProformaInvoice
    {
        if ($this->pdo === null) {
            $id = count($this->memoryProformas) + 1;
            $itemsWithId = [];
            foreach ($proforma->getItems() as $idx => $item) {
                $itemsWithId[] = new ProformaItem(
                    id: $idx + 1,
                    proformaId: $id,
                    description: $item->getDescription(),
                    quantity: $item->getQuantity(),
                    unitAmountMinor: $item->getUnitAmountMinor(),
                    subtotalMinor: $item->getSubtotalMinor(),
                    taxRate: $item->getTaxRate(),
                    taxAmountMinor: $item->getTaxAmountMinor(),
                    totalMinor: $item->getTotalMinor(),
                    metadata: $item->getMetadata()
                );
            }

            $saved = new ProformaInvoice(
                id: $id,
                proformaNumber: $proforma->getProformaNumber(),
                userId: $proforma->getUserId(),
                organizationId: $proforma->getOrganizationId(),
                orderId: $proforma->getOrderId(),
                status: $proforma->getStatus(),
                currencyCode: $proforma->getCurrencyCode(),
                subtotalMinor: $proforma->getSubtotalMinor(),
                taxTotalMinor: $proforma->getTaxTotalMinor(),
                totalMinor: $proforma->getTotalMinor(),
                paidAmountMinor: $proforma->getPaidAmountMinor(),
                issueDate: $proforma->getIssueDate(),
                dueDate: $proforma->getDueDate(),
                paidAt: $proforma->getPaidAt(),
                convertedInvoiceId: $proforma->getConvertedInvoiceId(),
                convertedInvoiceNumber: $proforma->getConvertedInvoiceNumber(),
                notes: $proforma->getNotes(),
                items: $itemsWithId,
                createdAt: $proforma->getCreatedAt(),
                updatedAt: $proforma->getUpdatedAt()
            );

            $this->memoryProformas[$id] = $saved;
            return $saved;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO proforma_invoices (
                    proforma_number, user_id, organization_id, order_id, status,
                    currency_code, subtotal_minor, tax_total_minor, total_minor,
                    paid_amount_minor, issue_date, due_date, paid_at,
                    converted_invoice_id, converted_invoice_number, notes,
                    created_at, updated_at
                ) VALUES (
                    :proforma_number, :user_id, :organization_id, :order_id, :status,
                    :currency_code, :subtotal_minor, :tax_total_minor, :total_minor,
                    :paid_amount_minor, :issue_date, :due_date, :paid_at,
                    :converted_invoice_id, :converted_invoice_number, :notes,
                    :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':proforma_number' => $proforma->getProformaNumber(),
                ':user_id' => $proforma->getUserId(),
                ':organization_id' => $proforma->getOrganizationId(),
                ':order_id' => $proforma->getOrderId(),
                ':status' => $proforma->getStatus()->value,
                ':currency_code' => $proforma->getCurrencyCode(),
                ':subtotal_minor' => $proforma->getSubtotalMinor(),
                ':tax_total_minor' => $proforma->getTaxTotalMinor(),
                ':total_minor' => $proforma->getTotalMinor(),
                ':paid_amount_minor' => $proforma->getPaidAmountMinor(),
                ':issue_date' => $proforma->getIssueDate(),
                ':due_date' => $proforma->getDueDate(),
                ':paid_at' => $proforma->getPaidAt(),
                ':converted_invoice_id' => $proforma->getConvertedInvoiceId(),
                ':converted_invoice_number' => $proforma->getConvertedInvoiceNumber(),
                ':notes' => $proforma->getNotes(),
                ':created_at' => $proforma->getCreatedAt(),
                ':updated_at' => $proforma->getUpdatedAt(),
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO proforma_items (
                    proforma_id, description, quantity, unit_amount_minor, subtotal_minor,
                    tax_rate, tax_amount_minor, total_minor, metadata_json
                ) VALUES (
                    :proforma_id, :description, :quantity, :unit_amount_minor, :subtotal_minor,
                    :tax_rate, :tax_amount_minor, :total_minor, :metadata_json
                )'
            );

            $savedItems = [];
            foreach ($proforma->getItems() as $item) {
                $itemStmt->execute([
                    ':proforma_id' => $id,
                    ':description' => $item->getDescription(),
                    ':quantity' => $item->getQuantity(),
                    ':unit_amount_minor' => $item->getUnitAmountMinor(),
                    ':subtotal_minor' => $item->getSubtotalMinor(),
                    ':tax_rate' => $item->getTaxRate(),
                    ':tax_amount_minor' => $item->getTaxAmountMinor(),
                    ':total_minor' => $item->getTotalMinor(),
                    ':metadata_json' => json_encode($item->getMetadata()),
                ]);
                $itemId = (int) $this->pdo->lastInsertId();
                $savedItems[] = new ProformaItem(
                    id: $itemId,
                    proformaId: $id,
                    description: $item->getDescription(),
                    quantity: $item->getQuantity(),
                    unitAmountMinor: $item->getUnitAmountMinor(),
                    subtotalMinor: $item->getSubtotalMinor(),
                    taxRate: $item->getTaxRate(),
                    taxAmountMinor: $item->getTaxAmountMinor(),
                    totalMinor: $item->getTotalMinor(),
                    metadata: $item->getMetadata()
                );
            }

            $this->pdo->commit();

            return new ProformaInvoice(
                id: $id,
                proformaNumber: $proforma->getProformaNumber(),
                userId: $proforma->getUserId(),
                organizationId: $proforma->getOrganizationId(),
                orderId: $proforma->getOrderId(),
                status: $proforma->getStatus(),
                currencyCode: $proforma->getCurrencyCode(),
                subtotalMinor: $proforma->getSubtotalMinor(),
                taxTotalMinor: $proforma->getTaxTotalMinor(),
                totalMinor: $proforma->getTotalMinor(),
                paidAmountMinor: $proforma->getPaidAmountMinor(),
                issueDate: $proforma->getIssueDate(),
                dueDate: $proforma->getDueDate(),
                paidAt: $proforma->getPaidAt(),
                convertedInvoiceId: $proforma->getConvertedInvoiceId(),
                convertedInvoiceNumber: $proforma->getConvertedInvoiceNumber(),
                notes: $proforma->getNotes(),
                items: $savedItems,
                createdAt: $proforma->getCreatedAt(),
                updatedAt: $proforma->getUpdatedAt()
            );
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function updateProforma(ProformaInvoice $proforma): ProformaInvoice
    {
        if ($proforma->getId() === null) {
            throw new RuntimeException('Cannot update proforma without ID.');
        }

        if ($this->pdo === null) {
            $this->memoryProformas[$proforma->getId()] = $proforma;
            return $proforma;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE proforma_invoices SET
                status = :status,
                paid_amount_minor = :paid_amount_minor,
                paid_at = :paid_at,
                converted_invoice_id = :converted_invoice_id,
                converted_invoice_number = :converted_invoice_number,
                notes = :notes,
                updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $proforma->getStatus()->value,
            ':paid_amount_minor' => $proforma->getPaidAmountMinor(),
            ':paid_at' => $proforma->getPaidAt(),
            ':converted_invoice_id' => $proforma->getConvertedInvoiceId(),
            ':converted_invoice_number' => $proforma->getConvertedInvoiceNumber(),
            ':notes' => $proforma->getNotes(),
            ':updated_at' => $proforma->getUpdatedAt(),
            ':id' => $proforma->getId(),
        ]);

        return $proforma;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapProformaRow(array $row): ProformaInvoice
    {
        $id = (int) $row['id'];
        $items = [];

        if ($this->pdo !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM proforma_items WHERE proforma_id = :proforma_id');
            $stmt->execute([':proforma_id' => $id]);
            while ($itemRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = new ProformaItem(
                    id: (int) $itemRow['id'],
                    proformaId: $id,
                    description: (string) $itemRow['description'],
                    quantity: (int) $itemRow['quantity'],
                    unitAmountMinor: (int) $itemRow['unit_amount_minor'],
                    subtotalMinor: (int) $itemRow['subtotal_minor'],
                    taxRate: (float) $itemRow['tax_rate'],
                    taxAmountMinor: (int) $itemRow['tax_amount_minor'],
                    totalMinor: (int) $itemRow['total_minor'],
                    metadata: !empty($itemRow['metadata_json']) ? json_decode((string) $itemRow['metadata_json'], true) : []
                );
            }
        }

        return new ProformaInvoice(
            id: $id,
            proformaNumber: (string) $row['proforma_number'],
            userId: (int) $row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int) $row['organization_id'] : null,
            orderId: $row['order_id'] !== null ? (int) $row['order_id'] : null,
            status: ProformaStatus::from((string) $row['status']),
            currencyCode: (string) $row['currency_code'],
            subtotalMinor: (int) $row['subtotal_minor'],
            taxTotalMinor: (int) $row['tax_total_minor'],
            totalMinor: (int) $row['total_minor'],
            paidAmountMinor: (int) $row['paid_amount_minor'],
            issueDate: (string) $row['issue_date'],
            dueDate: (string) $row['due_date'],
            paidAt: $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            convertedInvoiceId: $row['converted_invoice_id'] !== null ? (int) $row['converted_invoice_id'] : null,
            convertedInvoiceNumber: $row['converted_invoice_number'] !== null ? (string) $row['converted_invoice_number'] : null,
            notes: $row['notes'] !== null ? (string) $row['notes'] : null,
            items: $items,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $id = PdoSchema::autoIncrement($this->pdo);
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS proforma_invoices (
                id {$id},
                proforma_number VARCHAR(64) NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                organization_id INTEGER,
                order_id INTEGER,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
                currency_code VARCHAR(3) NOT NULL,
                subtotal_minor INTEGER NOT NULL,
                tax_total_minor INTEGER NOT NULL,
                total_minor INTEGER NOT NULL,
                paid_amount_minor INTEGER NOT NULL DEFAULT 0,
                issue_date VARCHAR(32) NOT NULL,
                due_date VARCHAR(32) NOT NULL,
                paid_at VARCHAR(64),
                converted_invoice_id INTEGER,
                converted_invoice_number VARCHAR(64),
                notes TEXT,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS proforma_items (
                id {$id},
                proforma_id INTEGER NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                unit_amount_minor INTEGER NOT NULL,
                subtotal_minor INTEGER NOT NULL,
                tax_rate REAL NOT NULL DEFAULT 0.0,
                tax_amount_minor INTEGER NOT NULL DEFAULT 0,
                total_minor INTEGER NOT NULL,
                metadata_json TEXT
            )"
        );
    }
}
