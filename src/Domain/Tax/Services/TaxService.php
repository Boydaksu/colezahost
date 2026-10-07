<?php

declare(strict_types=1);

namespace Coleza\Domain\Tax\Services;

use Coleza\Domain\Tax\Entities\TaxCalculationResult;
use Coleza\Domain\Tax\Entities\TaxClass;
use Coleza\Domain\Tax\Entities\TaxRate;
use Coleza\Domain\Tax\Entities\TaxZone;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class TaxService
{
    private string $classesTable = 'tax_classes';
    private string $zonesTable = 'tax_zones';
    private string $ratesTable = 'tax_rates';
    private string $exemptionsTable = 'tax_exemptions';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Tax Classes
        $sqlClasses = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                is_default INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->classesTable,
            $autoInc
        );
        $this->db->statement($sqlClasses);

        // Tax Zones
        $sqlZones = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                country_codes_json TEXT NULL,
                state_codes_json TEXT NULL,
                is_global INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->zonesTable,
            $autoInc
        );
        $this->db->statement($sqlZones);

        // Tax Rates
        $sqlRates = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                tax_class_id INT NOT NULL,
                tax_zone_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                rate_percent REAL NOT NULL,
                calculation_type VARCHAR(20) NOT NULL DEFAULT "exclusive",
                priority INT NOT NULL DEFAULT 1,
                is_active INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->ratesTable,
            $autoInc
        );
        $this->db->statement($sqlRates);

        // Customer/Organization Exemptions
        $sqlExemptions = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                customer_id INT NOT NULL UNIQUE,
                is_exempt INT NOT NULL DEFAULT 1,
                tax_number VARCHAR(100) NULL,
                exemption_reason VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->exemptionsTable,
            $autoInc
        );
        $this->db->statement($sqlExemptions);
    }

    // ==========================================
    // Tax Classes Methods
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createTaxClass(array $data): TaxClass
    {
        $code = strtolower(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException(['code' => ['Tax class code is required.']], 'Tax class code is required.');
        }

        if ($this->findTaxClassByCode($code) !== null) {
            throw new ValidationException(['code' => ['Tax class code already exists.']], "Tax class {$code} already exists.");
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Tax class name is required.']], 'Tax class name is required.');
        }

        $description = isset($data['description']) ? trim((string)$data['description']) : null;
        $isDefault = (bool)($data['is_default'] ?? false);

        if ($isDefault) {
            $this->db->statement(sprintf('UPDATE %s SET is_default = 0', $this->classesTable));
        }

        $sql = sprintf('INSERT INTO %s (code, name, description, is_default) VALUES (?, ?, ?, ?)', $this->classesTable);
        $this->db->statement($sql, [$code, $name, $description, $isDefault ? 1 : 0]);
        $id = (int)$this->db->getPdo()->lastInsertId();

        return new TaxClass($id, $code, $name, $description, $isDefault, date('Y-m-d H:i:s'));
    }

    public function findTaxClassByCode(string $code): ?TaxClass
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE code = ?', $this->classesTable), [strtolower($code)]);
        return $row ? $this->hydrateTaxClass($row) : null;
    }

    public function getDefaultTaxClass(): TaxClass
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE is_default = 1', $this->classesTable));
        if ($row === null) {
            return $this->createTaxClass([
                'code' => TaxClass::DEFAULT_STANDARD,
                'name' => 'Standard Tax Rate',
                'is_default' => true,
            ]);
        }
        return $this->hydrateTaxClass($row);
    }

    // ==========================================
    // Tax Zones Methods
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createTaxZone(array $data): TaxZone
    {
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException(['code' => ['Tax zone code is required.']], 'Tax zone code is required.');
        }

        $existing = $this->db->selectOne(sprintf('SELECT id FROM %s WHERE code = ?', $this->zonesTable), [$code]);
        if ($existing !== null) {
            throw new ValidationException(['code' => ['Tax zone code already exists.']], "Tax zone {$code} already exists.");
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Tax zone name is required.']], 'Tax zone name is required.');
        }

        $countryCodes = isset($data['country_codes']) && is_array($data['country_codes']) ? array_map('strtoupper', $data['country_codes']) : [];
        $stateCodes = isset($data['state_codes']) && is_array($data['state_codes']) ? array_map('strtoupper', $data['state_codes']) : [];
        $isGlobal = (bool)($data['is_global'] ?? false);

        $sql = sprintf(
            'INSERT INTO %s (code, name, country_codes_json, state_codes_json, is_global) VALUES (?, ?, ?, ?, ?)',
            $this->zonesTable
        );

        $this->db->statement($sql, [
            $code,
            $name,
            json_encode($countryCodes),
            json_encode($stateCodes),
            $isGlobal ? 1 : 0,
        ]);
        $id = (int)$this->db->getPdo()->lastInsertId();

        return new TaxZone($id, $code, $name, $countryCodes, $stateCodes, $isGlobal, date('Y-m-d H:i:s'));
    }

    /**
     * Find matching tax zone for country/state.
     */
    public function findZoneForLocation(string $countryCode, ?string $stateCode = null): ?TaxZone
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s ORDER BY is_global ASC, id ASC', $this->zonesTable));
        $globalZone = null;

        foreach ($rows as $row) {
            $zone = $this->hydrateTaxZone($row);
            if ($zone->isGlobal()) {
                $globalZone = $zone;
            } elseif ($zone->matches($countryCode, $stateCode)) {
                return $zone;
            }
        }

        return $globalZone;
    }

    // ==========================================
    // Tax Rates Methods
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createTaxRate(array $data): TaxRate
    {
        $taxClassId = (int)($data['tax_class_id'] ?? 0);
        $taxZoneId = (int)($data['tax_zone_id'] ?? 0);

        if ($taxClassId <= 0 || $taxZoneId <= 0) {
            throw new ValidationException(['ids' => ['Valid tax_class_id and tax_zone_id required.']], 'Invalid tax references.');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Tax rate name is required.']], 'Tax rate name is required.');
        }

        $ratePercent = (float)($data['rate_percent'] ?? 0.0);
        if ($ratePercent < 0.0) {
            throw new ValidationException(['rate_percent' => ['Tax rate cannot be negative.']], 'Tax rate cannot be negative.');
        }

        $calculationType = (string)($data['calculation_type'] ?? TaxRate::CALCULATION_EXCLUSIVE);
        if ($calculationType !== TaxRate::CALCULATION_EXCLUSIVE && $calculationType !== TaxRate::CALCULATION_INCLUSIVE) {
            throw new ValidationException(['calculation_type' => ['Invalid calculation type.']], 'Invalid calculation type.');
        }

        $priority = (int)($data['priority'] ?? 1);
        $isActive = (bool)($data['is_active'] ?? true);

        $sql = sprintf(
            'INSERT INTO %s (tax_class_id, tax_zone_id, name, rate_percent, calculation_type, priority, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            $this->ratesTable
        );

        $this->db->statement($sql, [
            $taxClassId,
            $taxZoneId,
            $name,
            $ratePercent,
            $calculationType,
            $priority,
            $isActive ? 1 : 0,
        ]);
        $id = (int)$this->db->getPdo()->lastInsertId();

        return new TaxRate($id, $taxClassId, $taxZoneId, $name, $ratePercent, $calculationType, $priority, $isActive, date('Y-m-d H:i:s'));
    }

    // ==========================================
    // Tax Exemption Methods
    // ==========================================

    public function setCustomerExemption(int $customerId, bool $isExempt, ?string $taxNumber = null, ?string $reason = null): void
    {
        $existing = $this->db->selectOne(sprintf('SELECT id FROM %s WHERE customer_id = ?', $this->exemptionsTable), [$customerId]);
        if ($existing !== null) {
            $sql = sprintf('UPDATE %s SET is_exempt = ?, tax_number = ?, exemption_reason = ? WHERE customer_id = ?', $this->exemptionsTable);
            $this->db->statement($sql, [$isExempt ? 1 : 0, $taxNumber, $reason, $customerId]);
        } else {
            $sql = sprintf('INSERT INTO %s (customer_id, is_exempt, tax_number, exemption_reason) VALUES (?, ?, ?, ?)', $this->exemptionsTable);
            $this->db->statement($sql, [$customerId, $isExempt ? 1 : 0, $taxNumber, $reason]);
        }
    }

    /**
     * @return array{is_exempt: bool, tax_number: ?string, reason: ?string}
     */
    public function getCustomerExemption(int $customerId): array
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE customer_id = ?', $this->exemptionsTable), [$customerId]);
        if ($row === null) {
            return ['is_exempt' => false, 'tax_number' => null, 'reason' => null];
        }

        return [
            'is_exempt' => (bool)$row['is_exempt'],
            'tax_number' => $row['tax_number'] !== null ? (string)$row['tax_number'] : null,
            'reason' => $row['exemption_reason'] !== null ? (string)$row['exemption_reason'] : null,
        ];
    }

    // ==========================================
    // Tax Calculation Engine
    // ==========================================

    /**
     * Authoritative calculation of taxes for given items in a billing context.
     *
     * @param int $amountMinor Gross or Net minor amount depending on pricing
     * @param int $taxClassId
     * @param string $countryCode
     * @param string|null $stateCode
     * @param int|null $customerId
     */
    public function calculateTax(
        int $amountMinor,
        int $taxClassId,
        string $countryCode,
        ?string $stateCode = null,
        ?int $customerId = null
    ): TaxCalculationResult {
        // 1. Check customer exemption
        if ($customerId !== null) {
            $exemption = $this->getCustomerExemption($customerId);
            if ($exemption['is_exempt']) {
                return new TaxCalculationResult(
                    subtotalMinor: $amountMinor,
                    taxTotalMinor: 0,
                    totalMinor: $amountMinor,
                    isExempt: true,
                    exemptionReason: $exemption['reason'] ?? 'Validated tax exemption',
                    taxLines: []
                );
            }
        }

        // 2. Resolve Tax Zone
        $zone = $this->findZoneForLocation($countryCode, $stateCode);
        if ($zone === null) {
            // No tax zone matched -> 0% tax
            return new TaxCalculationResult($amountMinor, 0, $amountMinor, false, null, []);
        }

        // 3. Resolve active tax rates for class & zone
        $sql = sprintf(
            'SELECT * FROM %s WHERE tax_class_id = ? AND tax_zone_id = ? AND is_active = 1 ORDER BY priority ASC, id ASC',
            $this->ratesTable
        );
        $rows = $this->db->select($sql, [$taxClassId, $zone->getId()]);

        if (empty($rows)) {
            return new TaxCalculationResult($amountMinor, 0, $amountMinor, false, null, []);
        }

        $taxLines = [];
        $totalTax = 0;
        $finalSubtotal = $amountMinor;
        $finalTotal = $amountMinor;

        foreach ($rows as $row) {
            $taxRate = $this->hydrateTaxRate($row);
            $breakdown = $taxRate->compute($amountMinor);

            if ($taxRate->isInclusive()) {
                $finalSubtotal = $breakdown['net_minor'];
                $finalTotal = $breakdown['gross_minor'];
            } else {
                $finalSubtotal = $breakdown['net_minor'];
                $finalTotal = $breakdown['gross_minor'];
            }

            $totalTax += $breakdown['tax_minor'];
            $taxLines[] = [
                'name' => $taxRate->getName(),
                'rate_percent' => $taxRate->getRatePercent(),
                'tax_minor' => $breakdown['tax_minor'],
                'is_inclusive' => $taxRate->isInclusive(),
            ];
        }

        return new TaxCalculationResult(
            subtotalMinor: $finalSubtotal,
            taxTotalMinor: $totalTax,
            totalMinor: $finalTotal,
            isExempt: false,
            exemptionReason: null,
            taxLines: $taxLines
        );
    }

    private function hydrateTaxClass(array $row): TaxClass
    {
        return new TaxClass(
            id: (int)$row['id'],
            code: (string)$row['code'],
            name: (string)$row['name'],
            description: $row['description'] !== null ? (string)$row['description'] : null,
            isDefault: (bool)$row['is_default'],
            createdAt: (string)$row['created_at']
        );
    }

    private function hydrateTaxZone(array $row): TaxZone
    {
        $countryCodes = !empty($row['country_codes_json']) ? json_decode((string)$row['country_codes_json'], true) : [];
        $stateCodes = !empty($row['state_codes_json']) ? json_decode((string)$row['state_codes_json'], true) : [];

        return new TaxZone(
            id: (int)$row['id'],
            code: (string)$row['code'],
            name: (string)$row['name'],
            countryCodes: is_array($countryCodes) ? $countryCodes : [],
            stateCodes: is_array($stateCodes) ? $stateCodes : [],
            isGlobal: (bool)$row['is_global'],
            createdAt: (string)$row['created_at']
        );
    }

    private function hydrateTaxRate(array $row): TaxRate
    {
        return new TaxRate(
            id: (int)$row['id'],
            taxClassId: (int)$row['tax_class_id'],
            taxZoneId: (int)$row['tax_zone_id'],
            name: (string)$row['name'],
            ratePercent: (float)$row['rate_percent'],
            calculationType: (string)$row['calculation_type'],
            priority: (int)$row['priority'],
            isActive: (bool)$row['is_active'],
            createdAt: (string)$row['created_at']
        );
    }
}
