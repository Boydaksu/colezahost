<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Support;

/**
 * Validates and certifies that all raw source fields are accounted for
 * without any silent dropping (Constitution §11).
 */
final class UnsupportedDataAccountant
{
    /**
     * @var array<string, list<string>>
     */
    private array $knownMappedKeys = [
        'client' => [
            'id', 'client_id', 'userid', 'firstname', 'first_name', 'lastname', 'last_name',
            'email', 'companyname', 'company', 'company_name', 'phonenumber', 'phone', 'phone_number',
            'address1', 'address_line1', 'address2', 'address_line2', 'city', 'state', 'postcode',
            'zip', 'country', 'country_code', 'currency', 'status', 'taxexempt', 'datecreated',
            'created_at', 'customfields', 'custom_fields', 'unmapped_source_columns',
        ],
        'product' => [
            'id', 'product_id', 'name', 'type', 'description', 'price', 'monthly', 'currency',
            'paytype', 'billing_cycle', 'servertype', 'module', 'configoption1', 'package_name', 'retired',
        ],
        'service' => [
            'id', 'service_id', 'userid', 'client_id', 'packageid', 'product_id', 'domain',
            'username', 'dedicatedip', 'domainstatus', 'status', 'billingcycle', 'billing_cycle',
            'amount', 'firstpaymentamount', 'currency', 'regdate', 'registration_date', 'nextduedate',
            'next_due_date', 'suspendreason', 'customfields',
        ],
        'domain' => [
            'id', 'domain_id', 'userid', 'client_id', 'domain', 'registrar', 'status',
            'recurringamount', 'currency', 'registrationperiod', 'registrationdate',
            'expirydate', 'nextduedate', 'donotrenew', 'idprotection',
        ],
        'invoice' => [
            'id', 'invoice_id', 'userid', 'client_id', 'invoicenum', 'invoice_number',
            'subtotal', 'tax', 'tax2', 'total', 'currency', 'status', 'date', 'duedate',
            'due_date', 'datepaid', 'date_paid', 'items', 'line_items', 'unmapped_source_columns',
        ],
        'payment' => [
            'id', 'transaction_id', 'userid', 'client_id', 'invoiceid', 'amountin', 'amount',
            'fees', 'fee', 'currency', 'transid', 'gateway', 'status', 'date',
        ],
        'ticket' => [
            'id', 'ticket_id', 'userid', 'client_id', 'did', 'department', 'title', 'subject',
            'message', 'urgency', 'priority', 'status', 'tid', 'date', 'lastreply', 'replies',
            'attachments', 'customfields', 'unmapped_source_columns',
        ],
    ];

    /**
     * @var array<string, array{entities_count: int, mapped_fields: int, preserved_unsupported_fields: int, dropped_fields: int, sample_preserved_keys: list<string>}>
     */
    private array $statsByEntity = [];

    /**
     * Accounts a single record's raw fields and confirms preservation.
     *
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $metadata
     */
    public function accountRecord(string $entityType, array $rawPayload, array $metadata): void
    {
        $type = strtolower(trim($entityType));
        if (!isset($this->statsByEntity[$type])) {
            $this->statsByEntity[$type] = [
                'entities_count' => 0,
                'mapped_fields' => 0,
                'preserved_unsupported_fields' => 0,
                'dropped_fields' => 0,
                'sample_preserved_keys' => [],
            ];
        }

        $this->statsByEntity[$type]['entities_count']++;

        $known = array_flip($this->knownMappedKeys[$type] ?? []);
        $unsupported = (array) ($metadata['unsupported_source_fields'] ?? []);

        foreach (array_keys($rawPayload) as $key) {
            $keyStr = (string) $key;
            if (isset($known[$keyStr])) {
                $this->statsByEntity[$type]['mapped_fields']++;
            } elseif (array_key_exists($keyStr, $unsupported) || array_key_exists($keyStr, $metadata)) {
                $this->statsByEntity[$type]['preserved_unsupported_fields']++;
                if (count($this->statsByEntity[$type]['sample_preserved_keys']) < 20) {
                    if (!in_array($keyStr, $this->statsByEntity[$type]['sample_preserved_keys'], true)) {
                        $this->statsByEntity[$type]['sample_preserved_keys'][] = $keyStr;
                    }
                }
            } else {
                // Key was neither recognized nor preserved in metadata: violation of zero silent loss!
                $this->statsByEntity[$type]['dropped_fields']++;
            }
        }
    }

    public function generateReport(string $batchId): UnsupportedDataReport
    {
        $totalEntities = 0;
        $totalMapped = 0;
        $totalPreserved = 0;
        $totalDropped = 0;

        foreach ($this->statsByEntity as $stats) {
            $totalEntities += $stats['entities_count'];
            $totalMapped += $stats['mapped_fields'];
            $totalPreserved += $stats['preserved_unsupported_fields'];
            $totalDropped += $stats['dropped_fields'];
        }

        return new UnsupportedDataReport(
            batchId: $batchId,
            totalEntitiesInspected: $totalEntities,
            totalMappedFields: $totalMapped,
            totalPreservedUnsupportedFields: $totalPreserved,
            totalDroppedFields: $totalDropped,
            byEntityType: $this->statsByEntity
        );
    }
}
