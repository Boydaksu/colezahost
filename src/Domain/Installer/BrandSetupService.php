<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;

/**
 * Initializes the root provider organization and active brand identity.
 */
final class BrandSetupService
{
    /**
     * @return array{brand_id: int, organization_id: int, brand_name: string, company_name: string}
     */
    public function configureBrand(
        Connection $db,
        BrandSetupDto $dto,
        string $defaultCurrency = 'USD',
        string $defaultLocale = 'en',
        string $prefix = ''
    ): array {
        $tOrgs = $prefix . 'organizations';
        $tBrands = $prefix . 'brands';
        $tSettings = $prefix . 'system_settings';

        // 1. Setup root organization
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($dto->getCompanyName())) ?: 'default-org';
        $existingOrg = $db->selectOne(sprintf('SELECT id FROM %s ORDER BY id ASC LIMIT 1', $tOrgs));

        if ($existingOrg !== null) {
            $orgId = (int) $existingOrg['id'];
            $db->statement(
                sprintf('UPDATE %s SET name = ?, slug = ?, status = "active", billing_currency = ? WHERE id = ?', $tOrgs),
                [$dto->getCompanyName(), $slug, $defaultCurrency, $orgId]
            );
        } else {
            $db->statement(
                sprintf('INSERT INTO %s (name, slug, status, billing_currency) VALUES (?, ?, "active", ?)', $tOrgs),
                [$dto->getCompanyName(), $slug, $defaultCurrency]
            );
            $orgId = (int) $db->getPdo()->lastInsertId();
        }

        // 2. Setup active brand
        $code = preg_replace('/[^a-z0-9]+/', '-', strtolower($dto->getBrandName())) ?: 'default-brand';
        $brandSettings = json_encode([
            'company_name' => $dto->getCompanyName(),
            'support_email' => $dto->getSupportEmail(),
            'address' => $dto->getAddress(),
            'tax_number' => $dto->getTaxNumber(),
        ], JSON_UNESCAPED_UNICODE);

        $existingBrand = $db->selectOne(sprintf('SELECT id FROM %s WHERE is_active = 1 ORDER BY id ASC LIMIT 1', $tBrands));

        if ($existingBrand !== null) {
            $brandId = (int) $existingBrand['id'];
            $db->statement(
                sprintf('UPDATE %s SET name = ?, code = ?, domain = ?, default_locale = ?, default_currency = ?, settings = ? WHERE id = ?', $tBrands),
                [$dto->getBrandName(), $code, $dto->getDomain(), $defaultLocale, $defaultCurrency, $brandSettings, $brandId]
            );
        } else {
            $db->statement(
                sprintf('INSERT INTO %s (name, code, domain, default_locale, default_currency, is_active, settings) VALUES (?, ?, ?, ?, ?, 1, ?)', $tBrands),
                [$dto->getBrandName(), $code, $dto->getDomain(), $defaultLocale, $defaultCurrency, $brandSettings]
            );
            $brandId = (int) $db->getPdo()->lastInsertId();
        }

        // 3. Persist settings
        $brandSettingsMap = [
            'brand.name' => $dto->getBrandName(),
            'brand.company_name' => $dto->getCompanyName(),
            'brand.support_email' => $dto->getSupportEmail(),
            'brand.tax_number' => $dto->getTaxNumber() ?? '',
            'brand.address' => $dto->getAddress() ?? '',
        ];

        foreach ($brandSettingsMap as $key => $val) {
            $existing = $db->selectOne(sprintf('SELECT id FROM %s WHERE setting_key = ?', $tSettings), [$key]);
            if ($existing !== null) {
                $db->statement(
                    sprintf('UPDATE %s SET setting_value = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?', $tSettings),
                    [$val, $key]
                );
            } else {
                $db->statement(
                    sprintf('INSERT INTO %s (setting_key, setting_value, is_encrypted, updated_at) VALUES (?, ?, 0, CURRENT_TIMESTAMP)', $tSettings),
                    [$key, $val]
                );
            }
        }

        return [
            'brand_id' => $brandId,
            'organization_id' => $orgId,
            'brand_name' => $dto->getBrandName(),
            'company_name' => $dto->getCompanyName(),
        ];
    }
}
