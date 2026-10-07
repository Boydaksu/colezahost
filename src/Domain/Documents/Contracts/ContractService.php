<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Contracts;

use Coleza\Domain\Documents\DocumentType;
use Coleza\Domain\Documents\Numbering\DocumentNumberGenerator;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use RuntimeException;

final class ContractService
{
    private DocumentNumberGenerator $numberGenerator;
    private ContractStateMachine $stateMachine;

    /**
     * @var array<int, Contract> In-memory cache when PDO is null
     */
    private array $memoryContracts = [];

    public function __construct(
        private ?PDO $pdo = null,
        ?DocumentNumberGenerator $numberGenerator = null,
        ?ContractStateMachine $stateMachine = null
    ) {
        $this->numberGenerator = $numberGenerator ?? new DocumentNumberGenerator($this->pdo);
        $this->stateMachine = $stateMachine ?? new ContractStateMachine();

        if ($this->pdo !== null) {
            $this->ensureSchema();
        }
    }

    /**
     * @param int $userId
     * @param string $title
     * @param string $termsVersion
     * @param string $termsText
     * @param int $commitmentPeriodMonths
     * @param float $slaCommitmentUptimePercent
     * @param string|null $startDate
     * @param int|null $serviceId
     * @param int|null $orderId
     * @param int|null $organizationId
     * @param string|null $notes
     * @return Contract
     */
    public function createContract(
        int $userId,
        string $title,
        string $termsVersion,
        string $termsText,
        int $commitmentPeriodMonths = 12,
        float $slaCommitmentUptimePercent = 99.9,
        ?string $startDate = null,
        ?int $serviceId = null,
        ?int $orderId = null,
        ?int $organizationId = null,
        ?string $notes = null
    ): Contract {
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => ['Valid user ID is required.']], 'Invalid user');
        }

        if (trim($title) === '') {
            throw new ValidationException(['title' => ['Contract title is required.']], 'Invalid title');
        }

        $formattedStartDate = $startDate ?? date('Y-m-d');
        $endDate = null;
        if ($commitmentPeriodMonths > 0) {
            $endDate = date('Y-m-d', strtotime("{$formattedStartDate} +{$commitmentPeriodMonths} months"));
        }

        $contractNumber = $this->numberGenerator->generateNextNumber(
            DocumentType::CONTRACT,
            $organizationId !== null ? (string) $organizationId : '1'
        );

        $now = date('c');

        $contract = new Contract(
            id: null,
            contractNumber: $contractNumber,
            userId: $userId,
            organizationId: $organizationId,
            serviceId: $serviceId,
            orderId: $orderId,
            title: $title,
            status: ContractStatus::DRAFT,
            commitmentPeriodMonths: $commitmentPeriodMonths,
            startDate: $formattedStartDate,
            endDate: $endDate,
            termsVersion: $termsVersion,
            termsText: $termsText,
            slaCommitmentUptimePercent: $slaCommitmentUptimePercent,
            clientAcceptedAt: null,
            clientAcceptedIp: null,
            clientAcceptedUserAgent: null,
            clientAcceptedSignature: null,
            internalAcceptedByUserId: null,
            internalAcceptedAt: null,
            terminatedAt: null,
            terminationReason: null,
            earlyTerminationFeeMinor: null,
            notes: $notes,
            createdAt: $now,
            updatedAt: $now
        );

        return $this->persistContract($contract);
    }

    public function submitForAcceptance(int $id): Contract
    {
        $contract = $this->find($id);
        if ($contract === null) {
            throw new RuntimeException("Contract #{$id} not found.");
        }

        $newStatus = $this->stateMachine->transition($contract->getStatus(), ContractStatus::PENDING_ACCEPTANCE);
        $now = date('c');

        $updated = new Contract(
            id: $contract->getId(),
            contractNumber: $contract->getContractNumber(),
            userId: $contract->getUserId(),
            organizationId: $contract->getOrganizationId(),
            serviceId: $contract->getServiceId(),
            orderId: $contract->getOrderId(),
            title: $contract->getTitle(),
            status: $newStatus,
            commitmentPeriodMonths: $contract->getCommitmentPeriodMonths(),
            startDate: $contract->getStartDate(),
            endDate: $contract->getEndDate(),
            termsVersion: $contract->getTermsVersion(),
            termsText: $contract->getTermsText(),
            slaCommitmentUptimePercent: $contract->getSlaCommitmentUptimePercent(),
            clientAcceptedAt: $contract->getClientAcceptedAt(),
            clientAcceptedIp: $contract->getClientAcceptedIp(),
            clientAcceptedUserAgent: $contract->getClientAcceptedUserAgent(),
            clientAcceptedSignature: $contract->getClientAcceptedSignature(),
            internalAcceptedByUserId: $contract->getInternalAcceptedByUserId(),
            internalAcceptedAt: $contract->getInternalAcceptedAt(),
            terminatedAt: $contract->getTerminatedAt(),
            terminationReason: $contract->getTerminationReason(),
            earlyTerminationFeeMinor: $contract->getEarlyTerminationFeeMinor(),
            notes: $contract->getNotes(),
            createdAt: $contract->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateContract($updated);
    }

    public function recordClientAcceptance(
        int $id,
        string $clientIp,
        string $userAgent,
        string $typedSignature,
        bool $autoActivate = false
    ): Contract {
        $contract = $this->find($id);
        if ($contract === null) {
            throw new RuntimeException("Contract #{$id} not found.");
        }

        if (trim($typedSignature) === '') {
            throw new ValidationException(['signature' => ['Legal typed signature cannot be blank.']], 'Invalid signature');
        }

        $now = date('c');
        $shouldActivate = $autoActivate || $contract->isInternalAccepted();
        $newStatus = $shouldActivate 
            ? $this->stateMachine->transition($contract->getStatus(), ContractStatus::ACTIVE) 
            : $contract->getStatus();

        $updated = new Contract(
            id: $contract->getId(),
            contractNumber: $contract->getContractNumber(),
            userId: $contract->getUserId(),
            organizationId: $contract->getOrganizationId(),
            serviceId: $contract->getServiceId(),
            orderId: $contract->getOrderId(),
            title: $contract->getTitle(),
            status: $newStatus,
            commitmentPeriodMonths: $contract->getCommitmentPeriodMonths(),
            startDate: $contract->getStartDate(),
            endDate: $contract->getEndDate(),
            termsVersion: $contract->getTermsVersion(),
            termsText: $contract->getTermsText(),
            slaCommitmentUptimePercent: $contract->getSlaCommitmentUptimePercent(),
            clientAcceptedAt: $now,
            clientAcceptedIp: $clientIp,
            clientAcceptedUserAgent: $userAgent,
            clientAcceptedSignature: trim($typedSignature),
            internalAcceptedByUserId: $contract->getInternalAcceptedByUserId(),
            internalAcceptedAt: $contract->getInternalAcceptedAt(),
            terminatedAt: $contract->getTerminatedAt(),
            terminationReason: $contract->getTerminationReason(),
            earlyTerminationFeeMinor: $contract->getEarlyTerminationFeeMinor(),
            notes: $contract->getNotes(),
            createdAt: $contract->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateContract($updated);
    }

    public function recordInternalAcceptance(int $id, int $adminUserId, bool $autoActivate = true): Contract
    {
        $contract = $this->find($id);
        if ($contract === null) {
            throw new RuntimeException("Contract #{$id} not found.");
        }

        $now = date('c');
        $shouldActivate = $autoActivate && $contract->isClientAccepted();
        $newStatus = $shouldActivate
            ? $this->stateMachine->transition($contract->getStatus(), ContractStatus::ACTIVE)
            : $contract->getStatus();

        $updated = new Contract(
            id: $contract->getId(),
            contractNumber: $contract->getContractNumber(),
            userId: $contract->getUserId(),
            organizationId: $contract->getOrganizationId(),
            serviceId: $contract->getServiceId(),
            orderId: $contract->getOrderId(),
            title: $contract->getTitle(),
            status: $newStatus,
            commitmentPeriodMonths: $contract->getCommitmentPeriodMonths(),
            startDate: $contract->getStartDate(),
            endDate: $contract->getEndDate(),
            termsVersion: $contract->getTermsVersion(),
            termsText: $contract->getTermsText(),
            slaCommitmentUptimePercent: $contract->getSlaCommitmentUptimePercent(),
            clientAcceptedAt: $contract->getClientAcceptedAt(),
            clientAcceptedIp: $contract->getClientAcceptedIp(),
            clientAcceptedUserAgent: $contract->getClientAcceptedUserAgent(),
            clientAcceptedSignature: $contract->getClientAcceptedSignature(),
            internalAcceptedByUserId: $adminUserId,
            internalAcceptedAt: $now,
            terminatedAt: $contract->getTerminatedAt(),
            terminationReason: $contract->getTerminationReason(),
            earlyTerminationFeeMinor: $contract->getEarlyTerminationFeeMinor(),
            notes: $contract->getNotes(),
            createdAt: $contract->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateContract($updated);
    }

    public function terminateContract(int $id, string $reason, ?int $earlyTerminationFeeMinor = null): Contract
    {
        $contract = $this->find($id);
        if ($contract === null) {
            throw new RuntimeException("Contract #{$id} not found.");
        }

        $newStatus = $this->stateMachine->transition($contract->getStatus(), ContractStatus::TERMINATED);
        $now = date('c');

        $updated = new Contract(
            id: $contract->getId(),
            contractNumber: $contract->getContractNumber(),
            userId: $contract->getUserId(),
            organizationId: $contract->getOrganizationId(),
            serviceId: $contract->getServiceId(),
            orderId: $contract->getOrderId(),
            title: $contract->getTitle(),
            status: $newStatus,
            commitmentPeriodMonths: $contract->getCommitmentPeriodMonths(),
            startDate: $contract->getStartDate(),
            endDate: $contract->getEndDate(),
            termsVersion: $contract->getTermsVersion(),
            termsText: $contract->getTermsText(),
            slaCommitmentUptimePercent: $contract->getSlaCommitmentUptimePercent(),
            clientAcceptedAt: $contract->getClientAcceptedAt(),
            clientAcceptedIp: $contract->getClientAcceptedIp(),
            clientAcceptedUserAgent: $contract->getClientAcceptedUserAgent(),
            clientAcceptedSignature: $contract->getClientAcceptedSignature(),
            internalAcceptedByUserId: $contract->getInternalAcceptedByUserId(),
            internalAcceptedAt: $contract->getInternalAcceptedAt(),
            terminatedAt: $now,
            terminationReason: $reason,
            earlyTerminationFeeMinor: $earlyTerminationFeeMinor,
            notes: $contract->getNotes(),
            createdAt: $contract->getCreatedAt(),
            updatedAt: $now
        );

        return $this->updateContract($updated);
    }

    public function calculateEarlyTerminationFee(
        int $id,
        int $monthlyRateMinor,
        float $penaltyPercent = 50.0,
        ?string $terminationDate = null
    ): int {
        $contract = $this->find($id);
        if ($contract === null || $contract->getEndDate() === null) {
            return 0;
        }

        $termDt = new \DateTimeImmutable($terminationDate ?? date('Y-m-d'));
        $endDt = new \DateTimeImmutable($contract->getEndDate());

        if ($termDt >= $endDt) {
            return 0; // Commitment fulfilled
        }

        $diff = $termDt->diff($endDt);
        $remainingMonths = ($diff->y * 12) + $diff->m + ($diff->d > 0 ? 1 : 0);

        $totalRemainingCommitment = $remainingMonths * $monthlyRateMinor;
        return (int) round(($totalRemainingCommitment * $penaltyPercent) / 100.0);
    }

    public function find(int $id): ?Contract
    {
        if ($this->pdo === null) {
            return $this->memoryContracts[$id] ?? null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM contracts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapContractRow($row) : null;
    }

    public function findByContractNumber(string $number): ?Contract
    {
        if ($this->pdo === null) {
            foreach ($this->memoryContracts as $c) {
                if ($c->getContractNumber() === $number) {
                    return $c;
                }
            }
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM contracts WHERE contract_number = :number');
        $stmt->execute([':number' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapContractRow($row) : null;
    }

    /**
     * @return array<Contract>
     */
    public function findByServiceId(int $serviceId): array
    {
        if ($this->pdo === null) {
            return array_values(array_filter($this->memoryContracts, fn($c) => $c->getServiceId() === $serviceId));
        }

        $stmt = $this->pdo->prepare('SELECT * FROM contracts WHERE service_id = :service_id ORDER BY id DESC');
        $stmt->execute([':service_id' => $serviceId]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->mapContractRow($row);
        }

        return $results;
    }

    private function persistContract(Contract $contract): Contract
    {
        if ($this->pdo === null) {
            $id = count($this->memoryContracts) + 1;
            $saved = new Contract(
                id: $id,
                contractNumber: $contract->getContractNumber(),
                userId: $contract->getUserId(),
                organizationId: $contract->getOrganizationId(),
                serviceId: $contract->getServiceId(),
                orderId: $contract->getOrderId(),
                title: $contract->getTitle(),
                status: $contract->getStatus(),
                commitmentPeriodMonths: $contract->getCommitmentPeriodMonths(),
                startDate: $contract->getStartDate(),
                endDate: $contract->getEndDate(),
                termsVersion: $contract->getTermsVersion(),
                termsText: $contract->getTermsText(),
                slaCommitmentUptimePercent: $contract->getSlaCommitmentUptimePercent(),
                clientAcceptedAt: $contract->getClientAcceptedAt(),
                clientAcceptedIp: $contract->getClientAcceptedIp(),
                clientAcceptedUserAgent: $contract->getClientAcceptedUserAgent(),
                clientAcceptedSignature: $contract->getClientAcceptedSignature(),
                internalAcceptedByUserId: $contract->getInternalAcceptedByUserId(),
                internalAcceptedAt: $contract->getInternalAcceptedAt(),
                terminatedAt: $contract->getTerminatedAt(),
                terminationReason: $contract->getTerminationReason(),
                earlyTerminationFeeMinor: $contract->getEarlyTerminationFeeMinor(),
                notes: $contract->getNotes(),
                createdAt: $contract->getCreatedAt(),
                updatedAt: $contract->getUpdatedAt()
            );

            $this->memoryContracts[$id] = $saved;
            return $saved;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO contracts (
                contract_number, user_id, organization_id, service_id, order_id,
                title, status, commitment_period_months, start_date, end_date,
                terms_version, terms_text, sla_commitment_uptime_percent,
                client_accepted_at, client_accepted_ip, client_accepted_user_agent,
                client_accepted_signature, internal_accepted_by_user_id, internal_accepted_at,
                terminated_at, termination_reason, early_termination_fee_minor,
                notes, created_at, updated_at
            ) VALUES (
                :contract_number, :user_id, :organization_id, :service_id, :order_id,
                :title, :status, :commitment_period_months, :start_date, :end_date,
                :terms_version, :terms_text, :sla_commitment_uptime_percent,
                :client_accepted_at, :client_accepted_ip, :client_accepted_user_agent,
                :client_accepted_signature, :internal_accepted_by_user_id, :internal_accepted_at,
                :terminated_at, :termination_reason, :early_termination_fee_minor,
                :notes, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            ':contract_number' => $contract->getContractNumber(),
            ':user_id' => $contract->getUserId(),
            ':organization_id' => $contract->getOrganizationId(),
            ':service_id' => $contract->getServiceId(),
            ':order_id' => $contract->getOrderId(),
            ':title' => $contract->getTitle(),
            ':status' => $contract->getStatus()->value,
            ':commitment_period_months' => $contract->getCommitmentPeriodMonths(),
            ':start_date' => $contract->getStartDate(),
            ':end_date' => $contract->getEndDate(),
            ':terms_version' => $contract->getTermsVersion(),
            ':terms_text' => $contract->getTermsText(),
            ':sla_commitment_uptime_percent' => $contract->getSlaCommitmentUptimePercent(),
            ':client_accepted_at' => $contract->getClientAcceptedAt(),
            ':client_accepted_ip' => $contract->getClientAcceptedIp(),
            ':client_accepted_user_agent' => $contract->getClientAcceptedUserAgent(),
            ':client_accepted_signature' => $contract->getClientAcceptedSignature(),
            ':internal_accepted_by_user_id' => $contract->getInternalAcceptedByUserId(),
            ':internal_accepted_at' => $contract->getInternalAcceptedAt(),
            ':terminated_at' => $contract->getTerminatedAt(),
            ':termination_reason' => $contract->getTerminationReason(),
            ':early_termination_fee_minor' => $contract->getEarlyTerminationFeeMinor(),
            ':notes' => $contract->getNotes(),
            ':created_at' => $contract->getCreatedAt(),
            ':updated_at' => $contract->getUpdatedAt(),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return new Contract(
            id: $id,
            contractNumber: $contract->getContractNumber(),
            userId: $contract->getUserId(),
            organizationId: $contract->getOrganizationId(),
            serviceId: $contract->getServiceId(),
            orderId: $contract->getOrderId(),
            title: $contract->getTitle(),
            status: $contract->getStatus(),
            commitmentPeriodMonths: $contract->getCommitmentPeriodMonths(),
            startDate: $contract->getStartDate(),
            endDate: $contract->getEndDate(),
            termsVersion: $contract->getTermsVersion(),
            termsText: $contract->getTermsText(),
            slaCommitmentUptimePercent: $contract->getSlaCommitmentUptimePercent(),
            clientAcceptedAt: $contract->getClientAcceptedAt(),
            clientAcceptedIp: $contract->getClientAcceptedIp(),
            clientAcceptedUserAgent: $contract->getClientAcceptedUserAgent(),
            clientAcceptedSignature: $contract->getClientAcceptedSignature(),
            internalAcceptedByUserId: $contract->getInternalAcceptedByUserId(),
            internalAcceptedAt: $contract->getInternalAcceptedAt(),
            terminatedAt: $contract->getTerminatedAt(),
            terminationReason: $contract->getTerminationReason(),
            earlyTerminationFeeMinor: $contract->getEarlyTerminationFeeMinor(),
            notes: $contract->getNotes(),
            createdAt: $contract->getCreatedAt(),
            updatedAt: $contract->getUpdatedAt()
        );
    }

    private function updateContract(Contract $contract): Contract
    {
        if ($contract->getId() === null) {
            throw new RuntimeException('Cannot update contract without ID.');
        }

        if ($this->pdo === null) {
            $this->memoryContracts[$contract->getId()] = $contract;
            return $contract;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE contracts SET
                status = :status,
                client_accepted_at = :client_accepted_at,
                client_accepted_ip = :client_accepted_ip,
                client_accepted_user_agent = :client_accepted_user_agent,
                client_accepted_signature = :client_accepted_signature,
                internal_accepted_by_user_id = :internal_accepted_by_user_id,
                internal_accepted_at = :internal_accepted_at,
                terminated_at = :terminated_at,
                termination_reason = :termination_reason,
                early_termination_fee_minor = :early_termination_fee_minor,
                updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $contract->getStatus()->value,
            ':client_accepted_at' => $contract->getClientAcceptedAt(),
            ':client_accepted_ip' => $contract->getClientAcceptedIp(),
            ':client_accepted_user_agent' => $contract->getClientAcceptedUserAgent(),
            ':client_accepted_signature' => $contract->getClientAcceptedSignature(),
            ':internal_accepted_by_user_id' => $contract->getInternalAcceptedByUserId(),
            ':internal_accepted_at' => $contract->getInternalAcceptedAt(),
            ':terminated_at' => $contract->getTerminatedAt(),
            ':termination_reason' => $contract->getTerminationReason(),
            ':early_termination_fee_minor' => $contract->getEarlyTerminationFeeMinor(),
            ':updated_at' => $contract->getUpdatedAt(),
            ':id' => $contract->getId(),
        ]);

        return $contract;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapContractRow(array $row): Contract
    {
        return new Contract(
            id: (int) $row['id'],
            contractNumber: (string) $row['contract_number'],
            userId: (int) $row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int) $row['organization_id'] : null,
            serviceId: $row['service_id'] !== null ? (int) $row['service_id'] : null,
            orderId: $row['order_id'] !== null ? (int) $row['order_id'] : null,
            title: (string) $row['title'],
            status: ContractStatus::from((string) $row['status']),
            commitmentPeriodMonths: (int) $row['commitment_period_months'],
            startDate: (string) $row['start_date'],
            endDate: $row['end_date'] !== null ? (string) $row['end_date'] : null,
            termsVersion: (string) $row['terms_version'],
            termsText: (string) $row['terms_text'],
            slaCommitmentUptimePercent: (float) $row['sla_commitment_uptime_percent'],
            clientAcceptedAt: $row['client_accepted_at'] !== null ? (string) $row['client_accepted_at'] : null,
            clientAcceptedIp: $row['client_accepted_ip'] !== null ? (string) $row['client_accepted_ip'] : null,
            clientAcceptedUserAgent: $row['client_accepted_user_agent'] !== null ? (string) $row['client_accepted_user_agent'] : null,
            clientAcceptedSignature: $row['client_accepted_signature'] !== null ? (string) $row['client_accepted_signature'] : null,
            internalAcceptedByUserId: $row['internal_accepted_by_user_id'] !== null ? (int) $row['internal_accepted_by_user_id'] : null,
            internalAcceptedAt: $row['internal_accepted_at'] !== null ? (string) $row['internal_accepted_at'] : null,
            terminatedAt: $row['terminated_at'] !== null ? (string) $row['terminated_at'] : null,
            terminationReason: $row['termination_reason'] !== null ? (string) $row['termination_reason'] : null,
            earlyTerminationFeeMinor: $row['early_termination_fee_minor'] !== null ? (int) $row['early_termination_fee_minor'] : null,
            notes: $row['notes'] !== null ? (string) $row['notes'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }

    private function ensureSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS contracts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contract_number VARCHAR(64) NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                organization_id INTEGER,
                service_id INTEGER,
                order_id INTEGER,
                title VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "draft",
                commitment_period_months INTEGER NOT NULL DEFAULT 12,
                start_date VARCHAR(32) NOT NULL,
                end_date VARCHAR(32),
                terms_version VARCHAR(32) NOT NULL,
                terms_text TEXT NOT NULL,
                sla_commitment_uptime_percent REAL NOT NULL DEFAULT 99.9,
                client_accepted_at VARCHAR(64),
                client_accepted_ip VARCHAR(45),
                client_accepted_user_agent TEXT,
                client_accepted_signature VARCHAR(255),
                internal_accepted_by_user_id INTEGER,
                internal_accepted_at VARCHAR(64),
                terminated_at VARCHAR(64),
                termination_reason TEXT,
                early_termination_fee_minor INTEGER,
                notes TEXT,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            );'
        );
    }
}
