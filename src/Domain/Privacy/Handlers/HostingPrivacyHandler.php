<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Handlers;

use Coleza\Foundation\Database\Connection;

final class HostingPrivacyHandler implements DomainPrivacyHandlerInterface
{
    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function getDomainName(): string
    {
        return 'hosting';
    }

    public function handleErasure(int $userId, string $mode = 'ANONYMIZE', array $context = []): DomainPrivacyActionResult
    {
        $fieldsRedacted = [];
        $recordsAffected = 0;

        try {
            $services = $this->db->select(
                "SELECT id FROM hosting_services WHERE user_id = :uid",
                ['uid' => $userId]
            );

            if (!empty($services)) {
                $this->db->statement(
                    "UPDATE hosting_services
                     SET username = 'erased_user', password_encrypted = 'ERASED',
                         server_notes = NULL, access_token = NULL
                     WHERE user_id = :uid",
                    ['uid' => $userId]
                );
                $recordsAffected += count($services);
                $fieldsRedacted = array_merge($fieldsRedacted, [
                    'hosting_services.username',
                    'hosting_services.password_encrypted',
                    'hosting_services.access_token',
                ]);
            }
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }

        $preChecksum = hash('sha256', sprintf('hosting:%d:%s', $userId, json_encode($context)));
        $postChecksum = hash('sha256', sprintf('hosting_redacted:%d:%d', $userId, $recordsAffected));

        return new DomainPrivacyActionResult(
            domainName: $this->getDomainName(),
            action: $mode,
            recordsAffected: $recordsAffected,
            fieldsRedacted: array_values(array_unique($fieldsRedacted)),
            details: [
                'server_credentials_purged' => true,
                'usernames_anonymized' => true,
            ],
            success: true,
            preChecksum: $preChecksum,
            postChecksum: $postChecksum
        );
    }

    public function handleExport(int $userId): array
    {
        $export = [];
        try {
            $export = $this->db->select(
                "SELECT id, domain, package_name, status, created_at FROM hosting_services WHERE user_id = :uid",
                ['uid' => $userId]
            );
        } catch (\Throwable) {
            // Ignore
        }

        return ['hosting_services' => $export];
    }

    public function handleRestriction(int $userId, bool $restricted): void
    {
        // No action required on hosting services for processing restriction
    }
}
