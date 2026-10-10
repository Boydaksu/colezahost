<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Numbering;

use Coleza\Domain\Documents\DocumentType;
use Coleza\Foundation\Database\PdoSchema;
use PDO;

final class DocumentNumberGenerator
{
    /**
     * @var array<string, int> In-memory fallback sequences when PDO is not used
     */
    private array $memorySequences = [];

    /**
     * @param PDO|null $pdo
     * @param array<string, string> $customPrefixes Map of DocumentType->value => custom prefix
     * @param int $paddingLength Default is 6 digits (e.g. 000001)
     */
    public function __construct(
        private ?PDO $pdo = null,
        private array $customPrefixes = [],
        private int $paddingLength = 6
    ) {
        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    public function generateNextNumber(
        DocumentType $type,
        string|int $tenantId = '1',
        ?int $year = null
    ): string {
        $year ??= (int) date('Y');
        $tenant = (string) $tenantId;
        $prefix = $this->getPrefix($type);

        $seq = $this->incrementSequence($tenant, $type->value, $year);

        return sprintf('%s-%d-%0' . $this->paddingLength . 'd', $prefix, $year, $seq);
    }

    public function getPrefix(DocumentType $type): string
    {
        if (isset($this->customPrefixes[$type->value])) {
            return strtoupper($this->customPrefixes[$type->value]);
        }

        return match ($type) {
            DocumentType::INVOICE => 'INV',
            DocumentType::QUOTE => 'QUO',
            DocumentType::PROFORMA => 'PRO',
            DocumentType::RECEIPT => 'REC',
            DocumentType::CREDIT_NOTE => 'CRN',
            DocumentType::CONTRACT => 'CTR',
        };
    }

    /** Global-unique document columns require a shared counter across customer organizations. */
    public function generateNextGlobalNumber(DocumentType $type, ?int $year = null): string
    {
        return $this->generateNextNumber($type, '__global__', $year);
    }

    public function preserveLegacyGlobalCounters(): void
    {
        if ($this->pdo === null) { return; }
        $rows = $this->pdo->query('SELECT document_type, year, MAX(last_number) AS n FROM document_sequences GROUP BY document_type, year')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $sql = 'INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES (?, ?, ?, ?)';
            $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
                : ' ON CONFLICT(tenant_id, document_type, year) DO UPDATE SET last_number = MAX(last_number, excluded.last_number)';
            $this->pdo->prepare($sql)->execute(['__global__', $row['document_type'], $row['year'], $row['n']]);
        }
    }

    /**
     * Parses a document number string into its constituent components.
     *
     * @param string $number
     * @return array{prefix: string, year: int, sequence: int}|null
     */
    public function parseNumber(string $number): ?array
    {
        if (!preg_match('/^([A-Z]{3,5})-(\d{4})-(\d{4,8})$/', $number, $matches)) {
            return null;
        }

        return [
            'prefix' => $matches[1],
            'year' => (int) $matches[2],
            'sequence' => (int) $matches[3],
        ];
    }

    private function incrementSequence(string $tenantId, string $type, int $year): int
    {
        if ($this->pdo === null) {
            $key = "{$tenantId}:{$type}:{$year}";
            $this->memorySequences[$key] = ($this->memorySequences[$key] ?? 0) + 1;
            return $this->memorySequences[$key];
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO document_sequences (tenant_id, document_type, year, last_number)
                 VALUES (?, ?, ?, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
            );
            $stmt->execute([$tenantId, $type, $year]);
            // A newly inserted row sets LAST_INSERT_ID to its surrogate id, not its counter.
            // Updated rows report two affected rows and retain the explicit counter result.
            return $stmt->rowCount() === 1 ? 1 : (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            // The write locks SQLite until the counter has been read (including outer transactions).
            $stmt = $this->pdo->prepare(
                'INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES (?, ?, ?, 1)
                 ON CONFLICT(tenant_id, document_type, year) DO UPDATE SET last_number = last_number + 1'
            );
            $stmt->execute([$tenantId, $type, $year]);
            $stmt = $this->pdo->prepare('SELECT last_number FROM document_sequences WHERE tenant_id = ? AND document_type = ? AND year = ?');
            $stmt->execute([$tenantId, $type, $year]);
            $next = (int) $stmt->fetchColumn();
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $next;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $id = PdoSchema::autoIncrement($this->pdo);
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS document_sequences (
                id {$id},
                tenant_id VARCHAR(64) NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                year INTEGER NOT NULL,
                last_number INTEGER NOT NULL DEFAULT 0,
                UNIQUE(tenant_id, document_type, year)
            )"
        );
    }
}
