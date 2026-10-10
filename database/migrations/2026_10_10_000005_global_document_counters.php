<?php
declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        $generator = new \Coleza\Domain\Documents\Numbering\DocumentNumberGenerator($db->getPdo());
        // Imported documents can exist without a matching legacy counter. Preserve their upper bound too.
        foreach (['quotes' => ['quote_number', 'quote'], 'proforma_invoices' => ['proforma_number', 'proforma']] as $table => [$column, $type]) {
            foreach ($db->select("SELECT {$column} AS number FROM {$table}") as $row) {
                if (!preg_match('/^[A-Z]{3,5}-(\d{4})-(\d+)(?:-.*)?$/D', $row['number'], $parts)) {
                    throw new RuntimeException('Unrecognized legacy document number; reconcile before migration.');
                }
                $sql = 'INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES (?, ?, ?, ?)';
                $sql .= $db->getDriverName() === 'mysql'
                    ? ' ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
                    : ' ON CONFLICT(tenant_id, document_type, year) DO UPDATE SET last_number = MAX(last_number, excluded.last_number)';
                $db->statement($sql, ['__global__', $type, (int) $parts[1], (int) $parts[2]]);
            }
        }
        $generator->preserveLegacyGlobalCounters();
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Global document numbering rollback requires a verified backup.');
    }
};
