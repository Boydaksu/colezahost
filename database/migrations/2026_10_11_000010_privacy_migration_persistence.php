<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneSchema::ensure($db);
        (new \Coleza\Domain\Privacy\PrivacyConsentService($db))->ensureTables();
        (new \Coleza\Domain\Privacy\Requests\PrivacyRequestService($db))->ensureTables();
        (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($db))->ensureTables();
        (new \Coleza\Domain\Privacy\Retention\RetentionAndLegalHoldService($db))->ensureTables();
        (new \Coleza\Domain\Privacy\Erasure\PrivacyErasureService($db))->ensureTables();
        (new \Coleza\Domain\Migration\Staging\DatabaseStagingRepository($db))->ensureTable();
        (new \Coleza\Domain\Migration\Execution\MigrationCheckpointRepository($db))->ensureTable();
        (new \Coleza\Domain\Migration\Hold\MigrationHoldRepository($db))->ensureTable();
        (new \Coleza\Domain\Migration\Hold\SuppressedNotificationRepository($db))->ensureTable();
        (new \Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository($db))->ensureTable();
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Privacy and migration history rollback requires a verified backup.');
    }
};
