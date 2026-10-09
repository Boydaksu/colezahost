<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

/**
 * Result report of fresh-install migration mode execution.
 */
final class FreshInstallMigrationResult
{
    /**
     * @param string $sourceType Source platform identifier
     * @param bool $isSuccess Whether migration completed without fatal error
     * @param int $totalMigrated Total entities imported into fresh system
     * @param int $totalConflicts Number of detected conflicts handled
     * @param int $totalQuarantined Number of entities sent to quarantine
     * @param bool $migrationHoldEngaged Whether migration hold remains active
     * @param bool $notificationsSuppressed Whether notifications were suppressed
     * @param array<string, mixed> $summary Details per domain (clients, products, invoices, tickets, etc.)
     * @param ?string $errorMessage Error message if failed
     */
    public function __construct(
        private string $sourceType,
        private bool $isSuccess,
        private int $totalMigrated,
        private int $totalConflicts,
        private int $totalQuarantined,
        private bool $migrationHoldEngaged,
        private bool $notificationsSuppressed,
        private array $summary = [],
        private ?string $errorMessage = null
    ) {
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function getTotalMigrated(): int
    {
        return $this->totalMigrated;
    }

    public function getTotalConflicts(): int
    {
        return $this->totalConflicts;
    }

    public function getTotalQuarantined(): int
    {
        return $this->totalQuarantined;
    }

    public function isMigrationHoldEngaged(): bool
    {
        return $this->migrationHoldEngaged;
    }

    public function areNotificationsSuppressed(): bool
    {
        return $this->notificationsSuppressed;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'success' => $this->isSuccess,
            'total_migrated' => $this->totalMigrated,
            'total_conflicts' => $this->totalConflicts,
            'total_quarantined' => $this->totalQuarantined,
            'migration_hold_engaged' => $this->migrationHoldEngaged,
            'notifications_suppressed' => $this->notificationsSuppressed,
            'summary' => $this->summary,
            'error_message' => $this->errorMessage,
        ];
    }
}
