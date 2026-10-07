<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Recurring;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Tax\Services\TaxService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class RenewalInvoiceService
{
    private string $renewalRecordsTable = 'renewal_records';

    public function __construct(
        private Connection $db,
        private InvoiceService $invoiceService,
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

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                service_id INT NOT NULL,
                user_id INT NOT NULL,
                organization_id INT NULL,
                billing_cycle VARCHAR(30) NOT NULL,
                period_start VARCHAR(20) NOT NULL,
                period_end VARCHAR(20) NOT NULL,
                invoice_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (service_id, period_start)
            )',
            $this->renewalRecordsTable,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Check if a renewal invoice was already generated for this service and period.
     */
    public function isRenewalAlreadyGenerated(int $serviceId, string $periodStart): bool
    {
        $row = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE service_id = ? AND period_start = ?', $this->renewalRecordsTable),
            [$serviceId, $periodStart]
        );
        return $row !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRenewalRecord(int $serviceId, string $periodStart): ?array
    {
        return $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE service_id = ? AND period_start = ?', $this->renewalRecordsTable),
            [$serviceId, $periodStart]
        );
    }

    /**
     * Generate renewal invoice for a service with strict idempotency protection.
     *
     * @param array<string, mixed> $serviceData
     */
    public function generateRenewalInvoice(array $serviceData, ?string $invoiceDueDate = null): Invoice
    {
        $serviceId = (int)($serviceData['service_id'] ?? $serviceData['id'] ?? 0);
        if ($serviceId <= 0) {
            throw new ValidationException(['service_id' => 'Valid service_id is required.'], 'Invalid service');
        }

        $userId = (int)($serviceData['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id is required.'], 'Invalid user');
        }

        $cycle = (string)($serviceData['billing_cycle'] ?? 'monthly');
        $nextDueDate = (string)($serviceData['next_due_date'] ?? date('Y-m-d'));
        $recurringAmountMinor = (int)($serviceData['recurring_amount_minor'] ?? 0);
        $currencyCode = strtoupper(trim((string)($serviceData['currency_code'] ?? 'USD')));
        $orgId = isset($serviceData['organization_id']) && $serviceData['organization_id'] !== null ? (int)$serviceData['organization_id'] : null;
        $desc = (string)($serviceData['description'] ?? 'Service Renewal');

        // 1. Compute billing period
        $period = BillingPeriod::computePeriod($nextDueDate, $cycle);
        $periodStart = $period['start'];
        $periodEnd = $period['end'];

        // 2. Idempotency Check: if already generated, return existing invoice
        $existing = $this->findRenewalRecord($serviceId, $periodStart);
        if ($existing !== null) {
            $existingInvoice = $this->invoiceService->findInvoiceById((int)$existing['invoice_id']);
            if ($existingInvoice !== null) {
                return $existingInvoice;
            }
        }

        // 3. Tax calculation
        $taxAmountMinor = 0;
        if ($this->taxService !== null && !empty($serviceData['country_code'])) {
            $taxClassId = isset($serviceData['tax_class_id'])
                ? (int)$serviceData['tax_class_id']
                : $this->taxService->getDefaultTaxClass()->getId();

            $taxCalc = $this->taxService->calculateTax(
                amountMinor: $recurringAmountMinor,
                taxClassId: $taxClassId,
                countryCode: (string)$serviceData['country_code'],
                stateCode: $serviceData['state_code'] ?? null,
                customerId: $userId
            );
            $taxAmountMinor = $taxCalc->getTaxTotalMinor();
        }

        $itemDescription = sprintf('%s (%s to %s)', $desc, $periodStart, $periodEnd);

        // 4. Create invoice via InvoiceService
        $dueDate = $invoiceDueDate ?? $nextDueDate;
        $invoice = $this->invoiceService->createInvoice(
            invoiceData: [
                'user_id' => $userId,
                'organization_id' => $orgId,
                'currency_code' => $currencyCode,
                'issue_date' => date('Y-m-d'),
                'due_date' => $dueDate,
                'notes' => "Automated renewal invoice for service #{$serviceId}",
            ],
            itemsData: [
                [
                    'description' => $itemDescription,
                    'quantity' => 1,
                    'unit_amount_minor' => $recurringAmountMinor,
                    'subtotal_minor' => $recurringAmountMinor,
                    'tax_amount_minor' => $taxAmountMinor,
                    'total_minor' => $recurringAmountMinor + $taxAmountMinor,
                    'service_id' => $serviceId,
                    'metadata' => [
                        'service_id' => $serviceId,
                        'billing_cycle' => $cycle,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                    ],
                ],
            ]
        );

        // 5. Store record in renewal_records
        $this->db->statement(
            sprintf(
                'INSERT INTO %s (service_id, user_id, organization_id, billing_cycle, period_start, period_end, invoice_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->renewalRecordsTable
            ),
            [
                $serviceId,
                $userId,
                $orgId,
                $cycle,
                $periodStart,
                $periodEnd,
                $invoice->getId(),
            ]
        );

        return $invoice;
    }

    /**
     * Batch process multiple candidate services, generating invoices only for those due within leadDays.
     *
     * @param array<array<string, mixed>> $candidates
     * @return array<Invoice>
     */
    public function processBatchRenewals(array $candidates, string $asOfDate, int $leadDays = 14): array
    {
        $invoices = [];
        foreach ($candidates as $candidate) {
            $nextDue = (string)($candidate['next_due_date'] ?? '');
            if ($nextDue === '') {
                continue;
            }

            if (BillingPeriod::isDueForRenewal($nextDue, $asOfDate, $leadDays)) {
                $invoices[] = $this->generateRenewalInvoice($candidate);
            }
        }
        return $invoices;
    }

    /**
     * Advance next due date by cycle length upon invoice settlement.
     */
    public static function advanceNextDueDate(string $currentDueDate, string $cycle): string
    {
        return BillingPeriod::calculateNextDueDate($currentDueDate, $cycle);
    }
}
