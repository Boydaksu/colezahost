<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;

/**
 * Generates background automation scheduler tokens, crontab command snippets, and webhook ping URLs.
 */
final class CronSetupService
{
    public const HASH_PREFIX = 'hash:v1:';
    private static function settings(string $prefix): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
        return $prefix . 'system_settings';
    }
    public static function isTokenHash(string $value): bool { return preg_match('/^hash:v1:[a-f0-9]{64}$/D', $value) === 1; }
    /**
     * @return array{token: string, cli_command: string, webhook_url: string}
     */
    public function setupCron(
        Connection $db,
        #[\SensitiveParameter] ?string $customToken = null,
        ?string $appUrl = null,
        ?string $basePath = null,
        string $prefix = ''
    ): array {
        $tSettings = self::settings($prefix);

        $token = $customToken ?? bin2hex(random_bytes(32));
        if (trim($token) === '') { throw new \InvalidArgumentException('Cron token must not be empty.'); }
        $url = rtrim($appUrl ?? 'https://localhost', '/');
        $path = rtrim($basePath ?? '/var/www/colezahost', '/');

        \Coleza\Domain\Vault\SecretStorageUpgrade::assertStorage($db, [$tSettings => ['id', 'setting_key', 'setting_value', 'is_encrypted', 'updated_at']]);
        $stored = self::HASH_PREFIX . hash('sha256', trim($token));
        $db->transaction(function () use ($db, $tSettings, $stored): void {
            $row = $db->selectOne("SELECT id FROM {$tSettings} WHERE setting_key = ?" . $db->forUpdate(), ['cron.token']);
            if ($row !== null) { $db->statement("UPDATE {$tSettings} SET setting_value = ?, is_encrypted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$stored, $row['id']]); }
            else { $db->statement("INSERT INTO {$tSettings} (setting_key, setting_value, is_encrypted, updated_at) VALUES (?, ?, 0, CURRENT_TIMESTAMP)", ['cron.token', $stored]); }
        });

        $cliCommand = sprintf('* * * * * php %s/bin/coleza schedule:run > /dev/null 2>&1', $path);
        $webhookUrl = sprintf('%s/api/cron/run?token=%s', $url, $token);

        return [
            'token' => $token,
            'cli_command' => $cliCommand,
            'webhook_url' => $webhookUrl,
        ];
    }

    public function validateCronToken(Connection $db, #[\SensitiveParameter] string $providedToken, string $prefix = ''): bool
    {
        if (trim($providedToken) === '') { return false; }
        $tSettings = self::settings($prefix);
        $row = $db->selectOne("SELECT setting_value FROM {$tSettings} WHERE setting_key = ?", ['cron.token']);

        if ($row === null || empty($row['setting_value'])) {
            return false;
        }

        return self::isTokenHash($row['setting_value']) && hash_equals($row['setting_value'], self::HASH_PREFIX . hash('sha256', trim($providedToken)));
    }

    public function upgradeLegacyToken(Connection $db, bool $legacyPlaintextConfirmed = false, string $prefix = ''): int
    {
        $table = self::settings($prefix);
        \Coleza\Domain\Vault\SecretStorageUpgrade::assertStorage($db, [$table => ['id', 'setting_key', 'setting_value', 'is_encrypted']]);
        return $db->transaction(function () use ($db, $table, $legacyPlaintextConfirmed): int {
            $row = $db->selectOne("SELECT * FROM {$table} WHERE setting_key = ?" . $db->forUpdate(), ['cron.token']);
            if ($row === null) { return 0; }
            if (self::isTokenHash($row['setting_value']) && (int) $row['is_encrypted'] === 0) { return 0; }
            if (!$legacyPlaintextConfirmed || $row['setting_value'] === '' || str_starts_with($row['setting_value'], 'hash:') || str_starts_with($row['setting_value'], 'enc:')) { throw new \LogicException('Confirm legacy cron plaintext provenance; unknown encodings require reconciliation.'); }
            $db->update($table, ['setting_value' => self::HASH_PREFIX . hash('sha256', trim($row['setting_value'])), 'is_encrypted' => 0], 'id = :id', ['id' => $row['id']]);
            return 1;
        });
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
