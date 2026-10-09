<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Quotes;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Numbering\DocumentNumberGenerator;
use Coleza\Domain\Documents\Snapshots\DocumentSnapshotService;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Foundation\Database\PdoSchema;
use PDO;
use RuntimeException;

final class QuoteService
{
    private QuoteStateMachine $stateMachine;
    private DocumentNumberGenerator $numberGenerator;

    /**
     * @var array<int, Quote> In-memory cache when PDO is null
     */
    private array $memoryQuotes = [];

    public function __construct(
        private ?PDO $pdo = null,
        ?DocumentNumberGenerator $numberGenerator = null,
        ?QuoteStateMachine $stateMachine = null,
        private ?DocumentSnapshotService $snapshotService = null
    ) {
        if ($this->pdo !== null) { PdoSchema::autoIncrement($this->pdo); }
        $this->numberGenerator = $numberGenerator ?? new DocumentNumberGenerator($this->pdo);
        $this->stateMachine = $stateMachine ?? new QuoteStateMachine();

        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    /**
     * @param int $userId
     * @param array<array<string, mixed>> $itemsData
     * @param string $currencyCode
     * @param string $validUntil Date string (YYYY-MM-DD) or relative expression (+14 days)
     * @param int|null $organizationId
     * @param string|null $notes
     * @return Quote
     */
    public function createQuote(
        int $userId,
        array $itemsData,
        string $currencyCode = 'TRY',
        string $validUntil = '+14 days',
        ?int $organizationId = null,
        ?string $notes = null
    ): Quote {
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['Valid user ID is required.']], 'Invalid user');
        }

        if (empty($itemsData)) {
            throw new ValidationException(['items' => ['At least one quote item is required.']], 'Empty items');
        }

        $formattedValidUntil = date('Y-m-d', strtotime($validUntil) ?: time());
        $quoteNumber = $this->numberGenerator->generateNextNumber(
            DocumentType::QUOTE,
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

            $computedItems[] = new QuoteItem(
                id: null,
                quoteId: null,
                description: (string) ($data['description'] ?? 'Item'),
                quantity: $qty,
                unitAmountMinor: $unitAmount,
                subtotalMinor: $lineSubtotal,
                taxRate: $taxRate,
                taxAmountMinor: $lineTax,
                totalMinor: $lineTotal,
                productId: isset($data['product_id']) ? (int) $data['product_id'] : null,
                billingCycle: (string) ($data['billing_cycle'] ?? 'monthly'),
                metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
            );

            $subtotalMinor += $lineSubtotal;
            $taxTotalMinor += $lineTax;
        }

        $totalMinor = $subtotalMinor + $taxTotalMinor;
        $now = date('c');

        $quote = new Quote(
            id: null,
            quoteNumber: $quoteNumber,
            originalQuoteNumber: $quoteNumber,
            version: 1,
            userId: $userId,
            organizationId: $organizationId,
            status: QuoteStatus::DRAFT,
            currencyCode: strtoupper($currencyCode),
            subtotalMinor: $subtotalMinor,
            taxTotalMinor: $taxTotalMinor,
            totalMinor: $totalMinor,
            validUntil: $formattedValidUntil,
            sentAt: null,
            acceptedAt: null,
            acceptedIp: null,
            rejectedAt: null,
            rejectionReason: null,
            convertedOrderId: null,
            revisionNote: null,
            notes: $notes,
            items: $computedItems,
            createdAt: $now,
            updatedAt: $now
        );

        return $this->persistQuote($quote);
    }

    /**
     * Creates a new revised quote incrementing the version number.
     *
     * @param int $quoteId
     * @param array<array<string, mixed>> $itemsData
     * @param string $revisionNote
     * @param string|null $validUntil
     * @return Quote
     */
    public function createRevision(
        int $quoteId,
        array $itemsData,
        string $revisionNote,
        ?string $validUntil = null
    ): Quote {
        $parent = $this->find($quoteId);
        if ($parent === null) {
            throw new RuntimeException("Parent quote ID {$quoteId} not found.");
        }

        if (trim($revisionNote) === '') {
            throw new ValidationException(['revision_note' => ['Revision note is required.']], 'Invalid revision');
        }

        $nextVersion = $parent->getVersion() + 1;
        $formattedValidUntil = $validUntil !== null 
            ? date('Y-m-d', strtotime($validUntil) ?: time()) 
            : $parent->getValidUntil();

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

            $computedItems[] = new QuoteItem(
                id: null,
                quoteId: null,
                description: (string) ($data['description'] ?? 'Item'),
                quantity: $qty,
                unitAmountMinor: $unitAmount,
                subtotalMinor: $lineSubtotal,
                taxRate: $taxRate,
                taxAmountMinor: $lineTax,
                totalMinor: $lineTotal,
                productId: isset($data['product_id']) ? (int) $data['product_id'] : null,
                billingCycle: (string) ($data['billing_cycle'] ?? 'monthly'),
                metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
            );

            $subtotalMinor += $lineSubtotal;
            $taxTotalMinor += $lineTax;
        }

        $totalMinor = $subtotalMinor + $taxTotalMinor;
        $now = date('c');

        $revisedQuoteNumber = sprintf('%s-R%d', $parent->getOriginalQuoteNumber(), $nextVersion);

        $revision = new Quote(
            id: null,
            quoteNumber: $revisedQuoteNumber,
            originalQuoteNumber: $parent->getOriginalQuoteNumber(),
            version: $nextVersion,
            userId: $parent->getUserId(),
            organizationId: $parent->getOrganizationId(),
            status: QuoteStatus::DRAFT,
            currencyCode: $parent->getCurrencyCode(),
            subtotalMinor: $subtotalMinor,
            taxTotalMinor: $taxTotalMinor,
            totalMinor: $totalMinor,
            validUntil: $formattedValidUntil,
            sentAt: null,
            acceptedAt: null,
            acceptedIp: null,
            rejectedAt: null,
            rejectionReason: null,
            convertedOrderId: null,
            revisionNote: $revisionNote,
            notes: $parent->getNotes(),
            items: $computedItems,
            createdAt: $now,
            updatedAt: $now
        );

        return $this->persistQuote($revision);
    }

    public function sendQuote(int $quoteId): Quote
    {
        $quote = $this->find($quoteId);
        if ($quote === null) {
            throw new RuntimeException("Quote #{$quoteId} not found.");
        }

        $newStatus = $this->stateMachine->transition($quote->getStatus(), QuoteStatus::SENT);
        $now = date('c');

        $updated = new Quote(
            id: $quote->getId(),
            quoteNumber: $quote->getQuoteNumber(),
            originalQuoteNumber: $quote->getOriginalQuoteNumber(),
            version: $quote->getVersion(),
            userId: $quote->getUserId(),
            organizationId: $quote->getOrganizationId(),
            status: $newStatus,
            currencyCode: $quote->getCurrencyCode(),
            subtotalMinor: $quote->getSubtotalMinor(),
            taxTotalMinor: $quote->getTaxTotalMinor(),
            totalMinor: $quote->getTotalMinor(),
            validUntil: $quote->getValidUntil(),
            sentAt: $now,
            acceptedAt: $quote->getAcceptedAt(),
            acceptedIp: $quote->getAcceptedIp(),
            rejectedAt: $quote->getRejectedAt(),
            rejectionReason: $quote->getRejectionReason(),
            convertedOrderId: $quote->getConvertedOrderId(),
            revisionNote: $quote->getRevisionNote(),
            notes: $quote->getNotes(),
            items: $quote->getItems(),
            createdAt: $quote->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateQuote($updated);
    }

    public function acceptQuote(int $quoteId, ?string $clientIp = null): Quote
    {
        $quote = $this->find($quoteId);
        if ($quote === null) {
            throw new RuntimeException("Quote #{$quoteId} not found.");
        }

        if ($quote->isExpired()) {
            $this->stateMachine->transition($quote->getStatus(), QuoteStatus::EXPIRED);
            $this->expireSingleQuote($quote);
            throw new RuntimeException("Quote #{$quoteId} has expired and cannot be accepted.");
        }

        $newStatus = $this->stateMachine->transition($quote->getStatus(), QuoteStatus::ACCEPTED);
        $now = date('c');

        $updated = new Quote(
            id: $quote->getId(),
            quoteNumber: $quote->getQuoteNumber(),
            originalQuoteNumber: $quote->getOriginalQuoteNumber(),
            version: $quote->getVersion(),
            userId: $quote->getUserId(),
            organizationId: $quote->getOrganizationId(),
            status: $newStatus,
            currencyCode: $quote->getCurrencyCode(),
            subtotalMinor: $quote->getSubtotalMinor(),
            taxTotalMinor: $quote->getTaxTotalMinor(),
            totalMinor: $quote->getTotalMinor(),
            validUntil: $quote->getValidUntil(),
            sentAt: $quote->getSentAt(),
            acceptedAt: $now,
            acceptedIp: $clientIp,
            rejectedAt: null,
            rejectionReason: null,
            convertedOrderId: null,
            revisionNote: $quote->getRevisionNote(),
            notes: $quote->getNotes(),
            items: $quote->getItems(),
            createdAt: $quote->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateQuote($updated);
    }

    public function rejectQuote(int $quoteId, ?string $reason = null): Quote
    {
        $quote = $this->find($quoteId);
        if ($quote === null) {
            throw new RuntimeException("Quote #{$quoteId} not found.");
        }

        $newStatus = $this->stateMachine->transition($quote->getStatus(), QuoteStatus::REJECTED);
        $now = date('c');

        $updated = new Quote(
            id: $quote->getId(),
            quoteNumber: $quote->getQuoteNumber(),
            originalQuoteNumber: $quote->getOriginalQuoteNumber(),
            version: $quote->getVersion(),
            userId: $quote->getUserId(),
            organizationId: $quote->getOrganizationId(),
            status: $newStatus,
            currencyCode: $quote->getCurrencyCode(),
            subtotalMinor: $quote->getSubtotalMinor(),
            taxTotalMinor: $quote->getTaxTotalMinor(),
            totalMinor: $quote->getTotalMinor(),
            validUntil: $quote->getValidUntil(),
            sentAt: $quote->getSentAt(),
            acceptedAt: null,
            acceptedIp: null,
            rejectedAt: $now,
            rejectionReason: $reason,
            convertedOrderId: null,
            revisionNote: $quote->getRevisionNote(),
            notes: $quote->getNotes(),
            items: $quote->getItems(),
            createdAt: $quote->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateQuote($updated);
    }

    public function expireQuotes(?string $referenceDate = null): int
    {
        $refDate = $referenceDate ?? date('Y-m-d');
        $expiredCount = 0;

        if ($this->pdo === null) {
            foreach ($this->memoryQuotes as $quote) {
                if (($quote->getStatus() === QuoteStatus::DRAFT || $quote->getStatus() === QuoteStatus::SENT)
                    && $quote->isExpired($refDate)) {
                    $this->expireSingleQuote($quote);
                    $expiredCount++;
                }
            }
            return $expiredCount;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM quotes WHERE status IN ("draft", "sent") AND valid_until < :ref_date'
        );
        $stmt->execute([':ref_date' => $refDate]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $quote = $this->mapQuoteRow($row);
            $this->expireSingleQuote($quote);
            $expiredCount++;
        }

        return $expiredCount;
    }

    /**
     * Converts an accepted quote into a commercial Order via OrderService.
     *
     * @param int $quoteId
     * @param OrderService $orderService
     * @param string|null $clientIp
     * @return array{quote: Quote, order: Order}
     */
    public function convertToOrder(int $quoteId, OrderService $orderService, ?string $clientIp = null): array
    {
        $quote = $this->find($quoteId);
        if ($quote === null) {
            throw new RuntimeException("Quote #{$quoteId} not found.");
        }

        if ($quote->getStatus() !== QuoteStatus::ACCEPTED) {
            throw new RuntimeException("Only ACCEPTED quotes can be converted to orders. Current status: {$quote->getStatus()->value}");
        }

        // Map QuoteItems to Order items format
        $orderItemsData = [];
        foreach ($quote->getItems() as $item) {
            $orderItemsData[] = [
                'product_id' => $item->getProductId() ?? 1,
                'product_name' => $item->getDescription(),
                'cycle' => $item->getBillingCycle() ?? 'monthly',
                'quantity' => $item->getQuantity(),
                'unit_price_minor' => $item->getUnitAmountMinor(),
                'unit_setup_fee_minor' => 0,
                'tax_rate' => $item->getTaxRate(),
                'metadata' => $item->getMetadata(),
            ];
        }

        $orderData = [
            'user_id' => $quote->getUserId(),
            'organization_id' => $quote->getOrganizationId(),
            'currency_code' => $quote->getCurrencyCode(),
            'notes' => "Converted from Quote {$quote->getQuoteNumber()}",
            'ip_address' => $clientIp ?? $quote->getAcceptedIp(),
        ];

        $order = $orderService->createOrder($orderData, $orderItemsData);

        // Update quote status to CONVERTED
        $newStatus = $this->stateMachine->transition($quote->getStatus(), QuoteStatus::CONVERTED);
        $now = date('c');

        $updatedQuote = new Quote(
            id: $quote->getId(),
            quoteNumber: $quote->getQuoteNumber(),
            originalQuoteNumber: $quote->getOriginalQuoteNumber(),
            version: $quote->getVersion(),
            userId: $quote->getUserId(),
            organizationId: $quote->getOrganizationId(),
            status: $newStatus,
            currencyCode: $quote->getCurrencyCode(),
            subtotalMinor: $quote->getSubtotalMinor(),
            taxTotalMinor: $quote->getTaxTotalMinor(),
            totalMinor: $quote->getTotalMinor(),
            validUntil: $quote->getValidUntil(),
            sentAt: $quote->getSentAt(),
            acceptedAt: $quote->getAcceptedAt(),
            acceptedIp: $quote->getAcceptedIp(),
            rejectedAt: $quote->getRejectedAt(),
            rejectionReason: $quote->getRejectionReason(),
            convertedOrderId: $order->getId(),
            revisionNote: $quote->getRevisionNote(),
            notes: $quote->getNotes(),
            items: $quote->getItems(),
            createdAt: $quote->getCreatedAt(),
            updatedAt: $now
        );

        $savedQuote = $this->updateQuote($updatedQuote);

        return [
            'quote' => $savedQuote,
            'order' => $order,
        ];
    }

    public function find(int $quoteId): ?Quote
    {
        if ($this->pdo === null) {
            return $this->memoryQuotes[$quoteId] ?? null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM quotes WHERE id = :id');
        $stmt->execute([':id' => $quoteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapQuoteRow($row) : null;
    }

    public function findByQuoteNumber(string $quoteNumber): ?Quote
    {
        if ($this->pdo === null) {
            foreach ($this->memoryQuotes as $q) {
                if ($q->getQuoteNumber() === $quoteNumber) {
                    return $q;
                }
            }
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM quotes WHERE quote_number = :quote_number');
        $stmt->execute([':quote_number' => $quoteNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapQuoteRow($row) : null;
    }

    private function expireSingleQuote(Quote $quote): void
    {
        $now = date('c');
        $expired = new Quote(
            id: $quote->getId(),
            quoteNumber: $quote->getQuoteNumber(),
            originalQuoteNumber: $quote->getOriginalQuoteNumber(),
            version: $quote->getVersion(),
            userId: $quote->getUserId(),
            organizationId: $quote->getOrganizationId(),
            status: QuoteStatus::EXPIRED,
            currencyCode: $quote->getCurrencyCode(),
            subtotalMinor: $quote->getSubtotalMinor(),
            taxTotalMinor: $quote->getTaxTotalMinor(),
            totalMinor: $quote->getTotalMinor(),
            validUntil: $quote->getValidUntil(),
            sentAt: $quote->getSentAt(),
            acceptedAt: $quote->getAcceptedAt(),
            acceptedIp: $quote->getAcceptedIp(),
            rejectedAt: $quote->getRejectedAt(),
            rejectionReason: $quote->getRejectionReason(),
            convertedOrderId: $quote->getConvertedOrderId(),
            revisionNote: $quote->getRevisionNote(),
            notes: $quote->getNotes(),
            items: $quote->getItems(),
            createdAt: $quote->getCreatedAt(),
            updatedAt: $now
        );

        $this->updateQuote($expired);
    }

    private function persistQuote(Quote $quote): Quote
    {
        if ($this->pdo === null) {
            $id = count($this->memoryQuotes) + 1;
            $itemsWithId = [];
            foreach ($quote->getItems() as $idx => $item) {
                $itemsWithId[] = new QuoteItem(
                    id: $idx + 1,
                    quoteId: $id,
                    description: $item->getDescription(),
                    quantity: $item->getQuantity(),
                    unitAmountMinor: $item->getUnitAmountMinor(),
                    subtotalMinor: $item->getSubtotalMinor(),
                    taxRate: $item->getTaxRate(),
                    taxAmountMinor: $item->getTaxAmountMinor(),
                    totalMinor: $item->getTotalMinor(),
                    productId: $item->getProductId(),
                    billingCycle: $item->getBillingCycle(),
                    metadata: $item->getMetadata()
                );
            }

            $saved = new Quote(
                id: $id,
                quoteNumber: $quote->getQuoteNumber(),
                originalQuoteNumber: $quote->getOriginalQuoteNumber(),
                version: $quote->getVersion(),
                userId: $quote->getUserId(),
                organizationId: $quote->getOrganizationId(),
                status: $quote->getStatus(),
                currencyCode: $quote->getCurrencyCode(),
                subtotalMinor: $quote->getSubtotalMinor(),
                taxTotalMinor: $quote->getTaxTotalMinor(),
                totalMinor: $quote->getTotalMinor(),
                validUntil: $quote->getValidUntil(),
                sentAt: $quote->getSentAt(),
                acceptedAt: $quote->getAcceptedAt(),
                acceptedIp: $quote->getAcceptedIp(),
                rejectedAt: $quote->getRejectedAt(),
                rejectionReason: $quote->getRejectionReason(),
                convertedOrderId: $quote->getConvertedOrderId(),
                revisionNote: $quote->getRevisionNote(),
                notes: $quote->getNotes(),
                items: $itemsWithId,
                createdAt: $quote->getCreatedAt(),
                updatedAt: $quote->getUpdatedAt()
            );

            $this->memoryQuotes[$id] = $saved;
            return $saved;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO quotes (
                    quote_number, original_quote_number, version, user_id, organization_id,
                    status, currency_code, subtotal_minor, tax_total_minor, total_minor,
                    valid_until, sent_at, accepted_at, accepted_ip, rejected_at,
                    rejection_reason, converted_order_id, revision_note, notes,
                    created_at, updated_at
                ) VALUES (
                    :quote_number, :original_quote_number, :version, :user_id, :organization_id,
                    :status, :currency_code, :subtotal_minor, :tax_total_minor, :total_minor,
                    :valid_until, :sent_at, :accepted_at, :accepted_ip, :rejected_at,
                    :rejection_reason, :converted_order_id, :revision_note, :notes,
                    :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':quote_number' => $quote->getQuoteNumber(),
                ':original_quote_number' => $quote->getOriginalQuoteNumber(),
                ':version' => $quote->getVersion(),
                ':user_id' => $quote->getUserId(),
                ':organization_id' => $quote->getOrganizationId(),
                ':status' => $quote->getStatus()->value,
                ':currency_code' => $quote->getCurrencyCode(),
                ':subtotal_minor' => $quote->getSubtotalMinor(),
                ':tax_total_minor' => $quote->getTaxTotalMinor(),
                ':total_minor' => $quote->getTotalMinor(),
                ':valid_until' => $quote->getValidUntil(),
                ':sent_at' => $quote->getSentAt(),
                ':accepted_at' => $quote->getAcceptedAt(),
                ':accepted_ip' => $quote->getAcceptedIp(),
                ':rejected_at' => $quote->getRejectedAt(),
                ':rejection_reason' => $quote->getRejectionReason(),
                ':converted_order_id' => $quote->getConvertedOrderId(),
                ':revision_note' => $quote->getRevisionNote(),
                ':notes' => $quote->getNotes(),
                ':created_at' => $quote->getCreatedAt(),
                ':updated_at' => $quote->getUpdatedAt(),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $itemStmt = $this->pdo->prepare(
                'INSERT INTO quote_items (
                    quote_id, description, quantity, unit_amount_minor, subtotal_minor,
                    tax_rate, tax_amount_minor, total_minor, product_id, billing_cycle, metadata_json
                ) VALUES (
                    :quote_id, :description, :quantity, :unit_amount_minor, :subtotal_minor,
                    :tax_rate, :tax_amount_minor, :total_minor, :product_id, :billing_cycle, :metadata_json
                )'
            );

            $savedItems = [];
            foreach ($quote->getItems() as $item) {
                $itemStmt->execute([
                    ':quote_id' => $id,
                    ':description' => $item->getDescription(),
                    ':quantity' => $item->getQuantity(),
                    ':unit_amount_minor' => $item->getUnitAmountMinor(),
                    ':subtotal_minor' => $item->getSubtotalMinor(),
                    ':tax_rate' => $item->getTaxRate(),
                    ':tax_amount_minor' => $item->getTaxAmountMinor(),
                    ':total_minor' => $item->getTotalMinor(),
                    ':product_id' => $item->getProductId(),
                    ':billing_cycle' => $item->getBillingCycle(),
                    ':metadata_json' => json_encode($item->getMetadata()),
                ]);
                $itemId = (int) $this->pdo->lastInsertId();
                $savedItems[] = new QuoteItem(
                    id: $itemId,
                    quoteId: $id,
                    description: $item->getDescription(),
                    quantity: $item->getQuantity(),
                    unitAmountMinor: $item->getUnitAmountMinor(),
                    subtotalMinor: $item->getSubtotalMinor(),
                    taxRate: $item->getTaxRate(),
                    taxAmountMinor: $item->getTaxAmountMinor(),
                    totalMinor: $item->getTotalMinor(),
                    productId: $item->getProductId(),
                    billingCycle: $item->getBillingCycle(),
                    metadata: $item->getMetadata()
                );
            }

            $this->pdo->commit();

            return new Quote(
                id: $id,
                quoteNumber: $quote->getQuoteNumber(),
                originalQuoteNumber: $quote->getOriginalQuoteNumber(),
                version: $quote->getVersion(),
                userId: $quote->getUserId(),
                organizationId: $quote->getOrganizationId(),
                status: $quote->getStatus(),
                currencyCode: $quote->getCurrencyCode(),
                subtotalMinor: $quote->getSubtotalMinor(),
                taxTotalMinor: $quote->getTaxTotalMinor(),
                totalMinor: $quote->getTotalMinor(),
                validUntil: $quote->getValidUntil(),
                sentAt: $quote->getSentAt(),
                acceptedAt: $quote->getAcceptedAt(),
                acceptedIp: $quote->getAcceptedIp(),
                rejectedAt: $quote->getRejectedAt(),
                rejectionReason: $quote->getRejectionReason(),
                convertedOrderId: $quote->getConvertedOrderId(),
                revisionNote: $quote->getRevisionNote(),
                notes: $quote->getNotes(),
                items: $savedItems,
                createdAt: $quote->getCreatedAt(),
                updatedAt: $quote->getUpdatedAt()
            );
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function updateQuote(Quote $quote): Quote
    {
        if ($quote->getId() === null) {
            throw new RuntimeException('Cannot update quote without ID.');
        }

        if ($this->pdo === null) {
            $this->memoryQuotes[$quote->getId()] = $quote;
            return $quote;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE quotes SET
                status = :status,
                sent_at = :sent_at,
                accepted_at = :accepted_at,
                accepted_ip = :accepted_ip,
                rejected_at = :rejected_at,
                rejection_reason = :rejection_reason,
                converted_order_id = :converted_order_id,
                updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $quote->getStatus()->value,
            ':sent_at' => $quote->getSentAt(),
            ':accepted_at' => $quote->getAcceptedAt(),
            ':accepted_ip' => $quote->getAcceptedIp(),
            ':rejected_at' => $quote->getRejectedAt(),
            ':rejection_reason' => $quote->getRejectionReason(),
            ':converted_order_id' => $quote->getConvertedOrderId(),
            ':updated_at' => $quote->getUpdatedAt(),
            ':id' => $quote->getId(),
        ]);

        return $quote;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapQuoteRow(array $row): Quote
    {
        $id = (int) $row['id'];
        $items = [];

        if ($this->pdo !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM quote_items WHERE quote_id = :quote_id');
            $stmt->execute([':quote_id' => $id]);
            while ($itemRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = new QuoteItem(
                    id: (int) $itemRow['id'],
                    quoteId: $id,
                    description: (string) $itemRow['description'],
                    quantity: (int) $itemRow['quantity'],
                    unitAmountMinor: (int) $itemRow['unit_amount_minor'],
                    subtotalMinor: (int) $itemRow['subtotal_minor'],
                    taxRate: (float) $itemRow['tax_rate'],
                    taxAmountMinor: (int) $itemRow['tax_amount_minor'],
                    totalMinor: (int) $itemRow['total_minor'],
                    productId: $itemRow['product_id'] !== null ? (int) $itemRow['product_id'] : null,
                    billingCycle: $itemRow['billing_cycle'] !== null ? (string) $itemRow['billing_cycle'] : 'monthly',
                    metadata: !empty($itemRow['metadata_json']) ? json_decode((string) $itemRow['metadata_json'], true) : []
                );
            }
        }

        return new Quote(
            id: $id,
            quoteNumber: (string) $row['quote_number'],
            originalQuoteNumber: (string) $row['original_quote_number'],
            version: (int) $row['version'],
            userId: (int) $row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int) $row['organization_id'] : null,
            status: QuoteStatus::from((string) $row['status']),
            currencyCode: (string) $row['currency_code'],
            subtotalMinor: (int) $row['subtotal_minor'],
            taxTotalMinor: (int) $row['tax_total_minor'],
            totalMinor: (int) $row['total_minor'],
            validUntil: (string) $row['valid_until'],
            sentAt: $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            acceptedAt: $row['accepted_at'] !== null ? (string) $row['accepted_at'] : null,
            acceptedIp: $row['accepted_ip'] !== null ? (string) $row['accepted_ip'] : null,
            rejectedAt: $row['rejected_at'] !== null ? (string) $row['rejected_at'] : null,
            rejectionReason: $row['rejection_reason'] !== null ? (string) $row['rejection_reason'] : null,
            convertedOrderId: $row['converted_order_id'] !== null ? (int) $row['converted_order_id'] : null,
            revisionNote: $row['revision_note'] !== null ? (string) $row['revision_note'] : null,
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
            "CREATE TABLE IF NOT EXISTS quotes (
                id {$id},
                quote_number VARCHAR(64) NOT NULL UNIQUE,
                original_quote_number VARCHAR(64) NOT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                user_id INTEGER NOT NULL,
                organization_id INTEGER,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
                currency_code VARCHAR(3) NOT NULL,
                subtotal_minor INTEGER NOT NULL,
                tax_total_minor INTEGER NOT NULL,
                total_minor INTEGER NOT NULL,
                valid_until VARCHAR(32) NOT NULL,
                sent_at VARCHAR(64),
                accepted_at VARCHAR(64),
                accepted_ip VARCHAR(45),
                rejected_at VARCHAR(64),
                rejection_reason TEXT,
                converted_order_id INTEGER,
                revision_note TEXT,
                notes TEXT,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS quote_items (
                id {$id},
                quote_id INTEGER NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                unit_amount_minor INTEGER NOT NULL,
                subtotal_minor INTEGER NOT NULL,
                tax_rate REAL NOT NULL DEFAULT 0.0,
                tax_amount_minor INTEGER NOT NULL DEFAULT 0,
                total_minor INTEGER NOT NULL,
                product_id INTEGER,
                billing_cycle VARCHAR(32) DEFAULT 'monthly',
                metadata_json TEXT
            )"
        );
    }
}
