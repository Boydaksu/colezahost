<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications;

use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Channel\NotificationPriority;
use Coleza\Domain\Notifications\Delivery\DeliveryResult;
use Coleza\Domain\Notifications\Messages\NotificationMessage;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MailerInterface;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;

final class NotificationEngine
{
    private MailerInterface $mailer;
    private NotificationTemplateEngine $templateEngine;

    public function __construct(
        ?MailerInterface $mailer = null,
        ?NotificationTemplateEngine $templateEngine = null
    ) {
        $this->mailer = $mailer ?? new MemoryMailTransport();
        $this->templateEngine = $templateEngine ?? new NotificationTemplateEngine();
    }

    /**
     * Renders a localized notification template and dispatches via configured mailer.
     *
     * @param string $templateKey
     * @param array<string, mixed> $data
     * @param string $recipientEmail
     * @param string $recipientLocale
     * @param string|null $recipientName
     * @param int|null $recipientUserId
     * @param NotificationChannel $channel
     * @param NotificationPriority $priority
     * @param array<string, mixed> $options
     * @return DeliveryResult
     */
    public function sendNotification(
        string $templateKey,
        array $data,
        string $recipientEmail,
        string $recipientLocale = 'en',
        ?string $recipientName = null,
        ?int $recipientUserId = null,
        NotificationChannel $channel = NotificationChannel::EMAIL,
        NotificationPriority $priority = NotificationPriority::NORMAL,
        array $options = []
    ): DeliveryResult {
        $rendered = $this->templateEngine->render($templateKey, $data, $recipientLocale);

        $message = new NotificationMessage(
            recipientEmail: $recipientEmail,
            subject: $rendered['subject'],
            htmlBody: $rendered['html'],
            plainTextBody: $rendered['text'],
            recipientName: $recipientName,
            recipientUserId: $recipientUserId,
            locale: $recipientLocale,
            channel: $channel,
            priority: $priority,
            fromEmail: $options['from_email'] ?? null,
            fromName: $options['from_name'] ?? null,
            metadata: array_merge(['template_key' => $templateKey], $options['metadata'] ?? []),
            tags: $options['tags'] ?? [$templateKey]
        );

        return $this->mailer->send($message);
    }

    /**
     * Dispatches a direct, ad-hoc email message without an internal template key.
     *
     * @param string $recipientEmail
     * @param string $subject
     * @param string $htmlBody
     * @param string|null $plainText
     * @param string|null $recipientName
     * @param int|null $recipientUserId
     * @param string $locale
     * @param array<string, mixed> $options
     * @return DeliveryResult
     */
    public function sendDirectEmail(
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        ?string $plainText = null,
        ?string $recipientName = null,
        ?int $recipientUserId = null,
        string $locale = 'en',
        array $options = []
    ): DeliveryResult {
        $message = new NotificationMessage(
            recipientEmail: $recipientEmail,
            subject: $subject,
            htmlBody: $htmlBody,
            plainTextBody: $plainText,
            recipientName: $recipientName,
            recipientUserId: $recipientUserId,
            locale: $locale,
            channel: NotificationChannel::EMAIL,
            priority: $options['priority'] ?? NotificationPriority::NORMAL,
            fromEmail: $options['from_email'] ?? null,
            fromName: $options['from_name'] ?? null,
            metadata: $options['metadata'] ?? [],
            tags: $options['tags'] ?? ['direct_email']
        );

        return $this->mailer->send($message);
    }

    public function getMailer(): MailerInterface
    {
        return $this->mailer;
    }

    public function getTemplateEngine(): NotificationTemplateEngine
    {
        return $this->templateEngine;
    }
}
