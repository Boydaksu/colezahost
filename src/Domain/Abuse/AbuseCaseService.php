<?php

declare(strict_types=1);

namespace Coleza\Domain\Abuse;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class AbuseCaseService
{
    private string $casesTable = 'abuse_cases';
    private string $messagesTable = 'abuse_case_messages';

    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlCases = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                case_number VARCHAR(50) NOT NULL UNIQUE,
                category VARCHAR(50) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'open\',
                organization_id INT NULL,
                user_id INT NULL,
                reporter_email VARCHAR(150) NOT NULL,
                reporter_name VARCHAR(150) NULL,
                resource_type VARCHAR(50) NOT NULL,
                resource_id INT NULL,
                resource_identifier VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                evidence_text TEXT NULL,
                evidence_url VARCHAR(500) NULL,
                deadline_at TIMESTAMP NULL,
                resolved_at TIMESTAMP NULL,
                resolution_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->casesTable,
            $autoInc
        );
        $this->db->statement($sqlCases);

        $sqlMessages = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                case_id INT NOT NULL,
                author_type VARCHAR(30) NOT NULL,
                author_id INT NULL,
                message TEXT NOT NULL,
                is_internal TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->messagesTable,
            $autoInc
        );
        $this->db->statement($sqlMessages);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_abuse_resource ON {$this->casesTable} (resource_type, resource_identifier)");
                $this->db->statement("CREATE INDEX idx_abuse_status ON {$this->casesTable} (status, deadline_at)");
                $this->db->statement("CREATE INDEX idx_abuse_messages_case ON {$this->messagesTable} (case_id)");
            } catch (\Throwable) {
                // Ignore if indices exist
            }
        }
    }

    public function createCase(
        AbuseCategory $category,
        AbuseSeverity $severity,
        string $reporterEmail,
        string $resourceType,
        string $resourceIdentifier,
        string $subject,
        string $description,
        ?string $reporterName = null,
        ?int $organizationId = null,
        ?int $userId = null,
        ?int $resourceId = null,
        ?string $evidenceText = null,
        ?string $evidenceUrl = null,
        ?DateTimeImmutable $customDeadline = null
    ): AbuseCase {
        $this->ensureTables();

        $errors = [];
        $trimmedEmail = trim($reporterEmail);
        if ($trimmedEmail === '' || !filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['reporter_email'][] = 'A valid reporter email address is required.';
        }

        $trimmedSubject = trim($subject);
        if ($trimmedSubject === '') {
            $errors['subject'][] = 'Abuse case subject cannot be empty.';
        }

        $trimmedDesc = trim($description);
        if ($trimmedDesc === '') {
            $errors['description'][] = 'Abuse case description cannot be empty.';
        }

        $trimmedResource = trim($resourceIdentifier);
        if ($trimmedResource === '') {
            $errors['resource_identifier'][] = 'Target resource identifier cannot be empty.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Abuse case validation failed.');
        }

        $now = new DateTimeImmutable();
        $deadline = $customDeadline ?? $now->modify(sprintf('+%d hours', $severity->getDefaultDeadlineHours()));
        $caseNumber = $this->generateCaseNumber();

        $data = [
            'case_number' => $caseNumber,
            'category' => $category->value,
            'severity' => $severity->value,
            'status' => AbuseCaseStatus::OPEN->value,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'reporter_email' => $trimmedEmail,
            'reporter_name' => $reporterName !== null ? trim($reporterName) : null,
            'resource_type' => strtolower(trim($resourceType)),
            'resource_id' => $resourceId,
            'resource_identifier' => $trimmedResource,
            'subject' => $trimmedSubject,
            'description' => $trimmedDesc,
            'evidence_text' => $evidenceText !== null ? trim($evidenceText) : null,
            'evidence_url' => $evidenceUrl !== null ? trim($evidenceUrl) : null,
            'deadline_at' => $deadline->format('Y-m-d H:i:s'),
            'resolved_at' => null,
            'resolution_notes' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];

        $id = (int) $this->db->insert($this->casesTable, $data);

        return new AbuseCase(
            id: $id,
            caseNumber: $caseNumber,
            category: $category,
            severity: $severity,
            status: AbuseCaseStatus::OPEN,
            organizationId: $organizationId,
            userId: $userId,
            reporterEmail: $trimmedEmail,
            reporterName: $reporterName !== null ? trim($reporterName) : null,
            resourceType: strtolower(trim($resourceType)),
            resourceId: $resourceId,
            resourceIdentifier: $trimmedResource,
            subject: $trimmedSubject,
            description: $trimmedDesc,
            evidenceText: $evidenceText !== null ? trim($evidenceText) : null,
            evidenceUrl: $evidenceUrl !== null ? trim($evidenceUrl) : null,
            deadlineAt: $deadline,
            resolvedAt: null,
            resolutionNotes: null,
            createdAt: $now,
            updatedAt: $now
        );
    }

    public function addMessage(
        int $caseId,
        string $authorType,
        string $message,
        ?int $authorId = null,
        bool $isInternal = false
    ): AbuseMessage {
        $case = $this->getCaseOrFail($caseId);
        $trimmedMsg = trim($message);

        if ($trimmedMsg === '') {
            throw new ValidationException(['message' => ['Message body cannot be empty.']], 'Message body cannot be empty.');
        }

        $now = new DateTimeImmutable();
        $msgData = [
            'case_id' => $caseId,
            'author_type' => strtolower(trim($authorType)),
            'author_id' => $authorId,
            'message' => $trimmedMsg,
            'is_internal' => $isInternal ? 1 : 0,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        $id = (int) $this->db->insert($this->messagesTable, $msgData);

        // Auto-update case status based on author
        $nextStatus = $case->getStatus();
        if (!$isInternal && $case->getStatus()->isOpen()) {
            if ($authorType === 'client') {
                $nextStatus = AbuseCaseStatus::CLIENT_RESPONDED;
            } elseif ($authorType === 'staff') {
                $nextStatus = AbuseCaseStatus::WAITING_CLIENT_RESPONSE;
            }
        }

        $this->db->statement(
            "UPDATE {$this->casesTable} SET status = :status, updated_at = :updated_at WHERE id = :id",
            [
                'status' => $nextStatus->value,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $caseId,
            ]
        );

        return new AbuseMessage(
            id: $id,
            caseId: $caseId,
            authorType: strtolower(trim($authorType)),
            authorId: $authorId,
            message: $trimmedMsg,
            isInternal: $isInternal,
            createdAt: $now
        );
    }

    public function resolveCase(int $caseId, int $staffId, string $resolutionNotes): AbuseCase
    {
        $this->getCaseOrFail($caseId);
        $trimmedNotes = trim($resolutionNotes);

        if ($trimmedNotes === '') {
            throw new ValidationException(['resolution_notes' => ['Resolution notes must be provided.']], 'Resolution notes must be provided.');
        }

        $now = new DateTimeImmutable();
        $this->db->statement(
            "UPDATE {$this->casesTable}
             SET status = :status, resolved_at = :resolved_at, resolution_notes = :notes, updated_at = :updated_at
             WHERE id = :id",
            [
                'status' => AbuseCaseStatus::RESOLVED->value,
                'resolved_at' => $now->format('Y-m-d H:i:s'),
                'notes' => $trimmedNotes,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $caseId,
            ]
        );

        return $this->getCaseOrFail($caseId);
    }

    public function dismissCase(int $caseId, int $staffId, string $reason): AbuseCase
    {
        $this->getCaseOrFail($caseId);
        $trimmedReason = trim($reason);

        if ($trimmedReason === '') {
            throw new ValidationException(['reason' => ['Dismissal reason must be provided.']], 'Dismissal reason must be provided.');
        }

        $now = new DateTimeImmutable();
        $this->db->statement(
            "UPDATE {$this->casesTable}
             SET status = :status, resolved_at = :resolved_at, resolution_notes = :notes, updated_at = :updated_at
             WHERE id = :id",
            [
                'status' => AbuseCaseStatus::DISMISSED->value,
                'resolved_at' => $now->format('Y-m-d H:i:s'),
                'notes' => $trimmedReason,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $caseId,
            ]
        );

        return $this->getCaseOrFail($caseId);
    }

    public function suspendResourceAction(int $caseId, int $staffId, string $notes): AbuseCase
    {
        $this->getCaseOrFail($caseId);
        $trimmedNotes = trim($notes);

        if ($trimmedNotes === '') {
            throw new ValidationException(['notes' => ['Suspension action notes must be provided.']], 'Suspension action notes must be provided.');
        }

        $now = new DateTimeImmutable();
        $this->db->statement(
            "UPDATE {$this->casesTable}
             SET status = :status, resolution_notes = :notes, updated_at = :updated_at
             WHERE id = :id",
            [
                'status' => AbuseCaseStatus::SUSPENDED->value,
                'notes' => $trimmedNotes,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $caseId,
            ]
        );

        $this->addMessage(
            caseId: $caseId,
            authorType: 'staff',
            message: sprintf('Resource suspended by staff #%d. Note: %s', $staffId, $trimmedNotes),
            authorId: $staffId,
            isInternal: true
        );

        return $this->getCaseOrFail($caseId);
    }

    /**
     * @return list<AbuseCase>
     */
    public function getOverdueCases(?DateTimeImmutable $now = null): array
    {
        $this->ensureTables();

        $current = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $rows = $this->db->select(
            "SELECT * FROM {$this->casesTable}
             WHERE status IN ('open', 'waiting_client_response', 'client_responded', 'under_review')
               AND deadline_at IS NOT NULL
               AND deadline_at < :now
             ORDER BY deadline_at ASC",
            ['now' => $current]
        );

        return array_map(fn (array $r) => $this->hydrateCase($r), $rows);
    }

    public function getCase(int $caseId): ?AbuseCase
    {
        $this->ensureTables();

        $row = $this->db->selectOne("SELECT * FROM {$this->casesTable} WHERE id = :id LIMIT 1", ['id' => $caseId]);
        return $row !== null ? $this->hydrateCase($row) : null;
    }

    public function getCaseOrFail(int $caseId): AbuseCase
    {
        $case = $this->getCase($caseId);
        if ($case === null) {
            throw new ValidationException(['case' => [sprintf('Abuse case #%d not found.', $caseId)]], sprintf('Abuse case #%d not found.', $caseId));
        }
        return $case;
    }

    public function getCaseByNumber(string $caseNumber): ?AbuseCase
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            "SELECT * FROM {$this->casesTable} WHERE case_number = :number LIMIT 1",
            ['number' => trim($caseNumber)]
        );

        return $row !== null ? $this->hydrateCase($row) : null;
    }

    /**
     * @return list<AbuseCase>
     */
    public function getCasesForResource(string $resourceType, string $resourceIdentifier): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->casesTable}
             WHERE resource_type = :type AND resource_identifier = :identifier
             ORDER BY id DESC",
            [
                'type' => strtolower(trim($resourceType)),
                'identifier' => trim($resourceIdentifier),
            ]
        );

        return array_map(fn (array $r) => $this->hydrateCase($r), $rows);
    }

    /**
     * @return list<AbuseCase>
     */
    public function getCasesForUser(int $userId): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->casesTable} WHERE user_id = :user_id ORDER BY id DESC",
            ['user_id' => $userId]
        );

        return array_map(fn (array $r) => $this->hydrateCase($r), $rows);
    }

    /**
     * @return list<AbuseCase>
     */
    public function getCasesForOrganization(int $orgId): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->casesTable} WHERE organization_id = :org_id ORDER BY id DESC",
            ['org_id' => $orgId]
        );

        return array_map(fn (array $r) => $this->hydrateCase($r), $rows);
    }

    /**
     * @return list<AbuseMessage>
     */
    public function getMessages(int $caseId, bool $includeInternal = true): array
    {
        $this->ensureTables();

        $sql = "SELECT * FROM {$this->messagesTable} WHERE case_id = :case_id";
        if (!$includeInternal) {
            $sql .= " AND is_internal = 0";
        }
        $sql .= " ORDER BY id ASC";

        $rows = $this->db->select($sql, ['case_id' => $caseId]);
        return array_map(fn (array $r) => $this->hydrateMessage($r), $rows);
    }

    private function generateCaseNumber(): string
    {
        $year = (new DateTimeImmutable())->format('Y');
        $random = strtoupper(bin2hex(random_bytes(3)));
        return sprintf('ABUSE-%s-%s', $year, $random);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCase(array $row): AbuseCase
    {
        return new AbuseCase(
            id: (int) $row['id'],
            caseNumber: (string) $row['case_number'],
            category: AbuseCategory::from((string) $row['category']),
            severity: AbuseSeverity::from((string) $row['severity']),
            status: AbuseCaseStatus::from((string) $row['status']),
            organizationId: isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            reporterEmail: (string) $row['reporter_email'],
            reporterName: isset($row['reporter_name']) ? (string) $row['reporter_name'] : null,
            resourceType: (string) $row['resource_type'],
            resourceId: isset($row['resource_id']) ? (int) $row['resource_id'] : null,
            resourceIdentifier: (string) $row['resource_identifier'],
            subject: (string) $row['subject'],
            description: (string) $row['description'],
            evidenceText: isset($row['evidence_text']) ? (string) $row['evidence_text'] : null,
            evidenceUrl: isset($row['evidence_url']) ? (string) $row['evidence_url'] : null,
            deadlineAt: !empty($row['deadline_at']) ? new DateTimeImmutable((string) $row['deadline_at']) : null,
            resolvedAt: !empty($row['resolved_at']) ? new DateTimeImmutable((string) $row['resolved_at']) : null,
            resolutionNotes: isset($row['resolution_notes']) ? (string) $row['resolution_notes'] : null,
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: !empty($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateMessage(array $row): AbuseMessage
    {
        return new AbuseMessage(
            id: (int) $row['id'],
            caseId: (int) $row['case_id'],
            authorType: (string) $row['author_type'],
            authorId: isset($row['author_id']) ? (int) $row['author_id'] : null,
            message: (string) $row['message'],
            isInternal: (bool) $row['is_internal'],
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null
        );
    }
}
