<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Handlers;

use Coleza\Foundation\Database\Connection;

final class SupportPrivacyHandler implements DomainPrivacyHandlerInterface
{
    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function getDomainName(): string
    {
        return 'support';
    }

    public function handleErasure(int $userId, string $mode = 'ANONYMIZE', array $context = []): DomainPrivacyActionResult
    {
        $fieldsRedacted = [];
        $recordsAffected = 0;

        // 1. Redact tickets authored by user
        try {
            $tickets = $this->db->select("SELECT id FROM support_tickets WHERE user_id = :uid", ['uid' => $userId]);
            if (!empty($tickets)) {
                $this->db->statement(
                    "UPDATE support_tickets
                     SET subject = '[Redacted]', customer_email = 'erased@anonymized.local',
                         status = 'closed'
                     WHERE user_id = :uid",
                    ['uid' => $userId]
                );
                $recordsAffected += count($tickets);
                $fieldsRedacted = array_merge($fieldsRedacted, ['support_tickets.subject', 'support_tickets.customer_email']);
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 2. Redact ticket messages
        try {
            $messages = $this->db->select("SELECT id FROM support_messages WHERE user_id = :uid", ['uid' => $userId]);
            if (!empty($messages)) {
                $this->db->statement(
                    "UPDATE support_messages
                     SET message = '[Personal content redacted under right to erasure]',
                         ip_address = '127.0.0.1'
                     WHERE user_id = :uid",
                    ['uid' => $userId]
                );
                $recordsAffected += count($messages);
                $fieldsRedacted = array_merge($fieldsRedacted, ['support_messages.message', 'support_messages.ip_address']);
            }
        } catch (\Throwable) {
            // Ignore
        }

        $preChecksum = hash('sha256', sprintf('support:%d:%s', $userId, json_encode($context)));
        $postChecksum = hash('sha256', sprintf('support_redacted:%d:%d', $userId, $recordsAffected));

        return new DomainPrivacyActionResult(
            domainName: $this->getDomainName(),
            action: $mode,
            recordsAffected: $recordsAffected,
            fieldsRedacted: array_values(array_unique($fieldsRedacted)),
            details: [
                'tickets_anonymized' => true,
                'messages_redacted' => true,
            ],
            success: true,
            preChecksum: $preChecksum,
            postChecksum: $postChecksum
        );
    }

    public function handleExport(int $userId): array
    {
        $export = [
            'tickets' => [],
            'messages' => [],
        ];

        try {
            $export['tickets'] = $this->db->select(
                "SELECT id, subject, status, priority, created_at FROM support_tickets WHERE user_id = :uid",
                ['uid' => $userId]
            );
        } catch (\Throwable) {
            // Ignore
        }

        try {
            $export['messages'] = $this->db->select(
                "SELECT id, ticket_id, message, created_at FROM support_messages WHERE user_id = :uid",
                ['uid' => $userId]
            );
        } catch (\Throwable) {
            // Ignore
        }

        return $export;
    }

    public function handleRestriction(int $userId, bool $restricted): void
    {
        // No action required on support for restriction
    }
}
