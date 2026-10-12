<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Currency;

use Coleza\Domain\Finance\Fx\FxRateProviderInterface;
use Coleza\Domain\Finance\Fx\FxSnapshot;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class CurrencyService
{
    private string $currenciesTable = 'currencies';
    private string $fxSnapshotsTable = 'fx_snapshots';

    /**
     * @param array<string, FxRateProviderInterface> $providers Keyed by provider name
     */
    public function __construct(
        private Connection $db,
        private array $providers = []
    ) {
    }

    public function registerProvider(FxRateProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    public static function schemaRequirements(): array
    {
        return [
            'currencies' => ['code', 'name', 'symbol', 'format', 'decimal_places', 'is_default', 'is_active', 'created_at'],
            'fx_snapshots' => ['id', 'base_currency', 'rates_json', 'provider', 'snapshot_date', 'created_at'],
        ];
    }

    public function ensureTables(bool $installSchema = false): void
    {
        if (\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db, self::schemaRequirements() + \Coleza\Foundation\Database\ConfigurationWriteLock::schemaRequirements(), $installSchema)) { return; }
        \Coleza\Foundation\Database\PdoSchema::autoIncrement($this->db->getPdo());
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Currencies table
        $sqlCurrencies = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                code VARCHAR(3) PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                symbol VARCHAR(10) NOT NULL,
                format VARCHAR(50) NOT NULL DEFAULT "{symbol}{amount}",
                decimal_places INT NOT NULL DEFAULT 2,
                is_default INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->currenciesTable
        );
        $this->db->statement($sqlCurrencies);

        // FX Snapshots table
        $sqlFx = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                base_currency VARCHAR(3) NOT NULL,
                rates_json TEXT NOT NULL,
                provider VARCHAR(100) NOT NULL,
                snapshot_date VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->fxSnapshotsTable,
            $autoInc
        );
        $this->db->statement($sqlFx);
        \Coleza\Foundation\Database\ConfigurationWriteLock::ensureSchema($this->db, $installSchema);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createCurrency(array $data): Currency
    {
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if (strlen($code) !== 3) {
            throw new ValidationException(['code' => ['ISO 4217 code must be exactly 3 characters.']], 'Invalid currency code.');
        }

        return (new \Coleza\Foundation\Database\ConfigurationWriteLock($this->db))->run('currency', function () use ($data, $code): Currency {
            if ($this->db->selectOne('SELECT code FROM currencies WHERE code = ?' . $this->db->forUpdate(), [$code]) !== null) {
                throw new ValidationException(['code' => ['Currency code already exists.']], "Currency {$code} already exists.");
            }

            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new ValidationException(['name' => ['Currency name is required.']], 'Currency name is required.');
            }

            $symbol = trim((string)($data['symbol'] ?? ''));
            if ($symbol === '') {
                throw new ValidationException(['symbol' => ['Currency symbol is required.']], 'Currency symbol is required.');
            }

            $format = (string)($data['format'] ?? '{symbol}{amount}');
            $decimalPlaces = (int)($data['decimal_places'] ?? 2);
            $isDefault = (bool)($data['is_default'] ?? false);
            $isActive = (bool)($data['is_active'] ?? true);

            if ($isDefault && !$isActive) { throw new ValidationException(['is_default' => ['Default currency must be active.']], 'Invalid default currency.'); }
            $this->requireUnambiguousDefaults();
            if ($isDefault) {
                // Unset previous default
                $this->db->statement(sprintf('UPDATE %s SET is_default = 0', $this->currenciesTable));
            }

            $sql = sprintf(
                'INSERT INTO %s (code, name, symbol, format, decimal_places, is_default, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->currenciesTable
            );

            $this->db->statement($sql, [
                $code,
                $name,
                $symbol,
                $format,
                $decimalPlaces,
                $isDefault ? 1 : 0,
                $isActive ? 1 : 0,
            ]);

            return new Currency(
                code: $code,
                name: $name,
                symbol: $symbol,
                format: $format,
                decimalPlaces: $decimalPlaces,
                isDefault: $isDefault,
                isActive: $isActive,
                createdAt: date('Y-m-d H:i:s')
            );
        });
    }

    public function findCurrency(string $code): ?Currency
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE code = ?', $this->currenciesTable), [strtoupper($code)]);
        return $row ? $this->hydrateCurrency($row) : null;
    }

    public function getDefaultCurrency(): Currency
    {
        return (new \Coleza\Foundation\Database\ConfigurationWriteLock($this->db))->run('currency', function (): Currency {
            $defaults = $this->requireUnambiguousDefaults();
            if ($defaults !== []) {
                if (!(bool) $defaults[0]['is_active']) { throw new ValidationException(['currency' => ['Default currency is inactive; explicit reconciliation required.']], 'Inactive default currency.'); }
                return $this->hydrateCurrency($defaults[0]);
            }
            $active = $this->db->select('SELECT * FROM currencies WHERE is_active = 1 ORDER BY code LIMIT 2' . $this->db->forUpdate());
            if (count($active) > 1) { throw new ValidationException(['currency' => ['Select an explicit default currency.']], 'Ambiguous default currency.'); }
            if ($active !== []) { return $this->hydrateCurrency($active[0]); }
            return $this->createCurrency(['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'format' => '{amount} {symbol}', 'is_default' => true]);
        });
    }

    private function requireUnambiguousDefaults(): array
    {
        $rows = $this->db->select('SELECT * FROM currencies WHERE is_default = 1 ORDER BY code LIMIT 2' . $this->db->forUpdate());
        if (count($rows) > 1) { throw new ValidationException(['currency' => ['Multiple default currencies require explicit reconciliation.']], 'Ambiguous default currency.'); }
        return $rows;
    }

    /**
     * @return array<Currency>
     */
    public function listCurrencies(bool $onlyActive = false): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->currenciesTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY is_default DESC, code ASC';

        $rows = $this->db->select($sql);
        return array_map([$this, 'hydrateCurrency'], $rows);
    }

    /**
     * Capture FX rate snapshot from a provider and persist immutably.
     */
    public function captureFxSnapshot(?string $providerName = null, ?string $snapshotDate = null): FxSnapshot
    {
        // Validate the persistence target before fallback currency writes or provider I/O.
        $this->ensureTables();
        $baseCurrency = $this->getDefaultCurrency()->getCode();
        $activeCurrencies = array_map(fn($c) => $c->getCode(), $this->listCurrencies(true));

        if (empty($this->providers)) {
            throw new RuntimeException('No FX rate providers registered.');
        }

        $provider = null;
        if ($providerName !== null && isset($this->providers[$providerName])) {
            $provider = $this->providers[$providerName];
        } else {
            $provider = reset($this->providers);
        }

        $targetCurrencies = array_values(array_filter($activeCurrencies, fn($c) => $c !== $baseCurrency));
        $rates = $provider->getRates($baseCurrency, $targetCurrencies);
        $date = $snapshotDate ?? date('Y-m-d');

        $sql = sprintf(
            'INSERT INTO %s (base_currency, rates_json, provider, snapshot_date) VALUES (?, ?, ?, ?)',
            $this->fxSnapshotsTable
        );

        $this->db->statement($sql, [
            $baseCurrency,
            json_encode($rates),
            $provider->getName(),
            $date,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new FxSnapshot(
            id: $id,
            baseCurrency: $baseCurrency,
            rates: $rates,
            provider: $provider->getName(),
            snapshotDate: $date,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function getLatestFxSnapshot(?string $baseCurrency = null): ?FxSnapshot
    {
        $base = $baseCurrency ? strtoupper($baseCurrency) : $this->getDefaultCurrency()->getCode();
        $sql = sprintf('SELECT * FROM %s WHERE base_currency = ? ORDER BY id DESC LIMIT 1', $this->fxSnapshotsTable);
        $row = $this->db->selectOne($sql, [$base]);

        return $row ? $this->hydrateSnapshot($row) : null;
    }

    public function getSnapshotByDate(string $date, ?string $baseCurrency = null): ?FxSnapshot
    {
        $base = $baseCurrency ? strtoupper($baseCurrency) : $this->getDefaultCurrency()->getCode();
        $sql = sprintf('SELECT * FROM %s WHERE base_currency = ? AND snapshot_date = ? ORDER BY id DESC LIMIT 1', $this->fxSnapshotsTable);
        $row = $this->db->selectOne($sql, [$base, $date]);

        return $row ? $this->hydrateSnapshot($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCurrency(array $row): Currency
    {
        return new Currency(
            code: (string)$row['code'],
            name: (string)$row['name'],
            symbol: (string)$row['symbol'],
            format: (string)$row['format'],
            decimalPlaces: (int)$row['decimal_places'],
            isDefault: (bool)$row['is_default'],
            isActive: (bool)$row['is_active'],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSnapshot(array $row): FxSnapshot
    {
        $rates = [];
        if (!empty($row['rates_json'])) {
            $decoded = json_decode((string)$row['rates_json'], true);
            if (is_array($decoded)) {
                $rates = $decoded;
            }
        }

        return new FxSnapshot(
            id: (int)$row['id'],
            baseCurrency: (string)$row['base_currency'],
            rates: $rates,
            provider: (string)$row['provider'],
            snapshotDate: (string)$row['snapshot_date'],
            createdAt: (string)$row['created_at']
        );
    }
}
