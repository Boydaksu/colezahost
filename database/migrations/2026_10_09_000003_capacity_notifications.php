<?php

declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        $servers = new \Coleza\Domain\Servers\Services\ServerService($db);
        $servers->ensureTables();
        (new \Coleza\Domain\Servers\Capacity\Services\CapacityReservationService($db, $servers))->ensureTables();
        (new \Coleza\Domain\Servers\Capacity\Services\CapacityScopeSchema($db))->upgrade();
        new \Coleza\Domain\Notifications\Center\NotificationCenterService($db->getPdo());
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Capacity migration requires a verified backup for rollback.');
    }
};
