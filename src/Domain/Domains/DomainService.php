<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class DomainService
{
    private string $domainsTable = 'domains';
    private string $contactsTable = 'domain_contacts';
    private string $timelineTable = 'domain_timeline_events';

    public function __construct(
        private readonly Connection $db,
        private readonly ?DomainCatalogService $catalogService = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlDomains = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                organization_id INT NULL,
                domain VARCHAR(255) NOT NULL UNIQUE,
                sld VARCHAR(128) NOT NULL,
                tld VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                registration_period_years INT DEFAULT 1,
                registration_date VARCHAR(20) NULL,
                expiry_date VARCHAR(20) NULL,
                next_due_date VARCHAR(20) NULL,
                auto_renew TINYINT(1) DEFAULT 1,
                whois_privacy TINYINT(1) DEFAULT 0,
                dns_management TINYINT(1) DEFAULT 1,
                email_forwarding TINYINT(1) DEFAULT 1,
                is_locked TINYINT(1) DEFAULT 1,
                epp_code VARCHAR(255) NULL,
                registrar_id VARCHAR(64) NULL,
                nameservers_json TEXT NULL,
                subscription_id INT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->domainsTable,
            $autoInc
        );
        $this->db->statement($sqlDomains);

        $sqlContacts = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                domain_id INT NOT NULL,
                contact_type VARCHAR(20) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                company_name VARCHAR(150) NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(50) NOT NULL,
                fax VARCHAR(50) NULL,
                address_line_1 VARCHAR(255) NOT NULL,
                address_line_2 VARCHAR(255) NULL,
                city VARCHAR(100) NOT NULL,
                state VARCHAR(100) NULL,
                postal_code VARCHAR(30) NOT NULL,
                country_code VARCHAR(2) NOT NULL,
                additional_fields_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (domain_id, contact_type)
            )',
            $this->contactsTable,
            $autoInc
        );
        $this->db->statement($sqlContacts);

        $sqlTimeline = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                domain_id INT NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                actor_id INT NULL,
                actor_type VARCHAR(30) NOT NULL DEFAULT \'system\',
                description TEXT NOT NULL,
                payload_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->timelineTable,
            $autoInc
        );
        $this->db->statement($sqlTimeline);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDomain(array $data): Domain
    {
        $domainName = strtolower(trim((string) ($data['domain'] ?? '')));
        if ($domainName === '') {
            throw new ValidationException(['domain' => 'Domain name is required.'], 'Invalid domain');
        }

        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id is required.'], 'Invalid user');
        }

        $years = max(1, (int) ($data['registration_period_years'] ?? $data['period_years'] ?? 1));

        // Validate domain syntax against catalog if available
        if ($this->catalogService !== null) {
            $validation = $this->catalogService->validateDomainName($domainName, $years, (array) ($data['additional_data'] ?? []));
            if (!$validation->isValid()) {
                throw new ValidationException(['domain' => $validation->getFirstError() ?? 'Invalid domain'], 'Domain validation failed');
            }
            $sld = $validation->getSld();
            $tld = $validation->getTld();
        } else {
            $dotPos = strpos($domainName, '.');
            if ($dotPos === false) {
                throw new ValidationException(['domain' => 'Domain must contain a valid extension.'], 'Invalid domain');
            }
            $sld = substr($domainName, 0, $dotPos);
            $tld = substr($domainName, $dotPos);
        }

        if ($this->findDomainByName($domainName) !== null) {
            throw new ValidationException(['domain' => "Domain '{$domainName}' is already recorded in the system."], 'Duplicate domain');
        }

        $organizationId = isset($data['organization_id']) ? (int) $data['organization_id'] : null;
        $status = (string) ($data['status'] ?? DomainStateMachine::STATUS_PENDING_REGISTRATION);
        $autoRenew = (bool) ($data['auto_renew'] ?? true);
        $whoisPrivacy = (bool) ($data['whois_privacy'] ?? false);
        $dnsManagement = (bool) ($data['dns_management'] ?? true);
        $emailForwarding = (bool) ($data['email_forwarding'] ?? true);
        $isLocked = (bool) ($data['is_locked'] ?? true);
        $registrarId = isset($data['registrar_id']) ? (string) $data['registrar_id'] : null;
        $nameservers = (array) ($data['nameservers'] ?? ['ns1.coleza.com', 'ns2.coleza.com']);
        $subscriptionId = isset($data['subscription_id']) ? (int) $data['subscription_id'] : null;
        $metadata = (array) ($data['metadata'] ?? []);
        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (
                user_id, organization_id, domain, sld, tld, status,
                registration_period_years, auto_renew, whois_privacy,
                dns_management, email_forwarding, is_locked, registrar_id,
                nameservers_json, subscription_id, metadata_json, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->domainsTable
        );

        $this->db->statement($sql, [
            $userId,
            $organizationId,
            $domainName,
            $sld,
            $tld,
            $status,
            $years,
            $autoRenew ? 1 : 0,
            $whoisPrivacy ? 1 : 0,
            $dnsManagement ? 1 : 0,
            $emailForwarding ? 1 : 0,
            $isLocked ? 1 : 0,
            $registrarId,
            json_encode($nameservers),
            $subscriptionId,
            json_encode($metadata),
            $now,
            $now,
        ]);

        $domainId = (int) $this->db->getPdo()->lastInsertId();

        // Record creation timeline event
        $this->recordTimelineEvent(
            domainId: $domainId,
            eventType: 'created',
            description: "Domain record created with initial status '{$status}'",
            actorType: (string) ($data['actor_type'] ?? 'user'),
            actorId: isset($data['actor_id']) ? (int) $data['actor_id'] : $userId
        );

        // Populate initial contacts if provided
        if (isset($data['contacts']) && is_array($data['contacts'])) {
            foreach ($data['contacts'] as $type => $contactData) {
                if (is_array($contactData)) {
                    $this->setContact($domainId, (string) $type, $contactData);
                }
            }
        } elseif (isset($data['contact']) && is_array($data['contact'])) {
            $this->setContact($domainId, DomainContact::TYPE_REGISTRANT, $data['contact']);
        }

        return $this->findDomainById($domainId) ?? throw new ValidationException(['id' => 'Failed to create domain'], 'Domain error');
    }

    public function findDomainById(int $id): ?Domain
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->domainsTable), [$id]);
        return $row ? $this->hydrateDomain($row) : null;
    }

    public function findDomainByName(string $domainName): ?Domain
    {
        $normalized = strtolower(trim($domainName));
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE domain = ?', $this->domainsTable), [$normalized]);
        return $row ? $this->hydrateDomain($row) : null;
    }

    /**
     * @return array<int, Domain>
     */
    public function listDomainsByUserId(int $userId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE user_id = ? ORDER BY domain ASC', $this->domainsTable),
            [$userId]
        );
        return array_map([$this, 'hydrateDomain'], $rows);
    }

    /**
     * @return array<int, Domain>
     */
    public function listDomainsByStatus(string $status): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE status = ? ORDER BY expiry_date ASC', $this->domainsTable),
            [strtolower(trim($status))]
        );
        return array_map([$this, 'hydrateDomain'], $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateDomain(int $id, array $data): Domain
    {
        $existing = $this->findDomainById($id);
        if ($existing === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $now = date('Y-m-d H:i:s');
        $autoRenew = isset($data['auto_renew']) ? ((bool) $data['auto_renew'] ? 1 : 0) : ($existing->isAutoRenew() ? 1 : 0);
        $whoisPrivacy = isset($data['whois_privacy']) ? ((bool) $data['whois_privacy'] ? 1 : 0) : ($existing->isWhoisPrivacy() ? 1 : 0);
        $dns = isset($data['dns_management']) ? ((bool) $data['dns_management'] ? 1 : 0) : ($existing->isDnsManagement() ? 1 : 0);
        $emailFwd = isset($data['email_forwarding']) ? ((bool) $data['email_forwarding'] ? 1 : 0) : ($existing->isEmailForwarding() ? 1 : 0);
        $isLocked = isset($data['is_locked']) ? ((bool) $data['is_locked'] ? 1 : 0) : ($existing->isLocked() ? 1 : 0);
        $eppCode = array_key_exists('epp_code', $data) ? $data['epp_code'] : $existing->getEppCode();
        $registrarId = array_key_exists('registrar_id', $data) ? $data['registrar_id'] : $existing->getRegistrarId();
        $nameservers = isset($data['nameservers']) ? json_encode($data['nameservers']) : json_encode($existing->getNameservers());
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : json_encode($existing->getMetadata());

        $sql = sprintf(
            'UPDATE %s SET
                auto_renew = ?, whois_privacy = ?, dns_management = ?,
                email_forwarding = ?, is_locked = ?, epp_code = ?,
                registrar_id = ?, nameservers_json = ?, metadata_json = ?, updated_at = ?
             WHERE id = ?',
            $this->domainsTable
        );

        $this->db->statement($sql, [
            $autoRenew,
            $whoisPrivacy,
            $dns,
            $emailFwd,
            $isLocked,
            $eppCode,
            $registrarId,
            $nameservers,
            $metadata,
            $now,
            $id,
        ]);

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Domain update failed'], 'Update error');
    }

    /**
     * Activate domain following successful registrar registration or transfer.
     */
    public function activateDomain(
        int $id,
        ?string $registrationDate = null,
        ?string $expiryDate = null,
        string $actorType = 'system',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        DomainStateMachine::assertCanTransition($domain->getStatus(), DomainStateMachine::STATUS_ACTIVE);

        $regDate = $registrationDate ?? date('Y-m-d');
        $expDate = $expiryDate ?? (new DateTimeImmutable($regDate))
            ->modify("+{$domain->getRegistrationPeriodYears()} years")
            ->format('Y-m-d');

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'UPDATE %s SET status = ?, registration_date = ?, expiry_date = ?, next_due_date = ?, updated_at = ? WHERE id = ?',
            $this->domainsTable
        );
        $this->db->statement($sql, [
            DomainStateMachine::STATUS_ACTIVE,
            $regDate,
            $expDate,
            $expDate,
            $now,
            $id,
        ]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'activated',
            description: "Domain activated. Valid from {$regDate} until {$expDate}.",
            payload: ['registration_date' => $regDate, 'expiry_date' => $expDate],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Activation reload failed'], 'Domain error');
    }

    /**
     * Renew an existing domain, advancing expiry and next due date.
     */
    public function renewDomain(
        int $id,
        int $years,
        string $actorType = 'system',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $baseDate = $domain->getExpiryDate() ?? date('Y-m-d');
        // If domain already expired, renewal extends from current expiry date or today depending on policy
        $newExpiry = (new DateTimeImmutable($baseDate))
            ->modify("+{$years} years")
            ->format('Y-m-d');

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'UPDATE %s SET status = ?, expiry_date = ?, next_due_date = ?, updated_at = ? WHERE id = ?',
            $this->domainsTable
        );
        $this->db->statement($sql, [
            DomainStateMachine::STATUS_ACTIVE,
            $newExpiry,
            $newExpiry,
            $now,
            $id,
        ]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'renewed',
            description: "Domain renewed for {$years} years. New expiration date: {$newExpiry}.",
            payload: ['years' => $years, 'previous_expiry' => $baseDate, 'new_expiry' => $newExpiry],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Renewal reload failed'], 'Domain error');
    }

    /**
     * Transition domain status through state machine rules.
     */
    public function transitionStatus(
        int $id,
        string $newStatus,
        ?string $reason = null,
        string $actorType = 'system',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $target = strtolower(trim($newStatus));
        DomainStateMachine::assertCanTransition($domain->getStatus(), $target);

        $now = date('Y-m-d H:i:s');
        $sql = sprintf('UPDATE %s SET status = ?, updated_at = ? WHERE id = ?', $this->domainsTable);
        $this->db->statement($sql, [$target, $now, $id]);

        $desc = "Domain status transitioned from '{$domain->getStatus()}' to '{$target}'" . ($reason ? ": {$reason}" : "");
        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'status_changed',
            description: $desc,
            payload: ['from' => $domain->getStatus(), 'to' => $target, 'reason' => $reason],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Transition reload failed'], 'Domain error');
    }

    public function expireDomain(int $id, string $actorType = 'system', ?int $actorId = null): Domain
    {
        return $this->transitionStatus($id, DomainStateMachine::STATUS_EXPIRED, 'Domain expiration threshold reached', $actorType, $actorId);
    }

    /**
     * @param array<int, string> $nameservers
     */
    public function updateNameservers(
        int $id,
        array $nameservers,
        string $actorType = 'user',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $cleaned = [];
        foreach ($nameservers as $ns) {
            $trimmed = strtolower(trim((string) $ns));
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        if (empty($cleaned)) {
            throw new ValidationException(['nameservers' => 'At least one valid nameserver must be provided.'], 'Invalid nameservers');
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf('UPDATE %s SET nameservers_json = ?, updated_at = ? WHERE id = ?', $this->domainsTable);
        $this->db->statement($sql, [json_encode($cleaned), $now, $id]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'nameservers_updated',
            description: 'Nameservers updated to: ' . implode(', ', $cleaned),
            payload: ['nameservers' => $cleaned],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Nameserver update failed'], 'Domain error');
    }

    public function setRegistrarLock(
        int $id,
        bool $locked,
        string $actorType = 'user',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf('UPDATE %s SET is_locked = ?, updated_at = ? WHERE id = ?', $this->domainsTable);
        $this->db->statement($sql, [$locked ? 1 : 0, $now, $id]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: $locked ? 'locked' : 'unlocked',
            description: $locked ? 'Registrar transfer lock enabled.' : 'Registrar transfer lock disabled.',
            payload: ['is_locked' => $locked],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Lock update failed'], 'Domain error');
    }

    public function setWhoisPrivacy(
        int $id,
        bool $enabled,
        string $actorType = 'user',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf('UPDATE %s SET whois_privacy = ?, updated_at = ? WHERE id = ?', $this->domainsTable);
        $this->db->statement($sql, [$enabled ? 1 : 0, $now, $id]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'whois_privacy_toggled',
            description: $enabled ? 'WHOIS ID Protection enabled.' : 'WHOIS ID Protection disabled.',
            payload: ['whois_privacy' => $enabled],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Privacy update failed'], 'Domain error');
    }

    public function setAutoRenew(
        int $id,
        bool $enabled,
        string $actorType = 'user',
        ?int $actorId = null
    ): Domain {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf('UPDATE %s SET auto_renew = ?, updated_at = ? WHERE id = ?', $this->domainsTable);
        $this->db->statement($sql, [$enabled ? 1 : 0, $now, $id]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'auto_renew_toggled',
            description: $enabled ? 'Domain auto-renew enabled.' : 'Domain auto-renew disabled.',
            payload: ['auto_renew' => $enabled],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'Auto-renew update failed'], 'Domain error');
    }

    public function setEppCode(int $id, string $eppCode): Domain
    {
        $domain = $this->findDomainById($id);
        if ($domain === null) {
            throw new ValidationException(['id' => "Domain ID {$id} not found."], 'Domain not found');
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf('UPDATE %s SET epp_code = ?, updated_at = ? WHERE id = ?', $this->domainsTable);
        $this->db->statement($sql, [trim($eppCode), $now, $id]);

        $this->recordTimelineEvent(
            domainId: $id,
            eventType: 'epp_code_updated',
            description: 'Domain EPP transfer authorization code updated.',
            payload: [],
            actorType: 'system'
        );

        return $this->findDomainById($id) ?? throw new ValidationException(['id' => 'EPP update failed'], 'Domain error');
    }

    /**
     * Upsert a domain contact profile.
     *
     * @param array<string, mixed> $data
     */
    public function setContact(
        int $domainId,
        string $contactType,
        array $data,
        string $actorType = 'user',
        ?int $actorId = null
    ): DomainContact {
        $type = DomainContact::validateContactType($contactType);

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw new ValidationException(['name' => 'First and last name are required for domain contact.'], 'Invalid name');
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'Valid email address is required for domain contact.'], 'Invalid email');
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            throw new ValidationException(['phone' => 'Valid phone number is required for domain contact.'], 'Invalid phone');
        }

        $addressLine1 = trim((string) ($data['address_line_1'] ?? $data['address'] ?? ''));
        $addressLine2 = isset($data['address_line_2']) ? trim((string) $data['address_line_2']) : null;
        $city = trim((string) ($data['city'] ?? ''));
        $state = isset($data['state']) ? trim((string) $data['state']) : null;
        $postalCode = trim((string) ($data['postal_code'] ?? $data['zip'] ?? ''));
        $countryCode = strtoupper(trim((string) ($data['country_code'] ?? $data['country'] ?? 'US')));
        $companyName = isset($data['company_name']) ? trim((string) $data['company_name']) : null;
        $fax = isset($data['fax']) ? trim((string) $data['fax']) : null;
        $additional = (array) ($data['additional_fields'] ?? []);

        $now = date('Y-m-d H:i:s');
        $driver = $this->db->getDriverName();

        if ($driver === 'sqlite') {
            $sql = sprintf(
                'INSERT INTO %s (
                    domain_id, contact_type, first_name, last_name, company_name,
                    email, phone, fax, address_line_1, address_line_2, city, state,
                    postal_code, country_code, additional_fields_json, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(domain_id, contact_type) DO UPDATE SET
                    first_name = excluded.first_name,
                    last_name = excluded.last_name,
                    company_name = excluded.company_name,
                    email = excluded.email,
                    phone = excluded.phone,
                    fax = excluded.fax,
                    address_line_1 = excluded.address_line_1,
                    address_line_2 = excluded.address_line_2,
                    city = excluded.city,
                    state = excluded.state,
                    postal_code = excluded.postal_code,
                    country_code = excluded.country_code,
                    additional_fields_json = excluded.additional_fields_json,
                    updated_at = excluded.updated_at',
                $this->contactsTable
            );
        } else {
            $sql = sprintf(
                'INSERT INTO %s (
                    domain_id, contact_type, first_name, last_name, company_name,
                    email, phone, fax, address_line_1, address_line_2, city, state,
                    postal_code, country_code, additional_fields_json, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    company_name = VALUES(company_name),
                    email = VALUES(email),
                    phone = VALUES(phone),
                    fax = VALUES(fax),
                    address_line_1 = VALUES(address_line_1),
                    address_line_2 = VALUES(address_line_2),
                    city = VALUES(city),
                    state = VALUES(state),
                    postal_code = VALUES(postal_code),
                    country_code = VALUES(country_code),
                    additional_fields_json = VALUES(additional_fields_json),
                    updated_at = VALUES(updated_at)',
                $this->contactsTable
            );
        }

        $this->db->statement($sql, [
            $domainId,
            $type,
            $firstName,
            $lastName,
            $companyName,
            $email,
            $phone,
            $fax,
            $addressLine1,
            $addressLine2,
            $city,
            $state,
            $postalCode,
            $countryCode,
            json_encode($additional),
            $now,
            $now,
        ]);

        $this->recordTimelineEvent(
            domainId: $domainId,
            eventType: 'contacts_updated',
            description: "Updated '{$type}' contact profile ({$firstName} {$lastName}).",
            payload: ['contact_type' => $type, 'email' => $email],
            actorType: $actorType,
            actorId: $actorId
        );

        return $this->getContact($domainId, $type)
            ?? throw new ValidationException(['contact' => 'Failed to save domain contact'], 'Contact error');
    }

    public function getContact(int $domainId, string $contactType): ?DomainContact
    {
        $type = DomainContact::validateContactType($contactType);
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE domain_id = ? AND contact_type = ?', $this->contactsTable),
            [$domainId, $type]
        );

        return $row ? $this->hydrateContact($row) : null;
    }

    /**
     * @return array<string, DomainContact>
     */
    public function getAllContacts(int $domainId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE domain_id = ? ORDER BY contact_type ASC', $this->contactsTable),
            [$domainId]
        );

        $contacts = [];
        foreach ($rows as $row) {
            $contact = $this->hydrateContact($row);
            $contacts[$contact->getContactType()] = $contact;
        }

        return $contacts;
    }

    /**
     * Record an audit event into domain timeline.
     *
     * @param array<string, mixed> $payload
     */
    public function recordTimelineEvent(
        int $domainId,
        string $eventType,
        string $description,
        array $payload = [],
        string $actorType = 'system',
        ?int $actorId = null
    ): DomainTimelineEvent {
        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'INSERT INTO %s (domain_id, event_type, actor_id, actor_type, description, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            $this->timelineTable
        );

        $this->db->statement($sql, [
            $domainId,
            strtolower(trim($eventType)),
            $actorId,
            strtolower(trim($actorType)),
            $description,
            json_encode($payload),
            $now,
        ]);

        $id = (int) $this->db->getPdo()->lastInsertId();

        return new DomainTimelineEvent(
            id: $id,
            domainId: $domainId,
            eventType: $eventType,
            description: $description,
            payload: $payload,
            actorType: $actorType,
            actorId: $actorId,
            createdAt: new DateTimeImmutable($now)
        );
    }

    /**
     * @return array<int, DomainTimelineEvent>
     */
    public function getTimeline(int $domainId, int $limit = 50): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE domain_id = ? ORDER BY id DESC LIMIT %d', $this->timelineTable, $limit),
            [$domainId]
        );

        return array_map([$this, 'hydrateTimelineEvent'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDomain(array $row): Domain
    {
        return new Domain(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            organizationId: isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            domain: (string) $row['domain'],
            sld: (string) $row['sld'],
            tld: (string) $row['tld'],
            status: (string) $row['status'],
            registrationPeriodYears: (int) ($row['registration_period_years'] ?? 1),
            registrationDate: isset($row['registration_date']) ? (string) $row['registration_date'] : null,
            expiryDate: isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
            nextDueDate: isset($row['next_due_date']) ? (string) $row['next_due_date'] : null,
            autoRenew: (bool) ($row['auto_renew'] ?? true),
            whoisPrivacy: (bool) ($row['whois_privacy'] ?? false),
            dnsManagement: (bool) ($row['dns_management'] ?? true),
            emailForwarding: (bool) ($row['email_forwarding'] ?? true),
            isLocked: (bool) ($row['is_locked'] ?? true),
            eppCode: isset($row['epp_code']) ? (string) $row['epp_code'] : null,
            registrarId: isset($row['registrar_id']) ? (string) $row['registrar_id'] : null,
            nameservers: json_decode((string) ($row['nameservers_json'] ?? '[]'), true) ?: [],
            subscriptionId: isset($row['subscription_id']) ? (int) $row['subscription_id'] : null,
            metadata: json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [],
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateContact(array $row): DomainContact
    {
        return new DomainContact(
            id: isset($row['id']) ? (int) $row['id'] : null,
            domainId: (int) $row['domain_id'],
            contactType: (string) $row['contact_type'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            companyName: isset($row['company_name']) ? (string) $row['company_name'] : null,
            email: (string) $row['email'],
            phone: (string) $row['phone'],
            fax: isset($row['fax']) ? (string) $row['fax'] : null,
            addressLine1: (string) $row['address_line_1'],
            addressLine2: isset($row['address_line_2']) ? (string) $row['address_line_2'] : null,
            city: (string) $row['city'],
            state: isset($row['state']) ? (string) $row['state'] : null,
            postalCode: (string) $row['postal_code'],
            countryCode: (string) $row['country_code'],
            additionalFields: json_decode((string) ($row['additional_fields_json'] ?? '{}'), true) ?: [],
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTimelineEvent(array $row): DomainTimelineEvent
    {
        return new DomainTimelineEvent(
            id: isset($row['id']) ? (int) $row['id'] : null,
            domainId: (int) $row['domain_id'],
            eventType: (string) $row['event_type'],
            description: (string) $row['description'],
            payload: json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [],
            actorType: (string) ($row['actor_type'] ?? 'system'),
            actorId: isset($row['actor_id']) ? (int) $row['actor_id'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null
        );
    }
}
