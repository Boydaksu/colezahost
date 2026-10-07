<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Notifications;

use Coleza\Domain\Notifications\Center\NotificationCenterService;
use Coleza\Domain\Notifications\Channel\NotificationChannel;
use Coleza\Domain\Notifications\Preferences\NotificationPreference;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationCenterServiceTest extends TestCase
{
    private PDO $pdo;
    private NotificationCenterService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->service = new NotificationCenterService($this->pdo);
    }

    public function testUserPreferencesDefaultsAndUpdates(): void
    {
        $userId = 10;
        $prefs = $this->service->getUserPreferences($userId);

        // Check defaults
        $this->assertArrayHasKey('marketing', $prefs);
        $this->assertTrue($prefs['marketing']->isEmailEnabled());
        $this->assertTrue($this->service->isChannelEnabled($userId, 'marketing', NotificationChannel::EMAIL));

        // Opt out of marketing emails
        $updated = $this->service->setPreference(
            userId: $userId,
            category: 'marketing',
            email: false,
            inApp: false,
            sms: false
        );

        $this->assertFalse($updated->isEmailEnabled());
        $this->assertFalse($this->service->isChannelEnabled($userId, 'marketing', NotificationChannel::EMAIL));
        $this->assertFalse($this->service->isChannelEnabled($userId, 'marketing', NotificationChannel::IN_APP));
    }

    public function testMandatorySecurityCategoryCannotBeDisabled(): void
    {
        $userId = 15;

        // Security channel is always enabled
        $this->assertTrue($this->service->isChannelEnabled($userId, 'security', NotificationChannel::EMAIL));

        // Attempting to opt out of mandatory security notifications must fail
        $this->expectException(InvalidArgumentException::class);
        $this->service->setPreference(
            userId: $userId,
            category: 'security',
            email: false,
            inApp: true,
            sms: false
        );
    }

    public function testInAppNotificationCreationAndReadStatus(): void
    {
        $userId = 25;

        $n1 = $this->service->createInAppNotification(
            userId: $userId,
            title: 'Fatura Ödendi',
            message: 'INV-2026-000001 numaralı faturanız ödendi.',
            actionUrl: '/invoices/1',
            type: 'success'
        );

        $n2 = $this->service->createInAppNotification(
            userId: $userId,
            title: 'Sunucu Bakımı',
            message: 'Planlı bakım çalışması bu gece yapılacaktır.',
            type: 'warning'
        );

        $this->assertSame(2, $this->service->getUnreadCount($userId));

        // Mark first notification as read
        $readResult = $this->service->markAsRead($n1->getId(), $userId);
        $this->assertTrue($readResult);

        $this->assertSame(1, $this->service->getUnreadCount($userId));

        // Unread only query
        $unreadList = $this->service->getUserNotifications($userId, unreadOnly: true);
        $this->assertCount(1, $unreadList);
        $this->assertSame($n2->getId(), $unreadList[0]->getId());
    }

    public function testMarkAllAsRead(): void
    {
        $userId = 33;

        $this->service->createInAppNotification($userId, 'Notif 1', 'Msg 1');
        $this->service->createInAppNotification($userId, 'Notif 2', 'Msg 2');
        $this->service->createInAppNotification($userId, 'Notif 3', 'Msg 3');

        $this->assertSame(3, $this->service->getUnreadCount($userId));

        $marked = $this->service->markAllAsRead($userId);
        $this->assertSame(3, $marked);
        $this->assertSame(0, $this->service->getUnreadCount($userId));
    }

    public function testDeliveryLoggingAndHistory(): void
    {
        $userId = 40;

        $logSuccess = $this->service->recordDelivery(
            channel: NotificationChannel::EMAIL,
            recipient: 'user@example.com',
            subject: 'Hoşgeldiniz',
            status: 'sent',
            userId: $userId,
            templateKey: 'welcome_email',
            messageId: '<msg-1@coleza.com>'
        );

        $logFailure = $this->service->recordDelivery(
            channel: NotificationChannel::EMAIL,
            recipient: 'bad-email@invalid.test',
            subject: 'Fatura Bildirimi',
            status: 'failed',
            userId: $userId,
            templateKey: 'invoice_created',
            error: '550 User unknown'
        );

        $this->assertSame('sent', $logSuccess->getStatus());
        $this->assertSame('failed', $logFailure->getStatus());

        $logs = $this->service->getDeliveryLogs($userId, limit: 10);
        $this->assertCount(2, $logs);
        $this->assertSame('bad-email@invalid.test', $logs[0]->getRecipient());
        $this->assertSame('550 User unknown', $logs[0]->getError());
        $this->assertSame('user@example.com', $logs[1]->getRecipient());
    }
}
