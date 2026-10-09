<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Core;

use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;

/**
 * Extracts core hosting commerce records from a source WHMCS database
 * using strictly read-only queries with zero mutations.
 */
final class WhmcsCoreEntityExtractor
{
    /**
     * @var array<int|string, string>|null Cached currency map [id => code]
     */
    private ?array $currencyMap = null;

    public function __construct(
        private WhmcsReadOnlyConnector $connector
    ) {
    }

    /**
     * Extracts currency map (e.g. [1 => 'USD', 2 => 'EUR']).
     * Defaults to [1 => 'USD'] if tblcurrencies does not exist.
     *
     * @return array<int|string, string>
     */
    public function getCurrencies(): array
    {
        if ($this->currencyMap !== null) {
            return $this->currencyMap;
        }

        $map = [1 => 'USD'];

        if ($this->connector->tableExists('tblcurrencies')) {
            $rows = $this->connector->select('SELECT id, code FROM tblcurrencies');
            foreach ($rows as $row) {
                if (isset($row['id'], $row['code'])) {
                    $map[(int) $row['id']] = strtoupper(trim((string) $row['code']));
                    $map[(string) $row['id']] = strtoupper(trim((string) $row['code']));
                }
            }
        }

        $this->currencyMap = $map;
        return $this->currencyMap;
    }

    /**
     * Resolves currency ID or code to standard 3-letter ISO code.
     */
    public function resolveCurrencyCode(mixed $currencyValue): string
    {
        if ($currencyValue === null || $currencyValue === '') {
            return 'USD';
        }

        if (is_numeric($currencyValue)) {
            $currencies = $this->getCurrencies();
            return $currencies[(int) $currencyValue] ?? 'USD';
        }

        $clean = strtoupper(trim((string) $currencyValue));
        return strlen($clean) === 3 ? $clean : 'USD';
    }

    /**
     * Extracts clients from tblclients.
     * Enriches rows with resolved currency code and custom fields if present.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractClients(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tblclients')) {
            return [];
        }

        $sql = 'SELECT * FROM tblclients ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $clients = $this->connector->select($sql);

        foreach ($clients as &$client) {
            if (isset($client['currency'])) {
                $client['currency'] = $this->resolveCurrencyCode($client['currency']);
            } else {
                $client['currency'] = 'USD';
            }

            // Extract custom fields if available
            if (isset($client['id'])) {
                $customFields = $this->extractCustomFields('client', (int) $client['id']);
                if (!empty($customFields)) {
                    $client['customfields'] = $customFields;
                }
            }
        }
        unset($client);

        return $clients;
    }

    /**
     * Extracts contacts / subaccounts from tblcontacts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractContacts(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tblcontacts')) {
            return [];
        }

        $sql = 'SELECT * FROM tblcontacts ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        return $this->connector->select($sql);
    }

    /**
     * Extracts product groups from tblproductgroups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractProductGroups(): array
    {
        if (!$this->connector->tableExists('tblproductgroups')) {
            return [];
        }

        return $this->connector->select('SELECT * FROM tblproductgroups ORDER BY id ASC');
    }

    /**
     * Extracts products from tblproducts, enriched with pricing if tblpricing exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractProducts(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tblproducts')) {
            return [];
        }

        $sql = 'SELECT * FROM tblproducts ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $products = $this->connector->select($sql);
        $hasPricing = $this->connector->tableExists('tblpricing');

        foreach ($products as &$prod) {
            $prodId = (int) ($prod['id'] ?? 0);
            $prod['price'] = 0.0;
            $prod['currency'] = 'USD';

            if ($hasPricing && $prodId > 0) {
                $pricing = $this->connector->selectOne(
                    "SELECT monthly, quarterly, semiannually, annually, currency FROM tblpricing WHERE type = 'product' AND relid = ? ORDER BY id ASC",
                    [$prodId]
                );

                if ($pricing !== null) {
                    $monthly = (float) ($pricing['monthly'] ?? 0.0);
                    $annually = (float) ($pricing['annually'] ?? 0.0);
                    $prod['price'] = $monthly > 0.0 ? $monthly : ($annually > 0.0 ? $annually : 0.0);
                    $prod['monthly'] = $monthly;
                    $prod['annually'] = $annually;
                    $prod['currency'] = $this->resolveCurrencyCode($pricing['currency'] ?? 1);
                }
            }
        }
        unset($prod);

        return $products;
    }

    /**
     * Extracts services / hosting accounts from tblhosting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractServices(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tblhosting')) {
            return [];
        }

        $sql = 'SELECT * FROM tblhosting ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $services = $this->connector->select($sql);

        // Fetch client currencies cache for services if needed
        $clientCurrencies = $this->getClientCurrencyMap();

        foreach ($services as &$srv) {
            $userId = (int) ($srv['userid'] ?? 0);
            $srv['currency'] = $clientCurrencies[$userId] ?? 'USD';

            if (isset($srv['id'])) {
                $customFields = $this->extractCustomFields('product', (int) $srv['id']);
                if (!empty($customFields)) {
                    $srv['customfields'] = $customFields;
                }
            }
        }
        unset($srv);

        return $services;
    }

    /**
     * Extracts domains from tbldomains.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractDomains(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tbldomains')) {
            return [];
        }

        $sql = 'SELECT * FROM tbldomains ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $domains = $this->connector->select($sql);
        $clientCurrencies = $this->getClientCurrencyMap();

        foreach ($domains as &$dom) {
            $userId = (int) ($dom['userid'] ?? 0);
            $dom['currency'] = $clientCurrencies[$userId] ?? 'USD';
        }
        unset($dom);

        return $domains;
    }

    /**
     * Extracts custom field values for an entity if custom fields tables exist.
     *
     * @return array<string, mixed>
     */
    public function extractCustomFields(string $type, int $relId): array
    {
        if (!$this->connector->tableExists('tblcustomfields') || !$this->connector->tableExists('tblcustomfieldsvalues')) {
            return [];
        }

        $sql = "SELECT f.fieldname, v.value 
                FROM tblcustomfields f
                JOIN tblcustomfieldsvalues v ON v.fieldid = f.id
                WHERE f.type = ? AND v.relid = ?";

        $rows = $this->connector->select($sql, [$type, $relId]);
        $fields = [];
        foreach ($rows as $row) {
            if (isset($row['fieldname'])) {
                $fields[(string) $row['fieldname']] = $row['value'] ?? '';
            }
        }

        return $fields;
    }

    /**
     * @return array<int, string> [userId => currencyCode]
     */
    private function getClientCurrencyMap(): array
    {
        $map = [];
        if (!$this->connector->tableExists('tblclients')) {
            return $map;
        }

        $rows = $this->connector->select('SELECT id, currency FROM tblclients');
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $this->resolveCurrencyCode($row['currency'] ?? 1);
            }
        }

        return $map;
    }
}
