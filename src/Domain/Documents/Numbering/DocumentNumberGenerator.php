<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Numbering;

use Coleza\Domain\Documents\DocumentType;
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

        $stmt = $this->pdo->prepare(
            'SELECT last_number FROM document_sequences WHERE tenant_id = :tenant_id AND document_type = :doc_type AND year = :year'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':doc_type' => $type,
            ':year' => $year,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $next = 1;
            $ins = $this->pdo->prepare(
                'INSERT INTO document_sequences (tenant_id, document_type, year, last_number) VALUES (:tenant_id, :doc_type, :year, :last_number)'
            );
            $ins->execute([
                ':tenant_id' => $tenantId,
                ':doc_type' => $type,
                ':year' => $year,
                ':last_number' => $next,
            ]);
            return $next;
        }

        $next = ((int) $row['last_number']) + 1;
        $upd = $this->pdo->prepare(
            'UPDATE document_sequences SET last_number = :last_number WHERE tenant_id = :tenant_id AND document_type = :doc_type AND year = :year'
        );
        $upd->execute([
            ':last_number' => $next,
            ':tenant_id' => $tenantId,
            ':doc_type' => $type,
            ':year' => $year,
        ]);

        return $next;
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS document_sequences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id VARCHAR(64) NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                year INTEGER NOT NULL,
                last_number INTEGER NOT NULL DEFAULT 0,
                UNIQUE(tenant_id, document_type, year)
            )'
        );
    }
}
