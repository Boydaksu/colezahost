<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Services;

use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PriceOverride;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Entities\PriceQuote;
use Coleza\Domain\Pricing\Entities\ProrataCalculation;
use Coleza\Domain\Pricing\Entities\UpgradeDowngradeQuote;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class PricingService
{
    private string $pricePointsTable = 'price_points';
    private string $overridesTable = 'price_overrides';

    public function __construct(
        private Connection $db,
        private ?CurrencyService $currencyService = null,
        private ?CatalogService $catalogService = null
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
                target_type VARCHAR(50) NOT NULL,
                target_id INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                cycle VARCHAR(50) NOT NULL,
                price_minor INT NOT NULL DEFAULT 0,
                setup_fee_minor INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(target_type, target_id, currency_code, cycle)
            )',
            $this->pricePointsTable,
            $autoInc
        );
        $this->db->statement($sql);

        $sqlOverrides = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                scope VARCHAR(50) NOT NULL,
                scope_id INT NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_id INT NOT NULL,
                override_type VARCHAR(50) NOT NULL,
                override_value INT NOT NULL,
                currency_code VARCHAR(3) NULL,
                cycle VARCHAR(50) NULL,
                reason TEXT NULL,
                is_active INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->overridesTable,
            $autoInc
        );
        $this->db->statement($sqlOverrides);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setPricePoint(array $data): PricePoint
    {
        $targetType = (string)($data['target_type'] ?? PricePoint::TARGET_PRODUCT);
        $validTargets = [
            PricePoint::TARGET_PRODUCT,
            PricePoint::TARGET_OPTION,
            PricePoint::TARGET_ADDON,
        ];
        if (!in_array($targetType, $validTargets, true)) {
            throw new ValidationException(['target_type' => ['Invalid price target type.']], 'Invalid price target type.');
        }

        $targetId = (int)($data['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw new ValidationException(['target_id' => ['Valid target_id required.']], 'Valid target_id required.');
        }

        $currencyCode = strtoupper(trim((string)($data['currency_code'] ?? 'TRY')));
        if (strlen($currencyCode) !== 3) {
            throw new ValidationException(['currency_code' => ['ISO 4217 code required.']], 'Invalid currency code.');
        }

        $cycle = (string)($data['cycle'] ?? PriceCycle::MONTHLY);
        if (!PriceCycle::isValid($cycle)) {
            throw new ValidationException(['cycle' => ['Invalid billing cycle.']], 'Invalid billing cycle.');
        }

        $priceMinor = (int)($data['price_minor'] ?? 0);
        if ($priceMinor < 0) {
            throw new ValidationException(['price_minor' => ['Price cannot be negative.']], 'Price cannot be negative.');
        }

        $setupFeeMinor = (int)($data['setup_fee_minor'] ?? 0);
        if ($setupFeeMinor < 0) {
            throw new ValidationException(['setup_fee_minor' => ['Setup fee cannot be negative.']], 'Setup fee cannot be negative.');
        }

        $isActive = (bool)($data['is_active'] ?? true);

        $existing = $this->findPricePoint($targetType, $targetId, $currencyCode, $cycle);

        if ($existing !== null) {
            $sql = sprintf(
                'UPDATE %s SET price_minor = ?, setup_fee_minor = ?, is_active = ? WHERE id = ?',
                $this->pricePointsTable
            );
            $this->db->statement($sql, [$priceMinor, $setupFeeMinor, $isActive ? 1 : 0, $existing->getId()]);
            $id = $existing->getId();
        } else {
            $sql = sprintf(
                'INSERT INTO %s (target_type, target_id, currency_code, cycle, price_minor, setup_fee_minor, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->pricePointsTable
            );
            $this->db->statement($sql, [
                $targetType,
                $targetId,
                $currencyCode,
                $cycle,
                $priceMinor,
                $setupFeeMinor,
                $isActive ? 1 : 0,
            ]);
            $id = (int)$this->db->getPdo()->lastInsertId();
        }

        return new PricePoint(
            id: $id,
            targetType: $targetType,
            targetId: $targetId,
            currencyCode: $currencyCode,
            cycle: $cycle,
            priceMinor: $priceMinor,
            setupFeeMinor: $setupFeeMinor,
            isActive: $isActive,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findPricePoint(string $targetType, int $targetId, string $currencyCode, string $cycle): ?PricePoint
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE target_type = ? AND target_id = ? AND currency_code = ? AND cycle = ?',
            $this->pricePointsTable
        );
        $row = $this->db->selectOne($sql, [$targetType, $targetId, strtoupper($currencyCode), $cycle]);

        return $row ? $this->hydratePricePoint($row) : null;
    }

    /**
     * @return array<PricePoint>
     */
    public function getPricesForTarget(string $targetType, int $targetId, ?string $currencyCode = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE target_type = ? AND target_id = ? AND is_active = 1', $this->pricePointsTable);
        $params = [$targetType, $targetId];

        if ($currencyCode !== null) {
            $sql .= ' AND currency_code = ?';
            $params[] = strtoupper($currencyCode);
        }

        $sql .= ' ORDER BY cycle ASC';
        $rows = $this->db->select($sql, $params);

        return array_map([$this, 'hydratePricePoint'], $rows);
    }

    /**
     * Calculate authoritative quote for a product with options and addons.
     *
     * @param int $productId
     * @param string $currencyCode
     * @param string $cycle
     * @param array<int> $selectedOptionSubIds Array of configurable_option_sub IDs
     * @param array<int> $selectedOptionSubIds Array of configurable_option_sub IDs
     * @param array<int> $selectedAddonIds Array of product_addon IDs
     * @param int|null $customerId User/Org ID for customer-specific price overrides
     * @param int|null $serviceId Service ID for service-specific renewal overrides
     */
    public function calculateQuote(
        int $productId,
        string $currencyCode,
        string $cycle,
        array $selectedOptionSubIds = [],
        array $selectedAddonIds = [],
        ?int $customerId = null,
        ?int $serviceId = null
    ): PriceQuote {
        $currency = strtoupper($currencyCode);

        // 1. Base Product Price
        $basePricePoint = $this->findPricePoint(PricePoint::TARGET_PRODUCT, $productId, $currency, $cycle);
        if ($basePricePoint === null || !$basePricePoint->isActive()) {
            throw new RuntimeException("Pricing not configured for product {$productId} in {$currency} / {$cycle}.");
        }

        $standardBase = $basePricePoint->getPriceMinor();
        $basePrice = $this->resolvePrice(PricePoint::TARGET_PRODUCT, $productId, $standardBase, $currency, $cycle, $customerId, $serviceId);
        $setupFee = $basePricePoint->getSetupFeeMinor();

        $items = [];
        $items[] = [
            'name' => "Product #{$productId}",
            'type' => 'product',
            'cycle' => $cycle,
            'price_minor' => $basePrice,
            'setup_minor' => $setupFee,
        ];

        // 2. Configurable Options
        $optionsTotal = 0;
        $optionsSetup = 0;
        foreach ($selectedOptionSubIds as $subId) {
            $optPrice = $this->findPricePoint(PricePoint::TARGET_OPTION, $subId, $currency, $cycle);
            // If cycle-specific not found, check one-time
            if ($optPrice === null && $cycle !== PriceCycle::ONE_TIME) {
                $optPrice = $this->findPricePoint(PricePoint::TARGET_OPTION, $subId, $currency, PriceCycle::ONE_TIME);
            }

            if ($optPrice !== null && $optPrice->isActive()) {
                $optStandard = $optPrice->getPriceMinor();
                $optFinal = $this->resolvePrice(PricePoint::TARGET_OPTION, $subId, $optStandard, $currency, $optPrice->getCycle(), $customerId, $serviceId);

                $optionsTotal += $optFinal;
                $optionsSetup += $optPrice->getSetupFeeMinor();
                $items[] = [
                    'name' => "OptionSub #{$subId}",
                    'type' => 'option',
                    'cycle' => $optPrice->getCycle(),
                    'price_minor' => $optFinal,
                    'setup_minor' => $optPrice->getSetupFeeMinor(),
                ];
            }
        }

        // 3. Product Addons
        $addonsTotal = 0;
        $addonsSetup = 0;
        foreach ($selectedAddonIds as $addonId) {
            $addonPrice = $this->findPricePoint(PricePoint::TARGET_ADDON, $addonId, $currency, $cycle);
            if ($addonPrice === null && $cycle !== PriceCycle::ONE_TIME) {
                $addonPrice = $this->findPricePoint(PricePoint::TARGET_ADDON, $addonId, $currency, PriceCycle::ONE_TIME);
            }

            if ($addonPrice !== null && $addonPrice->isActive()) {
                $addonStandard = $addonPrice->getPriceMinor();
                $addonFinal = $this->resolvePrice(PricePoint::TARGET_ADDON, $addonId, $addonStandard, $currency, $addonPrice->getCycle(), $customerId, $serviceId);

                $addonsTotal += $addonFinal;
                $addonsSetup += $addonPrice->getSetupFeeMinor();
                $items[] = [
                    'name' => "Addon #{$addonId}",
                    'type' => 'addon',
                    'cycle' => $addonPrice->getCycle(),
                    'price_minor' => $addonFinal,
                    'setup_minor' => $addonPrice->getSetupFeeMinor(),
                ];
            }
        }

        $totalSetup = $setupFee + $optionsSetup + $addonsSetup;
        $recurringTotal = $basePrice + $optionsTotal + $addonsTotal;
        $firstPaymentTotal = $recurringTotal + $totalSetup;

        return new PriceQuote(
            currencyCode: $currency,
            cycle: $cycle,
            basePriceMinor: $basePrice,
            setupFeeMinor: $totalSetup,
            optionsTotalMinor: $optionsTotal,
            addonsTotalMinor: $addonsTotal,
            recurringTotalMinor: $recurringTotal,
            firstPaymentTotalMinor: $firstPaymentTotal,
            items: $items
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPriceOverride(array $data): PriceOverride
    {
        $scope = (string)($data['scope'] ?? PriceOverride::SCOPE_CUSTOMER);
        if ($scope !== PriceOverride::SCOPE_CUSTOMER && $scope !== PriceOverride::SCOPE_SERVICE) {
            throw new ValidationException(['scope' => ['Invalid override scope.']], 'Invalid override scope.');
        }

        $scopeId = (int)($data['scope_id'] ?? 0);
        if ($scopeId <= 0) {
            throw new ValidationException(['scope_id' => ['Valid scope_id required.']], 'Valid scope_id required.');
        }

        $targetType = (string)($data['target_type'] ?? PricePoint::TARGET_PRODUCT);
        $targetId = (int)($data['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw new ValidationException(['target_id' => ['Valid target_id required.']], 'Valid target_id required.');
        }

        $overrideType = (string)($data['override_type'] ?? PriceOverride::TYPE_FIXED);
        if ($overrideType !== PriceOverride::TYPE_FIXED && $overrideType !== PriceOverride::TYPE_PERCENT) {
            throw new ValidationException(['override_type' => ['Invalid override type.']], 'Invalid override type.');
        }

        $overrideValue = (int)($data['override_value'] ?? 0);
        if ($overrideValue < 0) {
            throw new ValidationException(['override_value' => ['Override value cannot be negative.']], 'Override value cannot be negative.');
        }
        if ($overrideType === PriceOverride::TYPE_PERCENT && $overrideValue > 100) {
            throw new ValidationException(['override_value' => ['Percentage discount cannot exceed 100.']], 'Invalid percentage discount.');
        }

        $currencyCode = isset($data['currency_code']) ? strtoupper(trim((string)$data['currency_code'])) : null;
        $cycle = isset($data['cycle']) ? trim((string)$data['cycle']) : null;
        $reason = isset($data['reason']) ? trim((string)$data['reason']) : null;
        $isActive = (bool)($data['is_active'] ?? true);

        $sql = sprintf(
            'INSERT INTO %s (scope, scope_id, target_type, target_id, override_type, override_value, currency_code, cycle, reason, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->overridesTable
        );

        $this->db->statement($sql, [
            $scope,
            $scopeId,
            $targetType,
            $targetId,
            $overrideType,
            $overrideValue,
            $currencyCode,
            $cycle,
            $reason,
            $isActive ? 1 : 0,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new PriceOverride(
            id: $id,
            scope: $scope,
            scopeId: $scopeId,
            targetType: $targetType,
            targetId: $targetId,
            overrideType: $overrideType,
            overrideValue: $overrideValue,
            currencyCode: $currencyCode,
            cycle: $cycle,
            reason: $reason,
            isActive: $isActive,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * Resolve final price factoring in Service (highest precedence) or Customer overrides.
     */
    private function resolvePrice(
        string $targetType,
        int $targetId,
        int $standardPriceMinor,
        string $currency,
        string $cycle,
        ?int $customerId,
        ?int $serviceId
    ): int {
        // 1. Service override (most specific)
        if ($serviceId !== null) {
            $serviceOverride = $this->findMatchingOverride(PriceOverride::SCOPE_SERVICE, $serviceId, $targetType, $targetId, $currency, $cycle);
            if ($serviceOverride !== null) {
                return $serviceOverride->apply($standardPriceMinor);
            }
        }

        // 2. Customer override
        if ($customerId !== null) {
            $customerOverride = $this->findMatchingOverride(PriceOverride::SCOPE_CUSTOMER, $customerId, $targetType, $targetId, $currency, $cycle);
            if ($customerOverride !== null) {
                return $customerOverride->apply($standardPriceMinor);
            }
        }

        return $standardPriceMinor;
    }

    private function findMatchingOverride(
        string $scope,
        int $scopeId,
        string $targetType,
        int $targetId,
        string $currency,
        string $cycle
    ): ?PriceOverride {
        $sql = sprintf(
            'SELECT * FROM %s WHERE scope = ? AND scope_id = ? AND target_type = ? AND target_id = ? AND is_active = 1
             AND (currency_code IS NULL OR currency_code = ?)
             AND (cycle IS NULL OR cycle = ?)
             ORDER BY cycle DESC, currency_code DESC, id DESC LIMIT 1',
            $this->overridesTable
        );

        $row = $this->db->selectOne($sql, [$scope, $scopeId, $targetType, $targetId, $currency, $cycle]);
        return $row ? $this->hydratePriceOverride($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePriceOverride(array $row): PriceOverride
    {
        return new PriceOverride(
            id: (int)$row['id'],
            scope: (string)$row['scope'],
            scopeId: (int)$row['scope_id'],
            targetType: (string)$row['target_type'],
            targetId: (int)$row['target_id'],
            overrideType: (string)$row['override_type'],
            overrideValue: (int)$row['override_value'],
            currencyCode: $row['currency_code'] !== null ? (string)$row['currency_code'] : null,
            cycle: $row['cycle'] !== null ? (string)$row['cycle'] : null,
            reason: $row['reason'] !== null ? (string)$row['reason'] : null,
            isActive: (bool)$row['is_active'],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePricePoint(array $row): PricePoint
    {
        return new PricePoint(
            id: (int)$row['id'],
            targetType: (string)$row['target_type'],
            targetId: (int)$row['target_id'],
            currencyCode: (string)$row['currency_code'],
            cycle: (string)$row['cycle'],
            priceMinor: (int)$row['price_minor'],
            setupFeeMinor: (int)$row['setup_fee_minor'],
            isActive: (bool)$row['is_active'],
            createdAt: (string)$row['created_at']
        );
    }

    // ==========================================
    // Prorata & Upgrade/Downgrade Calculations
    // ==========================================

    /**
     * Calculate prorated minor unit amount based on day count.
     */
    public function calculateProrata(int $amountMinor, int $daysUsed, int $daysTotal): ProrataCalculation
    {
        if ($daysTotal <= 0) {
            throw new ValidationException(['days_total' => ['Total days must be greater than zero.']], 'Invalid days total.');
        }

        $clampedUsed = max(0, min($daysUsed, $daysTotal));
        $ratio = round($clampedUsed / $daysTotal, 6);
        $prorated = (int)round(($amountMinor * $clampedUsed) / $daysTotal);

        return new ProrataCalculation(
            daysUsed: $clampedUsed,
            daysTotal: $daysTotal,
            amountMinor: $amountMinor,
            proratedMinor: $prorated,
            ratio: $ratio
        );
    }

    /**
     * Calculate upgrade/downgrade quote between existing service and new target product.
     *
     * @param int $serviceId
     * @param int $oldProductId
     * @param int $newProductId
     * @param string $currencyCode
     * @param string $cycle
     * @param string $periodStartDate YYYY-MM-DD
     * @param string $periodEndDate YYYY-MM-DD
     * @param string|null $changeDate YYYY-MM-DD (defaults to today)
     */
    public function calculateUpgradeQuote(
        int $serviceId,
        int $oldProductId,
        int $newProductId,
        string $currencyCode,
        string $cycle,
        string $periodStartDate,
        string $periodEndDate,
        ?string $changeDate = null,
        ?int $customerId = null
    ): UpgradeDowngradeQuote {
        $currency = strtoupper($currencyCode);
        $change = $changeDate ?? date('Y-m-d');

        $startTs = strtotime($periodStartDate);
        $endTs = strtotime($periodEndDate);
        $changeTs = strtotime($change);

        if ($startTs === false || $endTs === false || $changeTs === false || $endTs <= $startTs) {
            throw new ValidationException(['period' => ['Invalid period dates.']], 'Invalid billing period range.');
        }

        // Period days calculation
        $totalDays = (int)round(($endTs - $startTs) / 86400);
        if ($totalDays <= 0) {
            $totalDays = 1;
        }

        $remainingDays = (int)round(($endTs - $changeTs) / 86400);
        $remainingDays = max(0, min($remainingDays, $totalDays));

        // Get effective prices for old and new product
        $oldPoint = $this->findPricePoint(PricePoint::TARGET_PRODUCT, $oldProductId, $currency, $cycle);
        if ($oldPoint === null) {
            throw new RuntimeException("Old product pricing not found for {$oldProductId} in {$currency}/{$cycle}.");
        }
        $oldPrice = $this->resolvePrice(PricePoint::TARGET_PRODUCT, $oldProductId, $oldPoint->getPriceMinor(), $currency, $cycle, $customerId, $serviceId);

        $newPoint = $this->findPricePoint(PricePoint::TARGET_PRODUCT, $newProductId, $currency, $cycle);
        if ($newPoint === null) {
            throw new RuntimeException("New product pricing not found for {$newProductId} in {$currency}/{$cycle}.");
        }
        $newPrice = $this->resolvePrice(PricePoint::TARGET_PRODUCT, $newProductId, $newPoint->getPriceMinor(), $currency, $cycle, $customerId, null);

        // Prorata credit of old product for remaining days
        $oldCreditMinor = (int)round(($oldPrice * $remainingDays) / $totalDays);

        // Prorata charge of new product for remaining days
        $newChargeMinor = (int)round(($newPrice * $remainingDays) / $totalDays);

        $difference = $newChargeMinor - $oldCreditMinor;

        if ($difference > 0) {
            $type = UpgradeDowngradeQuote::TYPE_UPGRADE;
            $netDue = $difference;
            $creditIssued = 0;
        } elseif ($difference < 0) {
            $type = UpgradeDowngradeQuote::TYPE_DOWNGRADE;
            $netDue = 0;
            $creditIssued = abs($difference);
        } else {
            $type = UpgradeDowngradeQuote::TYPE_LATERAL;
            $netDue = 0;
            $creditIssued = 0;
        }

        return new UpgradeDowngradeQuote(
            type: $type,
            currentServiceId: $serviceId,
            oldProductId: $oldProductId,
            newProductId: $newProductId,
            currencyCode: $currency,
            cycle: $cycle,
            daysRemaining: $remainingDays,
            totalPeriodDays: $totalDays,
            oldProrataCreditMinor: $oldCreditMinor,
            newProrataChargeMinor: $newChargeMinor,
            netDueMinor: $netDue,
            creditIssuedMinor: $creditIssued,
            effectiveDate: $change
        );
    }
}
