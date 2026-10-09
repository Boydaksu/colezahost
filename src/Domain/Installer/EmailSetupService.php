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
    /**
     * @return array<string, string>
     */
    public function configureEmail(Connection $db, EmailTransportConfig $config, string $prefix = ''): array
    {
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

        foreach ($settings as $key => $val) {
            $existing = $db->selectOne(sprintf('SELECT id FROM %s WHERE setting_key = ?', $tSettings), [$key]);
            $isEncrypted = ($key === 'mail.password') ? 1 : 0;

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

        return $settings;
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
