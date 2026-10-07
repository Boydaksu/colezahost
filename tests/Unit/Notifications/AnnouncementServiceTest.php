<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Notifications;

use Coleza\Domain\Notifications\Announcements\AnnouncementService;
use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Coleza\Domain\Notifications\NotificationEngine;
use Coleza\Domain\Notifications\Transport\MemoryMailTransport;
use Coleza\Domain\Notifications\Unsubscribe\UnsubscribeService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnnouncementServiceTest extends TestCase
{
    private PDO $pdo;
    private NotificationCenterService $centerService;
    private UnsubscribeService $unsubscribeService;
    private AnnouncementService $announcementService;
    private MemoryMailTransport $mailer;
    private NotificationEngine $engine;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->centerService = new NotificationCenterService($this->pdo);
        $this->unsubscribeService = new UnsubscribeService('test_secret_key');
        $this->announcementService = new AnnouncementService($this->pdo, $this->centerService, $this->unsubscribeService);

        $this->mailer = new MemoryMailTransport();
        $this->engine = new NotificationEngine($this->mailer);
    }

    public function testCreateAndPublishAnnouncement(): void
    {
        $announcement = $this->announcementService->createAnnouncement(
            title: 'Veri Merkezi Planlı Bakım Çalışması',
            content: '15 Ekim saat 02:00 - 04:00 arasında ağ omurgasında güncelleme yapılacaktır.',
            category: 'maintenance',
            isPinned: true
        );

        $this->assertNotNull($announcement->getId());
        $this->assertFalse($announcement->isPublished());
        $this->assertTrue($announcement->isPinned());
        $this->assertStringContainsString('veri-merkezi-planli-bakim-calismasi', $announcement->getSlug());

        $published = $this->announcementService->publishAnnouncement($announcement->getId());
        $this->assertTrue($published->isPublished());
        $this->assertNotNull($published->getPublishedAt());
        $this->assertTrue($published->isActive());
    }

    public function testPublicAnnouncementsAndPinnedAlerts(): void
    {
        $a1 = $this->announcementService->createAnnouncement(
            title: 'Genel Bilgilendirme',
            content: 'Yeni kontrol paneli özellikleri yayında.',
            category: 'general',
            isPinned: false
        );

        $a2 = $this->announcementService->createAnnouncement(
            title: 'Kritik Bakım Uyarısı',
            content: 'Elektrik altyapı testi.',
            category: 'maintenance',
            isPinned: true
        );

        $this->announcementService->publishAnnouncement($a1->getId());
        $this->announcementService->publishAnnouncement($a2->getId());

        $alerts = $this->announcementService->getActiveAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('Kritik Bakım Uyarısı', $alerts[0]->getTitle());

        $public = $this->announcementService->getPublicAnnouncements();
        $this->assertCount(2, $public);
        // Pinned alert comes first
        $this->assertSame('Kritik Bakım Uyarısı', $public[0]->getTitle());
    }

    public function testUnsubscribeTokenGenerationAndValidation(): void
    {
        $userId = 45;
        $email = 'newsletter@customer.com';
        $token = $this->unsubscribeService->generateToken($userId, $email, 'marketing');

        $this->assertNotEmpty($token);

        // Valid decode
        $decoded = $this->unsubscribeService->validateAndDecode($token);
        $this->assertNotNull($decoded);
        $this->assertSame(45, $decoded['userId']);
        $this->assertSame('newsletter@customer.com', $decoded['email']);
        $this->assertSame('marketing', $decoded['category']);

        // Tampered token fails
        $tampered = base64_encode(json_encode(['u' => 45, 'e' => 'newsletter@customer.com', 'c' => 'marketing', 's' => 'fake_signature']));
        $this->assertNull($this->unsubscribeService->validateAndDecode($tampered));

        // RFC 8058 headers
        $headers = $this->unsubscribeService->buildListUnsubscribeHeaders('https://panel.coleza.com/unsub');
        $this->assertSame('<https://panel.coleza.com/unsub>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function testBroadcastMarketingRespectsOptOutPreferences(): void
    {
        // User 1 opts IN to marketing
        $this->centerService->setPreference(
            userId: 101,
            category: 'marketing',
            email: true,
            inApp: true,
            sms: false
        );

        // User 2 opts OUT of marketing
        $this->centerService->setPreference(
            userId: 102,
            category: 'marketing',
            email: false,
            inApp: false,
            sms: false
        );

        $announcement = $this->announcementService->createAnnouncement(
            title: 'Black Friday %50 İndirim Kampanyası',
            content: 'Tüm bulut sunucularda geçerli kupon kodu: BLACK50',
            category: 'promotion'
        );
        $this->announcementService->publishAnnouncement($announcement->getId());

        $recipients = [
            ['userId' => 101, 'email' => 'optin@example.com', 'name' => 'Opted In User', 'locale' => 'tr'],
            ['userId' => 102, 'email' => 'optout@example.com', 'name' => 'Opted Out User', 'locale' => 'tr'],
        ];

        $stats = $this->announcementService->broadcastMarketingAnnouncement(
            announcementId: $announcement->getId(),
            engine: $this->engine,
            recipients: $recipients
        );

        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['skipped']);

        $this->assertSame(1, $this->mailer->count());
        $sentMessage = $this->mailer->findLastByRecipient('optin@example.com');
        $this->assertNotNull($sentMessage);
        $this->assertNull($this->mailer->findLastByRecipient('optout@example.com'));

        // Unsubscribe link was added to sent email
        $this->assertStringContainsString('/unsubscribe?token=', $sentMessage->getHtmlBody());
    }
}
