<?php

declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        new \Coleza\Domain\Documents\Quotes\QuoteService($db->getPdo());
        new \Coleza\Domain\Documents\Proforma\ProformaService($db->getPdo());
        new \Coleza\Domain\Notifications\Announcements\AnnouncementService($db->getPdo());
    }

    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Document migration requires a verified backup for rollback.');
    }
};
