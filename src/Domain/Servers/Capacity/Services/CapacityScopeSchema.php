<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Capacity\Services;

use Coleza\Foundation\Database\Connection;
use RuntimeException;

final class CapacityScopeSchema
{
    public function __construct(private Connection $db) {}

    public function upgrade(): void
    {
        if ($this->db->getDriverName() === 'sqlite' && !$this->db->inTransaction()) { $this->db->transaction(fn () => $this->upgradeSchema()); }
        else { $this->upgradeSchema(); }
    }

    private function upgradeSchema(): void
    {
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) { throw new RuntimeException('Capacity migration requires no application transaction.'); }
        $scopes = [];
        $orderItems = [];
        $updates = [];
        foreach ($this->db->select('SELECT id, service_id, order_item_id, accounts_count, disk_mb, bandwidth_mb FROM capacity_reservations WHERE status IN ("reserved", "committed")') as $row) {
            if ((int) $row['accounts_count'] <= 0 || (int) $row['disk_mb'] < 0 || (int) $row['bandwidth_mb'] < 0
                || ($row['service_id'] !== null && (int) $row['service_id'] <= 0)
                || ($row['order_item_id'] !== null && (int) $row['order_item_id'] <= 0)) {
                throw new RuntimeException('Invalid legacy capacity allocation; reconciliation required.');
            }
            $scope = $row['service_id'] !== null ? 'service:' . $row['service_id'] : ($row['order_item_id'] !== null ? 'order_item:' . $row['order_item_id'] : null);
            if ($scope !== null && isset($scopes[$scope])) { throw new RuntimeException('Duplicate active capacity scope; reconciliation required.'); }
            if ($scope !== null) { $scopes[$scope] = true; }
            $orderKey = $row['order_item_id'] !== null ? 'order_item:' . $row['order_item_id'] : null;
            if ($orderKey !== null && isset($orderItems[$orderKey])) { throw new RuntimeException('Duplicate active order item capacity scope; reconciliation required.'); }
            if ($orderKey !== null) { $orderItems[$orderKey] = true; }
            $updates[] = [(int) $row['id'], $scope, $orderKey];
        }
        $columns = $this->db->getDriverName() === 'sqlite' ? array_column($this->db->select('PRAGMA table_info(capacity_reservations)'), 'name') : array_column($this->db->select('SHOW COLUMNS FROM capacity_reservations'), 'Field');
        if (!in_array('scope_key', $columns, true)) { $this->db->statement('ALTER TABLE capacity_reservations ADD COLUMN scope_key VARCHAR(64) NULL'); }
        if (!in_array('order_item_scope_key', $columns, true)) { $this->db->statement('ALTER TABLE capacity_reservations ADD COLUMN order_item_scope_key VARCHAR(64) NULL'); }
        $this->db->statement('UPDATE capacity_reservations SET scope_key = NULL, order_item_scope_key = NULL');
        foreach ($updates as [$id, $scope, $orderKey]) { $this->db->statement('UPDATE capacity_reservations SET scope_key = ?, order_item_scope_key = ? WHERE id = ?', [$scope, $orderKey, $id]); }
        $indexes = $this->db->getDriverName() === 'sqlite' ? array_column($this->db->select('PRAGMA index_list(capacity_reservations)'), 'name') : array_column($this->db->select('SHOW INDEX FROM capacity_reservations'), 'Key_name');
        if (!in_array('capacity_active_scope_unique', $indexes, true)) { $this->db->statement('CREATE UNIQUE INDEX capacity_active_scope_unique ON capacity_reservations (scope_key)'); }
        if (!in_array('capacity_order_item_unique', $indexes, true)) { $this->db->statement('CREATE UNIQUE INDEX capacity_order_item_unique ON capacity_reservations (order_item_scope_key)'); }
        if (!in_array('capacity_server_status_index', $indexes, true)) { $this->db->statement('CREATE INDEX capacity_server_status_index ON capacity_reservations (server_id, status)'); }
        if (!in_array('capacity_expiration_index', $indexes, true)) { $this->db->statement('CREATE INDEX capacity_expiration_index ON capacity_reservations (status, expires_at)'); }
    }
}
