<?php

declare(strict_types=1);

namespace Coleza\Domain\Pricing\Services;

use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Domain\Finance\Currency\CurrencyService;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Pricing\Entities\PricePoint;
use Coleza\Domain\Pricing\Entities\PriceQuote;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class PricingService
{
    private string $pricePointsTable = 'price_points';

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
     * @param array<int> $selectedAddonIds Array of product_addon IDs
     */
    public function calculateQuote(
        int $productId,
        string $currencyCode,
        string $cycle,
        array $selectedOptionSubIds = [],
        array $selectedAddonIds = []
    ): PriceQuote {
        $currency = strtoupper($currencyCode);

        // 1. Base Product Price
        $basePricePoint = $this->findPricePoint(PricePoint::TARGET_PRODUCT, $productId, $currency, $cycle);
        if ($basePricePoint === null || !$basePricePoint->isActive()) {
            throw new RuntimeException("Pricing not configured for product {$productId} in {$currency} / {$cycle}.");
        }

        $items = [];
        $items[] = [
            'name' => "Product #{$productId}",
            'type' => 'product',
            'cycle' => $cycle,
            'price_minor' => $basePricePoint->getPriceMinor(),
            'setup_minor' => $basePricePoint->getSetupFeeMinor(),
        ];

        $basePrice = $basePricePoint->getPriceMinor();
        $setupFee = $basePricePoint->getSetupFeeMinor();

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
                $optionsTotal += $optPrice->getPriceMinor();
                $optionsSetup += $optPrice->getSetupFeeMinor();
                $items[] = [
                    'name' => "OptionSub #{$subId}",
                    'type' => 'option',
                    'cycle' => $optPrice->getCycle(),
                    'price_minor' => $optPrice->getPriceMinor(),
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
                $addonsTotal += $addonPrice->getPriceMinor();
                $addonsSetup += $addonPrice->getSetupFeeMinor();
                $items[] = [
                    'name' => "Addon #{$addonId}",
                    'type' => 'addon',
                    'cycle' => $addonPrice->getCycle(),
                    'price_minor' => $addonPrice->getPriceMinor(),
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
}
