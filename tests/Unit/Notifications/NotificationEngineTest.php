<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Notifications;

use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Channel\NotificationPriority;
use Coleza\Domain\Notifications\Messages\NotificationMessage;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Templates\NotificationTemplateEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\Notifications\Transport\SmtpConfiguration;
use Coleza\Domain\Notifications\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class NotificationEngineTest extends TestCase
{
    private MemoryMailTransport $mailer;
    private NotificationTemplateEngine $templateEngine;
    private NotificationEngine $engine;

    protected function setUp(): void
    {
        $this->mailer = new MemoryMailTransport();
        $this->templateEngine = new NotificationTemplateEngine();
        $this->engine = new NotificationEngine($this->mailer, $this->templateEngine);
    }

    public function testTurkishTemplateRendering(): void
    {
        $data = [
            'customer_name' => 'Caner Erkin',
            'order_number' => 'ORD-20261007-000001',
            'total_formatted' => '1.500,00 ₺',
            'action_url' => 'https://panel.coleza.com/orders/ORD-20261007-000001',
        ];

        $rendered = $this->templateEngine->render('order_created', $data, 'tr');

        $this->assertSame('Sipariş Onayı: ORD-20261007-000001', $rendered['subject']);
        $this->assertStringContainsString('Merhaba Caner Erkin,', $rendered['html']);
        $this->assertStringContainsString('1.500,00 ₺', $rendered['html']);
        $this->assertStringContainsString('Detayları Görüntüle', $rendered['html']);
        $this->assertStringContainsString('https://panel.coleza.com/orders/ORD-20261007-000001', $rendered['html']);
        $this->assertStringContainsString('Coleza Host bildirim altyapısı', $rendered['html']);
    }

    public function testEnglishTemplateRendering(): void
    {
        $data = [
            'customer_name' => 'Alice Johnson',
            'invoice_number' => 'INV-2026-000050',
            'total_formatted' => '$120.00',
            'due_date' => '2026-10-21',
            'action_url' => 'https://panel.coleza.com/invoices/INV-2026-000050',
            'action_text' => 'Pay Now',
        ];

        $rendered = $this->templateEngine->render('invoice_created', $data, 'en');

        $this->assertSame('New Invoice Issued: INV-2026-000050', $rendered['subject']);
        $this->assertStringContainsString('Dear Alice Johnson,', $rendered['html']);
        $this->assertStringContainsString('$120.00', $rendered['html']);
        $this->assertStringContainsString('Pay Now', $rendered['html']);
        $this->assertStringContainsString('Due Date: <strong>2026-10-21</strong>', $rendered['html']);
    }

    public function testNotificationEngineMemoryDispatch(): void
    {
        $result = $this->engine->sendNotification(
            templateKey: 'service_activated',
            data: [
                'customer_name' => 'Mehmet Kurt',
                'service_name' => 'Cloud VPS NVMe 2',
                'domain' => 'kurtyazilim.com.tr',
            ],
            recipientEmail: 'mehmet@kurtyazilim.com.tr',
            recipientLocale: 'tr',
            recipientName: 'Mehmet Kurt',
            recipientUserId: 12,
            priority: NotificationPriority::HIGH
        );

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->getMessageId());
        $this->assertSame('memory', $result->getTransport());

        // Verify captured message
        $this->assertSame(1, $this->mailer->count());
        $sent = $this->mailer->findLastByRecipient('mehmet@kurtyazilim.com.tr');
        $this->assertNotNull($sent);
        $this->assertSame('mehmet@kurtyazilim.com.tr', $sent->getRecipientEmail());
        $this->assertSame('Hizmetiniz Aktifleştirildi: Cloud VPS NVMe 2', $sent->getSubject());
        $this->assertSame(NotificationPriority::HIGH, $sent->getPriority());
        $this->assertSame('tr', $sent->getLocale());
    }

    public function testSmtpConfigurationPasswordMasking(): void
    {
        $config = new SmtpConfiguration(
            host: 'mail.coleza.com',
            port: 587,
            username: 'smtp_user',
            password: 'super_secret_smtp_password_123',
            encryption: 'tls',
            fromEmail: 'billing@coleza.com',
            fromName: 'Coleza Billing'
        );

        $this->assertSame('mail.coleza.com', $config->getHost());
        $this->assertSame(587, $config->getPort());
        $this->assertSame('super_secret_smtp_password_123', $config->getPassword());
        $this->assertSame('********', $config->getMaskedPassword());

        $safeArr = $config->toSafeArray();
        $this->assertSame('********', $safeArr['password']);
        $this->assertStringNotContainsString('super_secret', json_encode($safeArr));
    }

    public function testSmtpTransportMimeMessageAssembly(): void
    {
        $config = new SmtpConfiguration(
            host: 'localhost',
            port: 1025,
            fromEmail: 'noreply@coleza.com',
            fromName: 'Coleza Cloud'
        );

        $transport = new SmtpTransport($config);

        $message = new NotificationMessage(
            recipientEmail: 'client@example.com',
            subject: 'Önemli Fatura Bildirimi',
            htmlBody: '<h1>Faturanız Hazır</h1><p>Toplam Tutar: 100 ₺</p>',
            recipientName: 'Test Müşteri',
            fromEmail: 'noreply@coleza.com',
            fromName: 'Coleza Cloud'
        );

        $mime = $transport->buildMimeMessage($message, '<msg-123@coleza.internal>');

        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
        $this->assertStringContainsString('Message-ID: <msg-123@coleza.internal>', $mime);
        $this->assertStringContainsString('MIME-Version: 1.0', $mime);
        $this->assertStringContainsString('Content-Type: multipart/alternative;', $mime);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $mime);
    }

    public function testCustomTemplateRegistrationAndXssEscaping(): void
    {
        $this->templateEngine->registerTemplate(
            key: 'security_alert',
            subjectEn: 'Security Alert: {{ event }}',
            subjectTr: 'Güvenlik Uyarısı: {{ event }}',
            bodyEn: '<p>Warning: {{ malicious_input }}</p>',
            bodyTr: '<p>Uyarı: {{ malicious_input }}</p>'
        );

        $rendered = $this->templateEngine->render('security_alert', [
            'event' => 'Failed Logins',
            'malicious_input' => '<script>alert("hack")</script>',
        ], 'tr');

        $this->assertSame('Güvenlik Uyarısı: Failed Logins', $rendered['subject']);
        $this->assertStringNotContainsString('<script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;hack&quot;)&lt;/script&gt;', $rendered['html']);
    }

    public function testTransportFailureHandling(): void
    {
        $this->mailer->setSimulateFailure(true, 'Connection to mail relay timed out (110)');

        $result = $this->engine->sendDirectEmail(
            recipientEmail: 'down@example.com',
            subject: 'Test Down',
            htmlBody: '<p>Test</p>'
        );

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('timed out', (string) $result->getError());
        $this->assertSame(0, $this->mailer->count());
    }
}
