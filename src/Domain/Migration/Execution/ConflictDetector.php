<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

use Coleza\Domain\Migration\Adoption\AdoptedIdentityRepository;
use Coleza\Domain\Migration\Adoption\AdoptedIdentityType;
use Coleza\Foundation\Database\Connection;

/**
 * Pre-flight and real-time conflict detector for migration targets.
 * Detects entity collisions (emails, domains, server usernames, invoice numbers, slugs)
 * before committing mutations to prevent foreign key or unique constraint violations.
 */
final class ConflictDetector
{
    public function __construct(
        private Connection $targetDb,
        private ?AdoptedIdentityRepository $adoptedIdentityRepo = null
    ) {
    }

    /**
     * Checks if a user email already exists in the target Coleza users table.
     *
     * @return int|null Existing user ID if conflict detected, null otherwise
     */
    public function detectUserEmailConflict(string $email): ?int
    {
        $cleanEmail = strtolower(trim($email));
        if ($cleanEmail === '') {
            return null;
        }

        $row = $this->targetDb->selectOne(
            'SELECT id FROM users WHERE email = ?',
            [$cleanEmail]
        );

        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Checks if a domain name is already registered or adopted.
     *
     * @return int|null Existing domain ID if conflict detected, null otherwise
     */
    public function detectDomainConflict(string $domainName): ?int
    {
        $cleanDomain = strtolower(trim($domainName));
        if ($cleanDomain === '') {
            return null;
        }

        $row = $this->targetDb->selectOne(
            'SELECT id FROM domains WHERE domain_name = ?',
            [$cleanDomain]
        );

        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Checks if a hosting server account username on a specific server is already adopted.
     *
     * @return int|null Existing service ID if conflict detected, null otherwise
     */
    public function detectServiceConflict(int $serverId, string $username): ?int
    {
        $cleanUsername = trim($username);
        if ($cleanUsername === '') {
            return null;
        }

        // 1. Check adopted identity repository
        if ($this->adoptedIdentityRepo !== null) {
            $identity = $this->adoptedIdentityRepo->findByExternalReference(
                AdoptedIdentityType::HOSTING_SERVICE,
                (string) $serverId,
                $cleanUsername
            );
            if ($identity !== null) {
                return $identity->getTargetEntityId();
            }
        }

        // 2. Check services table
        $row = $this->targetDb->selectOne(
            'SELECT id FROM services WHERE server_id = ? AND username = ?',
            [$serverId, $cleanUsername]
        );

        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Checks if an invoice number already exists in the target invoices table.
     *
     * @return int|null Existing invoice ID if conflict detected, null otherwise
     */
    public function detectInvoiceNumberConflict(string $invoiceNumber): ?int
    {
        $cleanNumber = trim($invoiceNumber);
        if ($cleanNumber === '') {
            return null;
        }

        $row = $this->targetDb->selectOne(
            'SELECT id FROM invoices WHERE invoice_number = ?',
            [$cleanNumber]
        );

        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Checks if a product slug already exists in the catalog.
     *
     * @return int|null Existing product ID if conflict detected, null otherwise
     */
    public function detectProductSlugConflict(string $slug): ?int
    {
        $cleanSlug = strtolower(trim($slug));
        if ($cleanSlug === '') {
            return null;
        }

        $row = $this->targetDb->selectOne(
            'SELECT id FROM products WHERE slug = ?',
            [$cleanSlug]
        );

        return $row !== null ? (int) $row['id'] : null;
    }
}
