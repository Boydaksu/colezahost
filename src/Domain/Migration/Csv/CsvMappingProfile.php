<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Csv;

final class CsvMappingProfile
{
    /**
     * @param array<string, string> $columnMap Array mapping CSV header name (case-insensitive) to canonical field name
     * @param array<string, mixed> $defaultValues Default fallbacks for unsupplied canonical fields
     * @param list<string> $numberFields Canonical fields requiring localized decimal/currency parsing
     * @param list<string> $dateFields Canonical fields requiring localized date parsing
     */
    public function __construct(
        private string $entityType,
        private array $columnMap = [],
        private array $defaultValues = [],
        private array $numberFields = [],
        private array $dateFields = []
    ) {
    }

    public static function forClients(): self
    {
        return new self(
            entityType: 'client',
            columnMap: [
                'id' => 'id',
                'client id' => 'id',
                'customer id' => 'id',
                'first name' => 'first_name',
                'firstname' => 'first_name',
                'ad' => 'first_name',
                'last name' => 'last_name',
                'lastname' => 'last_name',
                'soyad' => 'last_name',
                'email' => 'email',
                'e-mail' => 'email',
                'company' => 'company_name',
                'company name' => 'company_name',
                'sirket' => 'company_name',
                'phone' => 'phone_number',
                'phone number' => 'phone_number',
                'telefon' => 'phone_number',
                'address' => 'address_line1',
                'address 1' => 'address_line1',
                'city' => 'city',
                'sehir' => 'city',
                'country' => 'country_code',
                'ulke' => 'country_code',
                'currency' => 'currency',
                'status' => 'status',
                'durum' => 'status',
            ],
            defaultValues: [
                'currency' => 'USD',
                'status' => 'active',
            ],
            numberFields: [],
            dateFields: ['created_at']
        );
    }

    public static function forInvoices(): self
    {
        return new self(
            entityType: 'invoice',
            columnMap: [
                'id' => 'id',
                'invoice id' => 'id',
                'invoice number' => 'invoicenum',
                'invoicenum' => 'invoicenum',
                'fatura no' => 'invoicenum',
                'client id' => 'client_id',
                'user id' => 'client_id',
                'userid' => 'client_id',
                'subtotal' => 'subtotal',
                'ara toplam' => 'subtotal',
                'tax' => 'tax',
                'kdv' => 'tax',
                'total' => 'total',
                'toplam' => 'total',
                'currency' => 'currency',
                'status' => 'status',
                'durum' => 'status',
                'date' => 'date',
                'tarih' => 'date',
                'due date' => 'duedate',
                'vade' => 'duedate',
            ],
            defaultValues: [
                'currency' => 'USD',
                'status' => 'paid',
                'tax' => 0.0,
            ],
            numberFields: ['subtotal', 'tax', 'total'],
            dateFields: ['date', 'duedate', 'datepaid']
        );
    }

    public static function forServices(): self
    {
        return new self(
            entityType: 'service',
            columnMap: [
                'id' => 'id',
                'service id' => 'id',
                'client id' => 'client_id',
                'userid' => 'client_id',
                'product id' => 'product_id',
                'packageid' => 'product_id',
                'domain' => 'domain',
                'alan adi' => 'domain',
                'username' => 'username',
                'status' => 'status',
                'durum' => 'status',
                'billing cycle' => 'billingcycle',
                'amount' => 'amount',
                'fiyat' => 'amount',
                'next due date' => 'nextduedate',
                'vade' => 'nextduedate',
            ],
            defaultValues: [
                'currency' => 'USD',
                'status' => 'active',
                'billingcycle' => 'monthly',
            ],
            numberFields: ['amount'],
            dateFields: ['regdate', 'nextduedate']
        );
    }

    public static function forDomains(): self
    {
        return new self(
            entityType: 'domain',
            columnMap: [
                'id' => 'id',
                'domain id' => 'id',
                'client id' => 'client_id',
                'userid' => 'client_id',
                'domain' => 'domain',
                'domain name' => 'domain',
                'alan adi' => 'domain',
                'registrar' => 'registrar',
                'status' => 'status',
                'recurring amount' => 'recurringamount',
                'registration period' => 'registrationperiod',
                'registration date' => 'registrationdate',
                'expiry date' => 'expirydate',
                'next due date' => 'nextduedate',
            ],
            defaultValues: [
                'currency' => 'USD',
                'status' => 'active',
                'registrationperiod' => 1,
            ],
            numberFields: ['recurringamount'],
            dateFields: ['registrationdate', 'expirydate', 'nextduedate']
        );
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * @return array<string, string>
     */
    public function getColumnMap(): array
    {
        return $this->columnMap;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultValues(): array
    {
        return $this->defaultValues;
    }

    /**
     * @return list<string>
     */
    public function getNumberFields(): array
    {
        return $this->numberFields;
    }

    /**
     * @return list<string>
     */
    public function getDateFields(): array
    {
        return $this->dateFields;
    }

    /**
     * Resolves a raw CSV column header to canonical field name.
     */
    public function mapHeader(string $header): ?string
    {
        $normalized = strtolower(trim($header));
        if (isset($this->columnMap[$normalized])) {
            return $this->columnMap[$normalized];
        }

        // Resilient fallback matching without spaces, underscores, or hyphens
        $simplified = str_replace(['_', '-', ' '], '', $normalized);
        foreach ($this->columnMap as $candidate => $target) {
            if (str_replace(['_', '-', ' '], '', strtolower($candidate)) === $simplified) {
                return $target;
            }
        }

        return null;
    }
}
