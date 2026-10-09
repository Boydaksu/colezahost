<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;

/**
 * Generates background automation scheduler tokens, crontab command snippets, and webhook ping URLs.
 */
final class CronSetupService
{
    /**
     * @return array{token: string, cli_command: string, webhook_url: string}
     */
    public function setupCron(
        Connection $db,
        ?string $customToken = null,
        ?string $appUrl = null,
        ?string $basePath = null,
        string $prefix = ''
    ): array {
        $tSettings = $prefix . 'system_settings';

        $token = $customToken ?? bin2hex(random_bytes(32));
        $url = rtrim($appUrl ?? 'https://localhost', '/');
        $path = rtrim($basePath ?? '/var/www/colezahost', '/');

        // 1. Save cron token to system_settings
        $existing = $db->selectOne(sprintf('SELECT id FROM %s WHERE setting_key = "cron.token"', $tSettings));
        if ($existing !== null) {
            $db->statement(
                sprintf('UPDATE %s SET setting_value = ?, is_encrypted = 1, updated_at = CURRENT_TIMESTAMP WHERE setting_key = "cron.token"', $tSettings),
                [$token]
            );
        } else {
            $db->statement(
                sprintf('INSERT INTO %s (setting_key, setting_value, is_encrypted, updated_at) VALUES ("cron.token", ?, 1, CURRENT_TIMESTAMP)', $tSettings),
                [$token]
            );
        }

        $cliCommand = sprintf('* * * * * php %s/bin/coleza schedule:run > /dev/null 2>&1', $path);
        $webhookUrl = sprintf('%s/api/cron/run?token=%s', $url, $token);

        return [
            'token' => $token,
            'cli_command' => $cliCommand,
            'webhook_url' => $webhookUrl,
        ];
    }

    public function validateCronToken(Connection $db, string $providedToken, string $prefix = ''): bool
    {
        $tSettings = $prefix . 'system_settings';
        $row = $db->selectOne(sprintf('SELECT setting_value FROM %s WHERE setting_key = "cron.token"', $tSettings));

        if ($row === null || empty($row['setting_value'])) {
            return false;
        }

        return hash_equals((string) $row['setting_value'], trim($providedToken));
    }

    public function recordCronExecution(
        Connection $db,
        int $durationMs,
        string $status = 'success',
        ?string $output = null,
        string $prefix = ''
    ): void {
        $tCron = $prefix . 'cron_runs';
        $db->statement(
            sprintf('INSERT INTO %s (duration_ms, status, output) VALUES (?, ?, ?)', $tCron),
            [$durationMs, $status, $output]
        );
    }
}
