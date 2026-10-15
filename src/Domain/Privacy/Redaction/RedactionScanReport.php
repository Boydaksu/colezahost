<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Redaction;

use JsonSerializable;

final class RedactionScanReport implements JsonSerializable
{
    public function __construct(
        private readonly int $userId,
        private readonly int $tablesScanned,
        private readonly int $violationsDetected,
        private readonly array $violationDetails,
        private readonly bool $isCompliant,
        private readonly string $scannedAt,
        private readonly string $scanDigest,
        private readonly array $unverifiedTables = []
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTablesScanned(): int
    {
        return $this->tablesScanned;
    }

    public function getViolationsDetected(): int
    {
        return $this->violationsDetected;
    }

    public function getViolationDetails(): array
    {
        return $this->violationDetails;
    }

    public function isComplete(): bool { return $this->tablesScanned === 5 && $this->unverifiedTables === []; }
    public function getUnverifiedTables(): array { return $this->unverifiedTables; }
    public function isCompliant(): bool
    {
        return $this->isCompliant && $this->isComplete() && $this->violationsDetected === 0 && $this->violationDetails === [];
    }

    public function getScannedAt(): string
    {
        return $this->scannedAt;
    }

    public function getScanDigest(): string
    {
        return $this->scanDigest;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'tables_scanned' => $this->tablesScanned,
            'violations_detected' => $this->violationsDetected,
            'violation_details' => $this->violationDetails,
            'is_compliant' => $this->isCompliant(),
            'is_complete' => $this->isComplete(),
            'scan_scope' => 'users/payment_methods/billing_profiles/hosting_services/privacy_consents',
            'unverified_tables' => $this->unverifiedTables,
            'scanned_at' => $this->scannedAt,
            'scan_digest' => $this->scanDigest,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
