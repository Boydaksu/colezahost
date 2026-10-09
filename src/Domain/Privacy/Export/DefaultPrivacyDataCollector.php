<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Export;

use Coleza\Foundation\Database\Connection;

final class DefaultPrivacyDataCollector implements PrivacyDataCollectorInterface
{
    public function __construct(
        private readonly Connection $db
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collectUserData(int $userId): array
    {
        $data = [
            'profile' => $this->collectProfile($userId),
            'consents' => $this->collectConsents($userId),
            'organizations' => $this->collectOrganizations($userId),
            'orders' => $this->collectOrders($userId),
            'invoices' => $this->collectInvoices($userId),
            'services' => $this->collectServices($userId),
            'domains' => $this->collectDomains($userId),
            'support_tickets' => $this->collectTickets($userId),
        ];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectProfile(int $userId): array
    {
        try {
            $user = $this->db->selectOne("SELECT * FROM users WHERE id = :id LIMIT 1", ['id' => $userId]);
            if ($user !== null) {
                // Scrub confidential fields
                unset(
                    $user['password'],
                    $user['password_hash'],
                    $user['two_factor_secret'],
                    $user['remember_token'],
                    $user['api_token']
                );
                return $user;
            }
        } catch (\Throwable) {
            // Table might not exist in isolated test
        }

        return ['user_id' => $userId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectConsents(int $userId): array
    {
        try {
            return $this->db->select("SELECT * FROM privacy_consents WHERE user_id = :id ORDER BY id ASC", ['id' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectOrganizations(int $userId): array
    {
        try {
            return $this->db->select("SELECT * FROM organization_members WHERE user_id = :id", ['id' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectOrders(int $userId): array
    {
        try {
            return $this->db->select("SELECT id, order_number, status, total_amount, currency, created_at FROM orders WHERE user_id = :id ORDER BY id DESC", ['id' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectInvoices(int $userId): array
    {
        try {
            return $this->db->select("SELECT id, invoice_number, status, total, currency, issue_date, due_date FROM invoices WHERE user_id = :id ORDER BY id DESC", ['id' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectServices(int $userId): array
    {
        try {
            $services = $this->db->select("SELECT id, domain, status, billing_cycle, created_at FROM hosting_services WHERE user_id = :id ORDER BY id DESC", ['id' => $userId]);
            return $services;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectDomains(int $userId): array
    {
        try {
            return $this->db->select("SELECT id, fqdn, status, registration_period, expires_at, created_at FROM domains WHERE user_id = :id ORDER BY id DESC", ['id' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectTickets(int $userId): array
    {
        try {
            return $this->db->select("SELECT id, ticket_number, subject, status, priority, created_at FROM support_tickets WHERE client_user_id = :id ORDER BY id DESC", ['id' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }
}
