<?php
declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        $departments = new \Coleza\Domain\Support\Departments\DepartmentService($db);
        $departments->ensureTables();
        $tickets = new \Coleza\Domain\Support\Tickets\TicketService($db, $departments);
        $tickets->ensureTables();
        (new \Coleza\Domain\Support\Sla\SlaService($db, $tickets))->ensureTables();
        (new \Coleza\Domain\Support\Assignments\TicketAssignmentService($db, $departments, $tickets))->ensureTables();
        (new \Coleza\Domain\Support\CannedResponses\CannedResponseService($db, $departments))->ensureTables();
        (new \Coleza\Domain\Support\Announcements\AnnouncementService($db))->ensureTables();
        // Schema-only entry points do not instantiate filesystem storage or provider handlers.
        \Coleza\Domain\Support\Attachments\AttachmentService::installSchema($db);
        \Coleza\Domain\Support\Context\TicketContextService::installSchema($db);
        (new \Coleza\Api\Auth\ApiKeyService($db))->ensureTable();
        (new \Coleza\Api\Logging\ApiRequestLogger($db))->ensureTable();
        (new \Coleza\Api\RateLimit\RateLimiter($db))->ensureTable();
        $sessions = new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($db);
        $sessions->ensureTable();
        $hasher = new \Coleza\Domain\Identity\Security\PasswordHasher();
        (new \Coleza\Domain\Identity\Auth\AuthService($db, $hasher, $sessions))->ensureTables();
        (new \Coleza\Domain\Identity\TwoFactor\TwoFactorService($db, new \Coleza\Domain\Identity\TwoFactor\TotpEngine(), $hasher))->ensureTables();
        (new \Coleza\Domain\Identity\Audit\AuditLogger($db))->ensureTable();
        \Coleza\Domain\Documents\Contracts\ContractService::installSchema($db->getPdo());
        \Coleza\Domain\Documents\Snapshots\DocumentSnapshotService::installSchema($db->getPdo());

        // Keep the visible number format while reconciling legacy tenant counters and imported contracts.
        foreach ($db->select('SELECT contract_number FROM contracts') as $row) {
            if (!preg_match('/^[A-Z]{3,5}-(\d{4})-(\d+)(?:-.*)?$/D', $row['contract_number'], $parts)) {
                throw new RuntimeException('Unrecognized legacy contract number; reconcile before migration.');
            }
            $sql = 'INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES (?, ?, ?, ?)';
            $sql .= $db->getDriverName() === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
                : ' ON CONFLICT(tenant_id, document_type, year) DO UPDATE SET last_number = MAX(last_number, excluded.last_number)';
            $db->statement($sql, ['__global__', 'contract', (int) $parts[1], (int) $parts[2]]);
        }
        (new \Coleza\Domain\Documents\Numbering\DocumentNumberGenerator($db->getPdo()))->preserveLegacyGlobalCounters();
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Module schema rollback requires a verified backup.');
    }
};
