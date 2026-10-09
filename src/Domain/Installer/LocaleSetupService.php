<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;

/**
 * Persists application localization, timezone, and base operational currency configurations.
 */
final class LocaleSetupService
{
    /**
     * @return array<string, string>
     */
    public function configureLocale(Connection $db, LocaleSetupDto $dto, string $prefix = ''): array
    {
        $tSettings = $prefix . 'system_settings';

        $settings = [
            'app.locale' => $dto->getDefaultLocale(),
            'app.timezone' => $dto->getTimezone(),
            'app.currency' => $dto->getDefaultCurrency(),
            'app.date_format' => $dto->getDateFormat(),
        ];

        foreach ($settings as $key => $value) {
            $existing = $db->selectOne(sprintf('SELECT id FROM %s WHERE setting_key = ?', $tSettings), [$key]);
            if ($existing !== null) {
                $db->statement(
                    sprintf('UPDATE %s SET setting_value = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?', $tSettings),
                    [$value, $key]
                );
            } else {
                $db->statement(
                    sprintf('INSERT INTO %s (setting_key, setting_value, is_encrypted, updated_at) VALUES (?, ?, 0, CURRENT_TIMESTAMP)', $tSettings),
                    [$key, $value]
                );
            }
        }

        return $settings;
    }
}
