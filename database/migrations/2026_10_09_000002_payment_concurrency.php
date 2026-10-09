<?php

declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Domain\Commerce\Payments\PaymentService($db))->ensureTables();
        (new \Coleza\Domain\Commerce\Payments\PaymentConcurrencySchema($db))->upgrade();
        (new \Coleza\Foundation\Queue\DatabaseQueue($db))->ensureTables();
        $columns = $db->getDriverName() === 'sqlite'
            ? array_column($db->select('PRAGMA table_info(jobs)'), 'name')
            : array_column($db->select('SHOW COLUMNS FROM jobs'), 'Field');
        if (!in_array('reservation_token', $columns, true)) {
            $db->statement('ALTER TABLE jobs ADD COLUMN reservation_token VARCHAR(64) NULL');
        }
        // Reservations from an older worker must not acknowledge a job under the new lease contract.
        $db->statement('UPDATE jobs SET reserved_at = NULL WHERE reservation_token IS NULL');
    }

    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Payment concurrency migration requires a verified backup for rollback.');
    }
};
