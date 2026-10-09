<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Financial;

use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;

/**
 * Extracts invoices, invoice items, financial transactions, and credit balances
 * from a source WHMCS database with read-only integrity.
 */
final class WhmcsFinancialExtractor
{
    /**
     * @var array<int|string, string>|null
     */
    private ?array $currencyMap = null;

    public function __construct(
        private WhmcsReadOnlyConnector $connector
    ) {
    }

    /**
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
                    $code = strtoupper(trim((string) $row['code']));
                    $map[(int) $row['id']] = $code;
                    $map[(string) $row['id']] = $code;
                }
            }
        }

        $this->currencyMap = $map;
        return $this->currencyMap;
    }

    public function resolveCurrency(mixed $currencyVal): string
    {
        if ($currencyVal === null || $currencyVal === '') {
            return 'USD';
        }

        if (is_numeric($currencyVal)) {
            $currencies = $this->getCurrencies();
            return $currencies[(int) $currencyVal] ?? 'USD';
        }

        $clean = strtoupper(trim((string) $currencyVal));
        return strlen($clean) === 3 ? $clean : 'USD';
    }

    /**
     * Extracts invoices from tblinvoices with line items from tblinvoiceitems.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractInvoices(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tblinvoices')) {
            return [];
        }

        $sql = 'SELECT * FROM tblinvoices ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $invoices = $this->connector->select($sql);
        $clientCurrencies = $this->getClientCurrencyMap();

        foreach ($invoices as &$inv) {
            $userId = (int) ($inv['userid'] ?? 0);
            $inv['currency'] = $this->resolveCurrency($inv['currency'] ?? ($clientCurrencies[$userId] ?? 'USD'));

            // Attach line items
            $invId = (int) ($inv['id'] ?? 0);
            $inv['items'] = $this->extractInvoiceItems($invId);
        }
        unset($inv);

        return $invoices;
    }

    /**
     * Extracts line items for an invoice from tblinvoiceitems.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractInvoiceItems(int $invoiceId): array
    {
        if (!$this->connector->tableExists('tblinvoiceitems')) {
            return [];
        }

        return $this->connector->select(
            'SELECT * FROM tblinvoiceitems WHERE invoiceid = ? ORDER BY id ASC',
            [$invoiceId]
        );
    }

    /**
     * Extracts financial payment transactions from tblaccounts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractTransactions(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tblaccounts')) {
            return [];
        }

        $sql = 'SELECT * FROM tblaccounts ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $transactions = $this->connector->select($sql);
        $clientCurrencies = $this->getClientCurrencyMap();

        foreach ($transactions as &$txn) {
            $userId = (int) ($txn['userid'] ?? 0);
            $txn['currency'] = $this->resolveCurrency($txn['currency'] ?? ($clientCurrencies[$userId] ?? 'USD'));
        }
        unset($txn);

        return $transactions;
    }

    /**
     * Extracts clients with positive credit balance.
     *
     * @return array<int, array{client_id: int, credit: float, currency: string}>
     */
    public function extractClientCredits(): array
    {
        if (!$this->connector->tableExists('tblclients')) {
            return [];
        }

        $rows = $this->connector->select('SELECT id, credit, currency FROM tblclients WHERE credit > 0');
        $credits = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $amount = (float) ($row['credit'] ?? 0.0);
            if ($id > 0 && $amount > 0.0) {
                $credits[] = [
                    'client_id' => $id,
                    'credit' => $amount,
                    'currency' => $this->resolveCurrency($row['currency'] ?? 1),
                ];
            }
        }

        return $credits;
    }

    /**
     * Calculates total invoiced sum in the source database, grouped by currency.
     *
     * @return array<string, float> [currency => total]
     */
    public function calculateSourceInvoiceTotals(): array
    {
        $invoices = $this->extractInvoices();
        $totals = [];

        foreach ($invoices as $inv) {
            $currency = (string) ($inv['currency'] ?? 'USD');
            $total = (float) ($inv['total'] ?? 0.0);
            $totals[$currency] = round(($totals[$currency] ?? 0.0) + $total, 2);
        }

        return $totals;
    }

    /**
     * Calculates total payment amount received in the source database, grouped by currency.
     *
     * @return array<string, float> [currency => total]
     */
    public function calculateSourcePaymentTotals(): array
    {
        $txns = $this->extractTransactions();
        $totals = [];

        foreach ($txns as $t) {
            $amount = (float) ($t['amountin'] ?? 0.0);
            if ($amount > 0.0) {
                $currency = (string) ($t['currency'] ?? 'USD');
                $totals[$currency] = round(($totals[$currency] ?? 0.0) + $amount, 2);
            }
        }

        return $totals;
    }

    /**
     * Calculates total credit balances in the source database, grouped by currency.
     *
     * @return array<string, float> [currency => total]
     */
    public function calculateSourceCreditTotals(): array
    {
        $credits = $this->extractClientCredits();
        $totals = [];

        foreach ($credits as $c) {
            $currency = $c['currency'];
            $amount = $c['credit'];
            $totals[$currency] = round(($totals[$currency] ?? 0.0) + $amount, 2);
        }

        return $totals;
    }

    /**
     * @return array<int, string>
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
                $map[$id] = $this->resolveCurrency($row['currency'] ?? 1);
            }
        }

        return $map;
    }
}
