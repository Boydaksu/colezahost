<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

/**
 * Result of executing a system restore operation.
 */
final class RestoreResult
{
    /**
     * @param string $backupId
     * @param bool $isSuccess
     * @param int $databaseStatementsExecuted Total SQL statements applied to database
     * @param int $filesRestored Total application/asset files restored
     * @param array<int, string> $tablesRestored Names of tables successfully recreated/populated
     * @param array<string, mixed> $metadata Restored system metadata (version, brand, settings)
     * @param ?string $errorMessage Error description if restoration failed
     */
    public function __construct(
        private string $backupId,
        private bool $isSuccess,
        private int $databaseStatementsExecuted,
        private int $filesRestored,
        private array $tablesRestored = [],
        private array $metadata = [],
        private ?string $errorMessage = null
    ) {
    }

    public function getBackupId(): string
    {
        return $this->backupId;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function getDatabaseStatementsExecuted(): int
    {
        return $this->databaseStatementsExecuted;
    }

    public function getFilesRestored(): int
    {
        return $this->filesRestored;
    }

    /**
     * @return array<int, string>
     */
    public function getTablesRestored(): array
    {
        return $this->tablesRestored;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
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
            'backup_id' => $this->backupId,
            'success' => $this->isSuccess,
            'statements_executed' => $this->databaseStatementsExecuted,
            'files_restored' => $this->filesRestored,
            'tables_restored' => $this->tablesRestored,
            'metadata' => $this->metadata,
            'error_message' => $this->errorMessage,
        ];
    }
}
