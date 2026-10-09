<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Handlers;

final class PrivacyHandlerRegistry
{
    /** @var array<string, DomainPrivacyHandlerInterface> */
    private array $handlers = [];

    /**
     * @param array<DomainPrivacyHandlerInterface> $handlers
     */
    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(DomainPrivacyHandlerInterface $handler): void
    {
        $this->handlers[$handler->getDomainName()] = $handler;
    }

    public function has(string $domainName): bool
    {
        return isset($this->handlers[$domainName]);
    }

    public function get(string $domainName): ?DomainPrivacyHandlerInterface
    {
        return $this->handlers[$domainName] ?? null;
    }

    /**
     * @return array<string, DomainPrivacyHandlerInterface>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * Executes coordinated erasure across all registered domain handlers.
     *
     * @return array<string, DomainPrivacyActionResult>
     */
    public function executeErasureAcrossAll(int $userId, string $mode = 'ANONYMIZE', array $context = []): array
    {
        $results = [];
        foreach ($this->handlers as $domain => $handler) {
            $results[$domain] = $handler->handleErasure($userId, $mode, $context);
        }

        return $results;
    }

    /**
     * Collects export data across all registered domain handlers.
     *
     * @return array<string, mixed>
     */
    public function collectExportAcrossAll(int $userId): array
    {
        $export = [];
        foreach ($this->handlers as $domain => $handler) {
            $export[$domain] = $handler->handleExport($userId);
        }

        return $export;
    }

    /**
     * Applies processing restriction across all registered domain handlers.
     */
    public function applyRestrictionAcrossAll(int $userId, bool $restricted): void
    {
        foreach ($this->handlers as $handler) {
            $handler->handleRestriction($userId, $restricted);
        }
    }
}
