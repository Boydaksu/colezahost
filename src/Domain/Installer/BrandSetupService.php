<?php

declare(strict_types=1);
namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Domain\Identity\Rbac\RbacService;

final class BrandSetupService
{
    public function configureBrand(Connection $db, BrandSetupDto $dto, string $defaultCurrency = 'USD', string $defaultLocale = 'en', string $prefix = '', ?int $ownerUserId = null): array
    {
        new ProviderIdentitySchema($db, $prefix); // Identifier validation only; no application-time DDL.
        if ($ownerUserId === null || $ownerUserId <= 0) { $this->reject('An explicit provider owner is required.'); }
        return $db->transaction(function () use ($db, $dto, $defaultCurrency, $defaultLocale, $prefix, $ownerUserId): array {
            $binding = $this->lockBinding($db, $prefix);
            $this->requireOwner($db, $prefix, $ownerUserId);
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($dto->getCompanyName())) ?: 'default-org';
            $code = preg_replace('/[^a-z0-9]+/', '-', strtolower($dto->getBrandName())) ?: 'default-brand';
            if ($binding['organization_id'] === null) {
                if ($db->selectOne("SELECT id FROM {$prefix}system_settings WHERE setting_key IN ('brand.name', 'brand.company_name', 'brand.organization_id') LIMIT 1") !== null) {
                    $this->reject('Legacy provider identity needs an explicit organization and brand binding.');
                }
                $orgId = (int) $db->insert($prefix . 'organizations', ['name' => $dto->getCompanyName(), 'slug' => $slug, 'owner_user_id' => $ownerUserId, 'status' => 'active', 'billing_currency' => $defaultCurrency]);
                $db->insert($prefix . 'organization_members', ['organization_id' => $orgId, 'user_id' => $ownerUserId, 'role' => 'owner']);
                $brandId = (int) $db->insert($prefix . 'brands', ['name' => $dto->getBrandName(), 'code' => $code, 'is_active' => 1]);
                $db->update($prefix . 'installer_provider_identity', ['organization_id' => $orgId, 'brand_id' => $brandId, 'owner_user_id' => $ownerUserId], 'id = :id', ['id' => 1]);
            } else {
                [$orgId, $brandId] = $this->validateBinding($db, $prefix, $binding, $ownerUserId);
            }
            $db->update($prefix . 'organizations', ['name' => $dto->getCompanyName(), 'slug' => $slug, 'billing_currency' => $defaultCurrency], 'id = :id', ['id' => $orgId]);
            $settings = json_encode(['company_name' => $dto->getCompanyName(), 'support_email' => $dto->getSupportEmail(), 'address' => $dto->getAddress(), 'tax_number' => $dto->getTaxNumber()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $db->update($prefix . 'brands', ['name' => $dto->getBrandName(), 'code' => $code, 'domain' => $dto->getDomain(), 'default_locale' => $defaultLocale, 'default_currency' => $defaultCurrency, 'settings' => $settings], 'id = :id', ['id' => $brandId]);
            foreach (['brand.name' => $dto->getBrandName(), 'brand.company_name' => $dto->getCompanyName(), 'brand.support_email' => $dto->getSupportEmail(), 'brand.tax_number' => $dto->getTaxNumber() ?? '', 'brand.address' => $dto->getAddress() ?? '', 'brand.organization_id' => (string) $orgId, 'brand.id' => (string) $brandId] as $key => $value) {
                $sql = "INSERT INTO {$prefix}system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0)";
                $sql .= $db->getDriverName() === 'mysql'
                    ? ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
                    : ' ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP';
                $db->statement($sql, [$key, $value]);
            }
            return ['brand_id' => $brandId, 'organization_id' => $orgId, 'brand_name' => $dto->getBrandName(), 'company_name' => $dto->getCompanyName()];
        });
    }

    /** Explicit reconciliation of a legacy installation; never select the first available row. */
    public function bindExistingProvider(Connection $db, int $organizationId, int $brandId, int $ownerUserId, string $prefix = ''): void
    {
        new ProviderIdentitySchema($db, $prefix);
        if (min($organizationId, $brandId, $ownerUserId) <= 0) { $this->reject('Explicit positive provider identity ids are required.'); }
        $db->transaction(function () use ($db, $organizationId, $brandId, $ownerUserId, $prefix): void {
            $binding = $this->lockBinding($db, $prefix);
            $this->requireOwner($db, $prefix, $ownerUserId);
            if ($binding['organization_id'] !== null) {
                if ((int) $binding['organization_id'] !== $organizationId || (int) $binding['brand_id'] !== $brandId || (int) $binding['owner_user_id'] !== $ownerUserId) {
                    $this->reject('Provider identity is already bound; implicit reassignment is forbidden.');
                }
                $this->validateBinding($db, $prefix, $binding, $ownerUserId);
                return;
            }
            $org = $db->selectOne("SELECT * FROM {$prefix}organizations WHERE id = ?" . $db->forUpdate(), [$organizationId]);
            $brand = $db->selectOne("SELECT * FROM {$prefix}brands WHERE id = ?" . $db->forUpdate(), [$brandId]);
            if ($org === null || $brand === null || $org['status'] !== 'active' || (int) $brand['is_active'] !== 1) { $this->reject('Selected provider records are missing or inactive.'); }
            if ($org['owner_user_id'] !== null && (int) $org['owner_user_id'] !== $ownerUserId) { $this->reject('Selected organization belongs to another owner.'); }
            if ($db->selectOne("SELECT id FROM {$prefix}organization_members WHERE organization_id = ? AND role = 'owner' AND user_id <> ?" . $db->forUpdate(), [$organizationId, $ownerUserId]) !== null) { $this->reject('Selected organization has another owner membership.'); }
            $db->update($prefix . 'organizations', ['owner_user_id' => $ownerUserId], 'id = :id', ['id' => $organizationId]);
            $conflict = $db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE role = VALUES(role)' : ' ON CONFLICT(organization_id, user_id) DO UPDATE SET role = excluded.role';
            $db->statement("INSERT INTO {$prefix}organization_members (organization_id, user_id, role) VALUES (?, ?, ?)" . $conflict, [$organizationId, $ownerUserId, 'owner']);
            $db->update($prefix . 'installer_provider_identity', ['organization_id' => $organizationId, 'brand_id' => $brandId, 'owner_user_id' => $ownerUserId], 'id = :id', ['id' => 1]);
        });
    }

    private function lockBinding(Connection $db, string $prefix): array
    {
        $db->lockRow($prefix . 'installer_provider_identity', 1);
        $binding = $db->selectOne("SELECT * FROM {$prefix}installer_provider_identity WHERE id = 1" . $db->forUpdate());
        if ($binding === null) { $this->reject('Provider identity schema is not initialized; apply migrations first.'); }
        return $binding;
    }
    private function requireOwner(Connection $db, string $prefix, int $owner): void
    {
        $db->lockRow($prefix . 'users', $owner);
        $user = $db->selectOne("SELECT is_active, status FROM {$prefix}users WHERE id = ?" . $db->forUpdate(), [$owner]);
        if ($user === null || (int) $user['is_active'] !== 1 || $user['status'] !== 'active' || !(new RbacService($db, $prefix))->hasPermissionFromInitializedSchema($owner, 'installer.brand.configure')) { $this->reject('Provider owner is inactive or lacks installer permission.'); }
    }
    private function validateBinding(Connection $db, string $prefix, array $binding, int $owner): array
    {
        if ((int) $binding['owner_user_id'] !== $owner) { $this->reject('Provider identity belongs to another owner.'); }
        $org = $db->selectOne("SELECT owner_user_id, status FROM {$prefix}organizations WHERE id = ?" . $db->forUpdate(), [$binding['organization_id']]);
        $brand = $db->selectOne("SELECT is_active FROM {$prefix}brands WHERE id = ?" . $db->forUpdate(), [$binding['brand_id']]);
        $member = $db->selectOne("SELECT role FROM {$prefix}organization_members WHERE organization_id = ? AND user_id = ?" . $db->forUpdate(), [$binding['organization_id'], $owner]);
        if ($org === null || (int) $org['owner_user_id'] !== $owner || $org['status'] !== 'active' || $brand === null || (int) $brand['is_active'] !== 1 || ($member['role'] ?? null) !== 'owner') { $this->reject('Provider identity is missing, inactive or has inconsistent ownership.'); }
        return [(int) $binding['organization_id'], (int) $binding['brand_id']];
    }
    private function reject(string $message): never
    {
        throw new ValidationException(['provider_identity' => [$message]], $message);
    }
}
