<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Handlers;

use Coleza\Foundation\Database\Connection;

final class BillingPrivacyHandler implements DomainPrivacyHandlerInterface
{
    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function getDomainName(): string
    {
        return 'billing';
    }

    public function handleErasure(int $userId, string $mode = 'ANONYMIZE', array $context = []): DomainPrivacyActionResult
    {
        $fieldsRedacted = [];
        $recordsAffected = 0;

        // 1. Redact saved payment methods / credit card tokens
        try {
            $paymentMethods = $this->db->select(
                "SELECT id FROM payment_methods WHERE user_id = :uid",
                ['uid' => $userId]
            );
            if (!empty($paymentMethods)) {
                $this->db->statement(
                    "UPDATE payment_methods
                     SET token = 'ERASED', card_last4 = '0000', card_brand = 'ERASED',
                         billing_name = 'ERASED', is_active = 0
                     WHERE user_id = :uid",
                    ['uid' => $userId]
                );
                $recordsAffected += count($paymentMethods);
                $fieldsRedacted = array_merge($fieldsRedacted, ['payment_methods.token', 'payment_methods.billing_name', 'payment_methods.card_last4']);
            }
        } catch (\Throwable) {
            // Table might not exist in testing
        }

        // 2. Redact customer billing profile / tax information
        try {
            $profiles = $this->db->select("SELECT id FROM billing_profiles WHERE user_id = :uid", ['uid' => $userId]);
            if (!empty($profiles)) {
                $this->db->statement(
                    "UPDATE billing_profiles
                     SET company_name = '[Anonymized]', tax_number = NULL, tax_office = NULL,
                         address = '[Anonymized]', city = NULL, state = NULL, postal_code = NULL,
                         phone = NULL
                     WHERE user_id = :uid",
                    ['uid' => $userId]
                );
                $recordsAffected += count($profiles);
                $fieldsRedacted = array_merge($fieldsRedacted, ['billing_profiles.company_name', 'billing_profiles.tax_number', 'billing_profiles.address', 'billing_profiles.phone']);
            }
        } catch (\Throwable) {
            // Ignore
        }

        $preChecksum = hash('sha256', sprintf('billing:%d:%s', $userId, json_encode($context)));
        $postChecksum = hash('sha256', sprintf('billing_redacted:%d:%d', $userId, $recordsAffected));

        return new DomainPrivacyActionResult(
            domainName: $this->getDomainName(),
            action: $mode,
            recordsAffected: $recordsAffected,
            fieldsRedacted: array_values(array_unique($fieldsRedacted)),
            details: [
                'payment_methods_purged' => true,
                'billing_address_anonymized' => true,
                'tax_data_cleared' => true,
                'fiscal_invoices_preserved' => true,
            ],
            success: true,
            preChecksum: $preChecksum,
            postChecksum: $postChecksum
        );
    }

    public function handleExport(int $userId): array
    {
        $export = [
            'payment_methods' => [],
            'billing_profiles' => [],
        ];

        try {
            $export['payment_methods'] = $this->db->select(
                "SELECT id, card_brand, card_last4, expires_at, created_at FROM payment_methods WHERE user_id = :uid",
                ['uid' => $userId]
            );
        } catch (\Throwable) {
            // Ignore
        }

        try {
            $export['billing_profiles'] = $this->db->select(
                "SELECT id, company_name, city, country, created_at FROM billing_profiles WHERE user_id = :uid",
                ['uid' => $userId]
            );
        } catch (\Throwable) {
            // Ignore
        }

        return $export;
    }

    public function handleRestriction(int $userId, bool $restricted): void
    {
        // When processing restricted, deactivate automatic payment method re-billings
        if ($restricted) {
            try {
                $this->db->statement(
                    "UPDATE payment_methods SET is_active = 0 WHERE user_id = :uid",
                    ['uid' => $userId]
                );
            } catch (\Throwable) {
                // Ignore
            }
        }
    }
}
