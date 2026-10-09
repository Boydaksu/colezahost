<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Requests;

use Coleza\Domain\Privacy\Export\DefaultPrivacyDataCollector;
use Coleza\Domain\Privacy\Export\PrivacyDataCollectorInterface;
use Coleza\Domain\Privacy\Export\PrivacyExportPackage;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class PrivacyRequestService
{
    private string $table = 'privacy_requests';

    public function __construct(
        private readonly Connection $db,
        private readonly ?PrivacyDataCollectorInterface $defaultCollector = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                organization_id INT NULL,
                request_type VARCHAR(30) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'pending_verification\',
                step_up_token VARCHAR(64) NOT NULL UNIQUE,
                otp_code_hash VARCHAR(64) NOT NULL,
                otp_attempts INT NOT NULL DEFAULT 0,
                expires_at TIMESTAMP NOT NULL,
                verified_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                export_checksum VARCHAR(64) NULL,
                export_data_json TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );
        $this->db->statement($sql);

        if ($driver !== 'sqlite') {
            try {
                $this->db->statement("CREATE INDEX idx_privacy_user ON {$this->table} (user_id, status)");
                $this->db->statement("CREATE INDEX idx_privacy_token ON {$this->table} (step_up_token)");
            } catch (\Throwable) {
                // Ignore if indices exist
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{request: PrivacyRequest, otp_code: string}
     */
    public function createRequest(
        int $userId,
        PrivacyRequestType $type,
        ?int $organizationId = null,
        int $ttlMinutes = 30,
        array $metadata = []
    ): array {
        $this->ensureTables();

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d minutes', max(5, $ttlMinutes)));

        // Generate 6-digit OTP code and secure token
        $otpCode = sprintf('%06d', random_int(100000, 999999));
        $stepUpToken = bin2hex(random_bytes(32));
        $otpCodeHash = hash('sha256', $otpCode);

        $data = [
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'request_type' => $type->value,
            'status' => PrivacyRequestStatus::PENDING_VERIFICATION->value,
            'step_up_token' => $stepUpToken,
            'otp_code_hash' => $otpCodeHash,
            'otp_attempts' => 0,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'verified_at' => null,
            'completed_at' => null,
            'export_checksum' => null,
            'export_data_json' => null,
            'metadata_json' => !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        $id = (int) $this->db->insert($this->table, $data);

        $request = new PrivacyRequest(
            id: $id,
            userId: $userId,
            organizationId: $organizationId,
            requestType: $type,
            status: PrivacyRequestStatus::PENDING_VERIFICATION,
            stepUpToken: $stepUpToken,
            otpCodeHash: $otpCodeHash,
            otpAttempts: 0,
            expiresAt: $expiresAt,
            verifiedAt: null,
            completedAt: null,
            exportChecksum: null,
            metadata: $metadata,
            createdAt: $now
        );

        return [
            'request' => $request,
            'otp_code' => $otpCode,
        ];
    }

    public function verifyStepUp(string $stepUpToken, string $otpCode): PrivacyRequest
    {
        $request = $this->getRequestByToken($stepUpToken);
        if ($request === null) {
            throw new ValidationException(['step_up_token' => ['Invalid step-up verification token.']], 'Invalid step-up verification token.');
        }

        if ($request->isExpired()) {
            throw new ValidationException(['step_up' => ['Verification challenge has expired. Please initiate a new privacy request.']], 'Verification challenge has expired. Please initiate a new privacy request.');
        }

        if ($request->getStatus() === PrivacyRequestStatus::REJECTED) {
            throw new ValidationException(['step_up' => ['This privacy request was rejected due to excessive failed verification attempts.']], 'This privacy request was rejected due to excessive failed verification attempts.');
        }

        if ($request->isVerified() || $request->isCompleted()) {
            return $request;
        }

        $inputHash = hash('sha256', trim($otpCode));
        if (!hash_equals($request->getOtpCodeHash(), $inputHash)) {
            $newAttempts = $request->getOtpAttempts() + 1;
            $newStatus = $newAttempts >= 3 ? PrivacyRequestStatus::REJECTED->value : $request->getStatus()->value;

            $this->db->statement(
                "UPDATE {$this->table} SET otp_attempts = :attempts, status = :status WHERE id = :id",
                [
                    'attempts' => $newAttempts,
                    'status' => $newStatus,
                    'id' => $request->getId(),
                ]
            );

            if ($newAttempts >= 3) {
                throw new ValidationException(['otp_code' => ['Maximum step-up verification attempts exceeded. Request locked.']], 'Maximum step-up verification attempts exceeded. Request locked.');
            }

            throw new ValidationException(['otp_code' => [sprintf('Incorrect verification code. Attempts remaining: %d.', 3 - $newAttempts)]], sprintf('Incorrect verification code. Attempts remaining: %d.', 3 - $newAttempts));
        }

        // Successfully verified
        $now = new DateTimeImmutable();
        $this->db->statement(
            "UPDATE {$this->table} SET status = :status, verified_at = :verified_at WHERE id = :id",
            [
                'status' => PrivacyRequestStatus::VERIFIED->value,
                'verified_at' => $now->format('Y-m-d H:i:s'),
                'id' => $request->getId(),
            ]
        );

        return $this->getRequestOrFail((int) $request->getId());
    }

    public function generateExport(int $requestId, ?PrivacyDataCollectorInterface $collector = null): PrivacyExportPackage
    {
        $request = $this->getRequestOrFail($requestId);

        if (!$request->isVerified()) {
            throw new ValidationException(['step_up' => ['Step-up identity verification required before data export can be compiled.']], 'Step-up identity verification required before data export can be compiled.');
        }

        if ($request->isExpired()) {
            throw new ValidationException(['step_up' => ['Privacy request has expired.']], 'Privacy request has expired.');
        }

        $activeCollector = $collector ?? $this->defaultCollector ?? new DefaultPrivacyDataCollector($this->db);
        $userData = $activeCollector->collectUserData($request->getUserId());

        $now = new DateTimeImmutable();
        $package = PrivacyExportPackage::create($request->getUserId(), $userData, $now);

        $this->db->statement(
            "UPDATE {$this->table}
             SET status = :status, completed_at = :completed_at, export_checksum = :checksum, export_data_json = :data
             WHERE id = :id",
            [
                'status' => PrivacyRequestStatus::COMPLETED->value,
                'completed_at' => $now->format('Y-m-d H:i:s'),
                'checksum' => $package->getChecksum(),
                'data' => $package->toJson(),
                'id' => $requestId,
            ]
        );

        return $package;
    }

    public function getExport(int $requestId): ?PrivacyExportPackage
    {
        $this->ensureTables();

        $row = $this->db->selectOne("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1", ['id' => $requestId]);
        if ($row === null || empty($row['export_data_json'])) {
            return null;
        }

        $decoded = json_decode((string) $row['export_data_json'], true);
        if (!is_array($decoded) || !isset($decoded['data'])) {
            return null;
        }

        return new PrivacyExportPackage(
            userId: (int) $row['user_id'],
            generatedAt: new DateTimeImmutable((string) $row['completed_at']),
            data: $decoded['data'],
            checksum: (string) $row['export_checksum']
        );
    }

    public function getRequest(int $id): ?PrivacyRequest
    {
        $this->ensureTables();

        $row = $this->db->selectOne("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1", ['id' => $id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function getRequestOrFail(int $id): PrivacyRequest
    {
        $request = $this->getRequest($id);
        if ($request === null) {
            throw new ValidationException(['request' => [sprintf('Privacy request #%d not found.', $id)]], sprintf('Privacy request #%d not found.', $id));
        }
        return $request;
    }

    public function getRequestByToken(string $token): ?PrivacyRequest
    {
        $this->ensureTables();

        $row = $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE step_up_token = :token LIMIT 1",
            ['token' => trim($token)]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @return list<PrivacyRequest>
     */
    public function getUserRequests(int $userId): array
    {
        $this->ensureTables();

        $rows = $this->db->select(
            "SELECT * FROM {$this->table} WHERE user_id = :user_id ORDER BY id DESC",
            ['user_id' => $userId]
        );

        return array_map(fn (array $r) => $this->hydrate($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PrivacyRequest
    {
        $meta = !empty($row['metadata_json'])
            ? json_decode((string) $row['metadata_json'], true) ?? []
            : [];

        return new PrivacyRequest(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            organizationId: isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            requestType: PrivacyRequestType::from((string) $row['request_type']),
            status: PrivacyRequestStatus::from((string) $row['status']),
            stepUpToken: (string) $row['step_up_token'],
            otpCodeHash: (string) $row['otp_code_hash'],
            otpAttempts: (int) $row['otp_attempts'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
            verifiedAt: !empty($row['verified_at']) ? new DateTimeImmutable((string) $row['verified_at']) : null,
            completedAt: !empty($row['completed_at']) ? new DateTimeImmutable((string) $row['completed_at']) : null,
            exportChecksum: isset($row['export_checksum']) ? (string) $row['export_checksum'] : null,
            metadata: $meta,
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null
        );
    }
}
