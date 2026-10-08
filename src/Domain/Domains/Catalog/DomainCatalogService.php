<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Catalog;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class DomainCatalogService
{
    private string $tldsTable = 'domain_tlds';
    private string $pricingTable = 'domain_tld_pricing';

    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlTlds = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                extension VARCHAR(32) NOT NULL UNIQUE,
                is_active TINYINT(1) DEFAULT 1,
                registrar_id VARCHAR(64) NULL,
                min_years INT DEFAULT 1,
                max_years INT DEFAULT 10,
                is_idn_supported TINYINT(1) DEFAULT 0,
                is_epp_required TINYINT(1) DEFAULT 1,
                whois_privacy_allowed TINYINT(1) DEFAULT 1,
                dns_management_allowed TINYINT(1) DEFAULT 1,
                email_forwarding_allowed TINYINT(1) DEFAULT 1,
                grace_period_days INT DEFAULT 30,
                redemption_period_days INT DEFAULT 30,
                additional_fields_json TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->tldsTable,
            $autoInc
        );
        $this->db->statement($sqlTlds);

        $sqlPricing = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                tld_id INT NOT NULL,
                operation VARCHAR(20) NOT NULL,
                years INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                price_minor INT NOT NULL,
                cost_minor INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tld_id, operation, years, currency_code)
            )',
            $this->pricingTable,
            $autoInc
        );
        $this->db->statement($sqlPricing);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function registerTld(array $data): Tld
    {
        $extension = Tld::normalizeExtension((string) ($data['extension'] ?? ''));
        if ($extension === '' || $extension === '.') {
            throw new ValidationException(['extension' => 'Valid TLD extension is required.'], 'Invalid extension');
        }

        if ($this->findTldByExtension($extension) !== null) {
            throw new ValidationException(['extension' => "TLD extension '{$extension}' is already registered."], 'Duplicate extension');
        }

        $isActive = (bool) ($data['is_active'] ?? true);
        $registrarId = isset($data['registrar_id']) ? (string) $data['registrar_id'] : null;
        $minYears = max(1, (int) ($data['min_years'] ?? 1));
        $maxYears = max($minYears, (int) ($data['max_years'] ?? 10));
        $isIdnSupported = (bool) ($data['is_idn_supported'] ?? false);
        $isEppRequired = (bool) ($data['is_epp_required'] ?? true);
        $whoisPrivacyAllowed = (bool) ($data['whois_privacy_allowed'] ?? true);
        $dnsManagementAllowed = (bool) ($data['dns_management_allowed'] ?? true);
        $emailForwardingAllowed = (bool) ($data['email_forwarding_allowed'] ?? true);
        $gracePeriodDays = max(0, (int) ($data['grace_period_days'] ?? 30));
        $redemptionPeriodDays = max(0, (int) ($data['redemption_period_days'] ?? 30));
        $additionalFields = (array) ($data['additional_fields'] ?? []);
        $metadata = (array) ($data['metadata'] ?? []);

        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (
                extension, is_active, registrar_id, min_years, max_years,
                is_idn_supported, is_epp_required, whois_privacy_allowed,
                dns_management_allowed, email_forwarding_allowed,
                grace_period_days, redemption_period_days,
                additional_fields_json, metadata_json, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->tldsTable
        );

        $this->db->statement($sql, [
            $extension,
            $isActive ? 1 : 0,
            $registrarId,
            $minYears,
            $maxYears,
            $isIdnSupported ? 1 : 0,
            $isEppRequired ? 1 : 0,
            $whoisPrivacyAllowed ? 1 : 0,
            $dnsManagementAllowed ? 1 : 0,
            $emailForwardingAllowed ? 1 : 0,
            $gracePeriodDays,
            $redemptionPeriodDays,
            json_encode($additionalFields),
            json_encode($metadata),
            $now,
            $now,
        ]);

        $id = (int) $this->db->getPdo()->lastInsertId();
        return $this->findTldById($id) ?? throw new ValidationException(['id' => 'Failed to create TLD'], 'Insert error');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateTld(int $id, array $data): Tld
    {
        $existing = $this->findTldById($id);
        if ($existing === null) {
            throw new ValidationException(['id' => "TLD ID {$id} not found."], 'TLD not found');
        }

        $isActive = isset($data['is_active']) ? ((bool) $data['is_active'] ? 1 : 0) : ($existing->isActive() ? 1 : 0);
        $registrarId = array_key_exists('registrar_id', $data) ? $data['registrar_id'] : $existing->getRegistrarId();
        $minYears = isset($data['min_years']) ? (int) $data['min_years'] : $existing->getMinYears();
        $maxYears = isset($data['max_years']) ? (int) $data['max_years'] : $existing->getMaxYears();
        $isIdn = isset($data['is_idn_supported']) ? ((bool) $data['is_idn_supported'] ? 1 : 0) : ($existing->isIdnSupported() ? 1 : 0);
        $isEpp = isset($data['is_epp_required']) ? ((bool) $data['is_epp_required'] ? 1 : 0) : ($existing->isEppRequired() ? 1 : 0);
        $whoisPrivacy = isset($data['whois_privacy_allowed']) ? ((bool) $data['whois_privacy_allowed'] ? 1 : 0) : ($existing->isWhoisPrivacyAllowed() ? 1 : 0);
        $dns = isset($data['dns_management_allowed']) ? ((bool) $data['dns_management_allowed'] ? 1 : 0) : ($existing->isDnsManagementAllowed() ? 1 : 0);
        $emailFwd = isset($data['email_forwarding_allowed']) ? ((bool) $data['email_forwarding_allowed'] ? 1 : 0) : ($existing->isEmailForwardingAllowed() ? 1 : 0);
        $grace = isset($data['grace_period_days']) ? (int) $data['grace_period_days'] : $existing->getGracePeriodDays();
        $redemption = isset($data['redemption_period_days']) ? (int) $data['redemption_period_days'] : $existing->getRedemptionPeriodDays();
        $additional = isset($data['additional_fields']) ? json_encode($data['additional_fields']) : json_encode($existing->getAdditionalFields());
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : json_encode($existing->getMetadata());

        $sql = sprintf(
            'UPDATE %s SET
                is_active = ?, registrar_id = ?, min_years = ?, max_years = ?,
                is_idn_supported = ?, is_epp_required = ?, whois_privacy_allowed = ?,
                dns_management_allowed = ?, email_forwarding_allowed = ?,
                grace_period_days = ?, redemption_period_days = ?,
                additional_fields_json = ?, metadata_json = ?, updated_at = ?
             WHERE id = ?',
            $this->tldsTable
        );

        $this->db->statement($sql, [
            $isActive,
            $registrarId,
            $minYears,
            $maxYears,
            $isIdn,
            $isEpp,
            $whoisPrivacy,
            $dns,
            $emailFwd,
            $grace,
            $redemption,
            $additional,
            $metadata,
            date('Y-m-d H:i:s'),
            $id,
        ]);

        return $this->findTldById($id) ?? throw new ValidationException(['id' => 'TLD update failed'], 'Update error');
    }

    public function deleteTld(int $id): bool
    {
        $this->db->statement(sprintf('DELETE FROM %s WHERE tld_id = ?', $this->pricingTable), [$id]);
        $this->db->statement(sprintf('DELETE FROM %s WHERE id = ?', $this->tldsTable), [$id]);
        return true;
    }

    public function findTldById(int $id): ?Tld
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->tldsTable), [$id]);
        return $row ? $this->hydrateTld($row) : null;
    }

    public function findTldByExtension(string $extension): ?Tld
    {
        $normalized = Tld::normalizeExtension($extension);
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE extension = ?', $this->tldsTable), [$normalized]);
        return $row ? $this->hydrateTld($row) : null;
    }

    /**
     * @return array<int, Tld>
     */
    public function listActiveTlds(): array
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s WHERE is_active = 1 ORDER BY extension ASC', $this->tldsTable));
        return array_map([$this, 'hydrateTld'], $rows);
    }

    /**
     * @return array<int, Tld>
     */
    public function listAllTlds(): array
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s ORDER BY extension ASC', $this->tldsTable));
        return array_map([$this, 'hydrateTld'], $rows);
    }

    /**
     * Set or update pricing for a TLD operation and term.
     */
    public function setTldPricing(
        int $tldId,
        string $operation,
        int $years,
        int $priceMinor,
        string $currencyCode = 'USD',
        ?int $costMinor = null
    ): TldPricing {
        $tld = $this->findTldById($tldId);
        if ($tld === null) {
            throw new ValidationException(['tld_id' => "TLD ID {$tldId} not found."], 'TLD not found');
        }

        $currency = strtoupper(trim($currencyCode));
        $op = strtolower(trim($operation));
        $now = date('Y-m-d H:i:s');
        $driver = $this->db->getDriverName();

        if ($driver === 'sqlite') {
            $sql = sprintf(
                'INSERT INTO %s (tld_id, operation, years, currency_code, price_minor, cost_minor, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(tld_id, operation, years, currency_code) DO UPDATE SET
                    price_minor = excluded.price_minor,
                    cost_minor = excluded.cost_minor,
                    updated_at = excluded.updated_at',
                $this->pricingTable
            );
            $this->db->statement($sql, [$tldId, $op, $years, $currency, $priceMinor, $costMinor, $now, $now]);
        } else {
            $sql = sprintf(
                'INSERT INTO %s (tld_id, operation, years, currency_code, price_minor, cost_minor, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    price_minor = VALUES(price_minor),
                    cost_minor = VALUES(cost_minor),
                    updated_at = VALUES(updated_at)',
                $this->pricingTable
            );
            $this->db->statement($sql, [$tldId, $op, $years, $currency, $priceMinor, $costMinor, $now, $now]);
        }

        return $this->getTldPricing($tldId, $op, $years, $currency)
            ?? throw new ValidationException(['pricing' => 'Failed to save pricing'], 'Pricing error');
    }

    public function getTldPricing(int $tldId, string $operation, int $years, string $currencyCode = 'USD'): ?TldPricing
    {
        $op = strtolower(trim($operation));
        $currency = strtoupper(trim($currencyCode));

        $row = $this->db->selectOne(
            sprintf(
                'SELECT * FROM %s WHERE tld_id = ? AND operation = ? AND years = ? AND currency_code = ?',
                $this->pricingTable
            ),
            [$tldId, $op, $years, $currency]
        );

        return $row ? $this->hydratePricing($row) : null;
    }

    /**
     * @return array<int, TldPricing>
     */
    public function listTldPricing(int $tldId, ?string $currencyCode = null): array
    {
        if ($currencyCode !== null) {
            $currency = strtoupper(trim($currencyCode));
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE tld_id = ? AND currency_code = ? ORDER BY operation ASC, years ASC', $this->pricingTable),
                [$tldId, $currency]
            );
        } else {
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE tld_id = ? ORDER BY currency_code ASC, operation ASC, years ASC', $this->pricingTable),
                [$tldId]
            );
        }

        return array_map([$this, 'hydratePricing'], $rows);
    }

    /**
     * Calculate registration price for a TLD and duration.
     * Uses explicit multi-year pricing if defined; otherwise multiplies 1-year rate.
     */
    public function calculateRegistrationPrice(string $extension, int $years, string $currencyCode = 'USD'): int
    {
        $tld = $this->findTldByExtension($extension);
        if ($tld === null || !$tld->isActive()) {
            throw new ValidationException(['extension' => "TLD '{$extension}' is not available or inactive."], 'Unavailable TLD');
        }

        if (!$tld->supportsYears($years)) {
            throw new ValidationException(
                ['years' => "Registration for {$years} years is not supported for {$extension}."],
                'Unsupported duration'
            );
        }

        $currency = strtoupper(trim($currencyCode));

        // 1. Check exact term match
        $pricing = $this->getTldPricing($tld->getId(), TldPricing::OPERATION_REGISTER, $years, $currency);
        if ($pricing !== null) {
            return $pricing->getPriceMinor();
        }

        // 2. Base 1-year rate calculation
        $basePricing = $this->getTldPricing($tld->getId(), TldPricing::OPERATION_REGISTER, 1, $currency);
        if ($basePricing !== null) {
            return $basePricing->getPriceMinor() * $years;
        }

        throw new ValidationException(
            ['pricing' => "No registration pricing configured for {$extension} in {$currency}."],
            'Missing pricing'
        );
    }

    /**
     * Calculate renewal price for a TLD and duration.
     */
    public function calculateRenewalPrice(string $extension, int $years, string $currencyCode = 'USD'): int
    {
        $tld = $this->findTldByExtension($extension);
        if ($tld === null || !$tld->isActive()) {
            throw new ValidationException(['extension' => "TLD '{$extension}' is not available or inactive."], 'Unavailable TLD');
        }

        if (!$tld->supportsYears($years)) {
            throw new ValidationException(
                ['years' => "Renewal for {$years} years is not supported for {$extension}."],
                'Unsupported duration'
            );
        }

        $currency = strtoupper(trim($currencyCode));

        // 1. Check exact renewal term match
        $pricing = $this->getTldPricing($tld->getId(), TldPricing::OPERATION_RENEW, $years, $currency);
        if ($pricing !== null) {
            return $pricing->getPriceMinor();
        }

        // 2. Base 1-year renewal rate
        $baseRenew = $this->getTldPricing($tld->getId(), TldPricing::OPERATION_RENEW, 1, $currency);
        if ($baseRenew !== null) {
            return $baseRenew->getPriceMinor() * $years;
        }

        // 3. Fallback to registration rate if renew not set
        return $this->calculateRegistrationPrice($extension, $years, $currency);
    }

    /**
     * Calculate transfer price for a TLD.
     */
    public function calculateTransferPrice(string $extension, string $currencyCode = 'USD'): int
    {
        $tld = $this->findTldByExtension($extension);
        if ($tld === null || !$tld->isActive()) {
            throw new ValidationException(['extension' => "TLD '{$extension}' is not available or inactive."], 'Unavailable TLD');
        }

        $currency = strtoupper(trim($currencyCode));
        $pricing = $this->getTldPricing($tld->getId(), TldPricing::OPERATION_TRANSFER, 1, $currency);
        if ($pricing !== null) {
            return $pricing->getPriceMinor();
        }

        // Fallback to 1-year registration price
        return $this->calculateRegistrationPrice($extension, 1, $currency);
    }

    /**
     * Calculate restore / redemption price for a TLD.
     */
    public function calculateRestorePrice(string $extension, string $currencyCode = 'USD'): int
    {
        $tld = $this->findTldByExtension($extension);
        if ($tld === null || !$tld->isActive()) {
            throw new ValidationException(['extension' => "TLD '{$extension}' is not available or inactive."], 'Unavailable TLD');
        }

        $currency = strtoupper(trim($currencyCode));
        $pricing = $this->getTldPricing($tld->getId(), TldPricing::OPERATION_RESTORE, 1, $currency);
        if ($pricing !== null) {
            return $pricing->getPriceMinor();
        }

        throw new ValidationException(
            ['pricing' => "No redemption restore pricing configured for {$extension} in {$currency}."],
            'Missing pricing'
        );
    }

    /**
     * Split domain name into SLD and TLD, matching against active catalog extensions.
     *
     * @return array{sld: string, tld: string}
     */
    public function splitDomain(string $domainName): array
    {
        $domain = strtolower(trim($domainName));

        // Sort catalog TLDs by length descending to match multi-part extensions first (.com.tr before .tr)
        $tlds = $this->listAllTlds();
        usort($tlds, fn (Tld $a, Tld $b) => strlen($b->getExtension()) <=> strlen($a->getExtension()));

        foreach ($tlds as $tld) {
            $ext = $tld->getExtension();
            if (str_ends_with($domain, $ext)) {
                $sld = substr($domain, 0, -strlen($ext));
                if ($sld !== '') {
                    return ['sld' => $sld, 'tld' => $ext];
                }
            }
        }

        // Fallback: split at first dot
        $dotPos = strpos($domain, '.');
        if ($dotPos !== false) {
            return [
                'sld' => substr($domain, 0, $dotPos),
                'tld' => substr($domain, $dotPos),
            ];
        }

        return ['sld' => $domain, 'tld' => ''];
    }

    /**
     * Validate full domain name against TLD catalog and policy.
     *
     * @param array<string, mixed> $additionalData
     */
    public function validateDomainName(
        string $domainName,
        int $years = 1,
        array $additionalData = []
    ): DomainValidationResult {
        $parts = $this->splitDomain($domainName);
        $sld = $parts['sld'];
        $ext = $parts['tld'];

        if ($ext === '') {
            return DomainValidationResult::failed($domainName, ['Domain must include a valid extension.'], $sld, '');
        }

        $tld = $this->findTldByExtension($ext);
        if ($tld === null) {
            return DomainValidationResult::failed($domainName, ["Unsupported domain extension '{$ext}'."], $sld, $ext);
        }

        if (!$tld->isActive()) {
            return DomainValidationResult::failed($domainName, ["Domain extension '{$ext}' is currently inactive."], $sld, $ext);
        }

        return TldPolicy::validate($sld, $tld, $years, $additionalData);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTld(array $row): Tld
    {
        return new Tld(
            id: (int) $row['id'],
            extension: (string) $row['extension'],
            isActive: (bool) $row['is_active'],
            registrarId: isset($row['registrar_id']) ? (string) $row['registrar_id'] : null,
            minYears: (int) $row['min_years'],
            maxYears: (int) $row['max_years'],
            isIdnSupported: (bool) $row['is_idn_supported'],
            isEppRequired: (bool) $row['is_epp_required'],
            whoisPrivacyAllowed: (bool) $row['whois_privacy_allowed'],
            dnsManagementAllowed: (bool) $row['dns_management_allowed'],
            emailForwardingAllowed: (bool) $row['email_forwarding_allowed'],
            gracePeriodDays: (int) $row['grace_period_days'],
            redemptionPeriodDays: (int) $row['redemption_period_days'],
            additionalFields: json_decode((string) ($row['additional_fields_json'] ?? '{}'), true) ?: [],
            metadata: json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [],
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePricing(array $row): TldPricing
    {
        return new TldPricing(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tldId: (int) $row['tld_id'],
            operation: (string) $row['operation'],
            years: (int) $row['years'],
            currencyCode: (string) $row['currency_code'],
            priceMinor: (int) $row['price_minor'],
            costMinor: isset($row['cost_minor']) ? (int) $row['cost_minor'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }
}
