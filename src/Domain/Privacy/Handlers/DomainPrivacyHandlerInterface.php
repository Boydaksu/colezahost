<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Handlers;

/**
 * Pluggable contract for domain and module privacy lifecycle handlers.
 * Every subsystem (Billing, Hosting, Domains, Support, Notifications)
 * implements this contract to ensure coordinated PII redaction and audit trails.
 */
interface DomainPrivacyHandlerInterface
{
    /**
     * Unique domain or module machine name (e.g. 'billing', 'hosting', 'support').
     */
    public function getDomainName(): string;

    /**
     * Executes privacy erasure/redaction for the specified user within this domain.
     *
     * @param int $userId Target user ID
     * @param string $mode 'ANONYMIZE', 'DELETE', or 'PURGE'
     * @param array<string, mixed> $context Optional execution context
     */
    public function handleErasure(int $userId, string $mode = 'ANONYMIZE', array $context = []): DomainPrivacyActionResult;

    /**
     * Extracts domain-specific personal data for subject data portability export.
     *
     * @return array<string, mixed>
     */
    public function handleExport(int $userId): array;

    /**
     * Applies or lifts processing restrictions (e.g., stops marketing, halts automated profiling).
     */
    public function handleRestriction(int $userId, bool $restricted): void;
}
