<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Channel\NotificationPriority;
use Coleza\Domain\Notifications\Delivery\DeliveryResult;
use Coleza\Domain\Notifications\Messages\NotificationMessage;
use Coleza\Domain\Notifications\Transport\MailerInterface;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Foundation\Database\Connection;
use Throwable;

/**
 * Persists email transport configuration and tests outbound mail delivery.
 */
final class EmailSetupService
{
    public function __construct(private ?\Coleza\Domain\Vault\Encryptor $encryptor = null) {}

    /**
     * @return array<string, string>
     */
    public function configureEmail(Connection $db, EmailTransportConfig $config, string $prefix = ''): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
        $tSettings = $prefix . 'system_settings';

        $settings = [
            'mail.driver' => $config->getDriver(),
            'mail.from_address' => $config->getFromAddress(),
            'mail.from_name' => $config->getFromName(),
            'mail.host' => (string) ($config->getHost() ?? ''),
            'mail.port' => (string) ($config->getPort() ?? 587),
            'mail.encryption' => (string) ($config->getEncryption() ?? ''),
            'mail.username' => (string) ($config->getUsername() ?? ''),
            'mail.password' => (string) ($config->getPassword() ?? ''),
        ];

        $password = $settings['mail.password'];
        $settings['mail.password'] = $password === '' ? '' : (new \Coleza\Domain\Vault\SecretCipher($this->encryptor))->seal($password, 'smtp:' . $prefix);
        \Coleza\Domain\Vault\SecretStorageUpgrade::assertStorage($db, [$tSettings => ['id', 'setting_key', 'setting_value', 'is_encrypted', 'updated_at']]);
        $db->transaction(function () use ($db, $settings, $tSettings): void {
            foreach ($settings as $key => $val) {
                $existing = $db->selectOne(sprintf('SELECT id FROM %s WHERE setting_key = ?', $tSettings), [$key]);
                $isEncrypted = ($key === 'mail.password' && $val !== '') ? 1 : 0;

                if ($existing !== null) {
                    $db->statement(
                        sprintf('UPDATE %s SET setting_value = ?, is_encrypted = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?', $tSettings),
                        [$val, $isEncrypted, $key]
                    );
                } else {
                    $db->statement(
                        sprintf('INSERT INTO %s (setting_key, setting_value, is_encrypted, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)', $tSettings),
                        [$key, $val, $isEncrypted]
                    );
                }
            }

        });
        $settings['mail.password'] = $password === '' ? '' : '••••••••';
        return $settings;
    }

    public function readConfiguration(Connection $db, string $prefix = ''): EmailTransportConfig
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
        $values = [];
        foreach ($db->select("SELECT setting_key, setting_value FROM {$prefix}system_settings WHERE setting_key LIKE 'mail.%'") as $row) {
            $values[$row['setting_key']] = $row['setting_value'];
        }
        $stored = (string) ($values['mail.password'] ?? '');
        $password = $stored === '' ? null : (new \Coleza\Domain\Vault\SecretCipher($this->encryptor))->open($stored, 'smtp:' . $prefix);
        return new EmailTransportConfig(driver: $values['mail.driver'] ?? 'smtp', fromAddress: $values['mail.from_address'] ?? 'noreply@colezahost.com',
            fromName: $values['mail.from_name'] ?? 'Coleza Host', host: $values['mail.host'] ?? null, port: (int) ($values['mail.port'] ?? 587),
            encryption: $values['mail.encryption'] ?? 'tls', username: $values['mail.username'] ?? null, password: $password);
    }

    /**
     * Dispatches a test verification email to confirm outbound connectivity.
     */
    public function sendTestEmail(
        EmailTransportConfig $config,
        string $recipientEmail,
        ?MailerInterface $overrideMailer = null
    ): DeliveryResult {
        $mailer = $overrideMailer ?? new MemoryMailTransport();

        $message = new NotificationMessage(
            recipientEmail: $recipientEmail,
            subject: 'Coleza Host Installation - Email Verification',
            htmlBody: '<p>Congratulations! Your Coleza Host email transport has been successfully configured.</p>',
            plainTextBody: 'Congratulations! Your Coleza Host email transport has been successfully configured.',
            recipientName: 'Administrator',
            locale: 'en',
            channel: NotificationChannel::EMAIL,
            priority: NotificationPriority::HIGH,
            fromEmail: $config->getFromAddress(),
            fromName: $config->getFromName()
        );

        try {
            return $mailer->send($message);
        } catch (Throwable $e) {
            return DeliveryResult::failure(
                error: $e->getMessage(),
                transport: $config->getDriver()
            );
        }
    }
}
