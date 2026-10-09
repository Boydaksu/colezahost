<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

use Coleza\Foundation\Database\Connection;
use RuntimeException;

final class PaymentConcurrencySchema
{
    public function __construct(private Connection $db) {}

    public function ensureSupportingTables(): void
    {
        $this->db->statement('CREATE TABLE IF NOT EXISTS gateway_refund_reservations (
            request_id VARCHAR(64) PRIMARY KEY, payment_id INT NOT NULL, amount_minor INT NOT NULL,
            status VARCHAR(20) NOT NULL, gateway VARCHAR(50) NOT NULL,
            provider_reference VARCHAR(255) NULL, refund_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        $this->db->statement('CREATE TABLE IF NOT EXISTS credit_balance_locks (
            user_id INT NOT NULL, organization_id INT NOT NULL DEFAULT 0, currency_code VARCHAR(3) NOT NULL,
            PRIMARY KEY (user_id, organization_id, currency_code))');
    }

    public function upgrade(): void
    {
        if ($this->db->getDriverName() === 'sqlite' && !$this->db->inTransaction()) {
            $this->db->transaction(fn () => $this->upgradeSchema());
        } else {
            $this->upgradeSchema();
        }
    }

    private function upgradeSchema(): void
    {
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) {
            throw new RuntimeException('Payment schema migration requires no application transaction.');
        }
        $rows = $this->db->select('SELECT id, metadata_json FROM payments');
        $tokens = [];
        $backfill = [];
        foreach ($rows as $row) {
            $metadata = $row['metadata_json'] === null ? [] : json_decode((string) $row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) { throw new RuntimeException('Legacy payment metadata must be an object.'); }
            $token = $metadata['checkout_token'] ?? null;
            if ($token === null) { continue; }
            if (!is_string($token) || trim($token) === '' || trim($token) !== $token || strlen($token) > 2048) {
                throw new RuntimeException('Invalid legacy checkout token; reconciliation required.');
            }
            $hash = hash('sha256', $token);
            if (isset($tokens[$hash])) {
                throw new RuntimeException('Duplicate legacy checkout token; reconciliation required.');
            }
            $tokens[$hash] = true;
            $backfill[] = [(int) $row['id'], $hash];
        }
        if ($this->db->selectOne('SELECT gateway, event_id FROM payment_webhook_events GROUP BY gateway, event_id HAVING COUNT(*) > 1 LIMIT 1') !== null) {
            throw new RuntimeException('Duplicate legacy webhook events; reconciliation required.');
        }
        $columns = $this->db->getDriverName() === 'sqlite'
            ? array_column($this->db->select('PRAGMA table_info(payments)'), 'name')
            : array_column($this->db->select('SHOW COLUMNS FROM payments'), 'Field');
        if (!in_array('checkout_token_hash', $columns, true)) {
            $this->db->statement('ALTER TABLE payments ADD COLUMN checkout_token_hash VARCHAR(64) NULL');
        }
        foreach ($backfill as [$id, $hash]) {
            $this->db->statement('UPDATE payments SET checkout_token_hash = ? WHERE id = ?', [$hash, $id]);
        }
        $this->ensureSupportingTables();
        foreach ([['payments', 'payment_checkout_token_unique', 'checkout_token_hash', true],
            ['payment_webhook_events', 'payment_webhook_event_unique', 'gateway, event_id', true],
            ['payment_allocations', 'allocation_payment_index', 'payment_id', false],
            ['refunds', 'refund_payment_index', 'payment_id', false],
            ['gateway_refund_reservations', 'refund_reservation_payment_index', 'payment_id, status', false]] as [$table, $name, $columns, $unique]) {
            $indexes = $this->db->getDriverName() === 'sqlite'
                ? array_column($this->db->select("PRAGMA index_list({$table})"), 'name')
                : array_column($this->db->select("SHOW INDEX FROM {$table}"), 'Key_name');
            if (!in_array($name, $indexes, true)) {
                $this->db->statement('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table} ({$columns})");
            }
        }
    }
}
