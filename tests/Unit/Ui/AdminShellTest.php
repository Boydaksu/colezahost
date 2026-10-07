<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Ui;

use Coleza\Ui\Admin\AdminShell;
use PHPUnit\Framework\TestCase;

final class AdminShellTest extends TestCase
{
    public function testAdminShellRendersThemeAndDensityAttributes(): void
    {
        $shell = new AdminShell(
            title: 'Coleza Operations',
            activeBrand: 'Coleza Global',
            theme: 'dark',
            density: 'compact'
        );

        $html = $shell->render('<h1>Dashboard Overview</h1>', 'Root Admin');

        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('data-density="compact"', $html);
        $this->assertStringContainsString('Coleza Operations', $html);
        $this->assertStringContainsString('Coleza Global', $html);
        $this->assertStringContainsString('Root Admin', $html);
        $this->assertStringContainsString('<h1>Dashboard Overview</h1>', $html);
    }

    public function testAdminShellSidebarNavigationAndBadges(): void
    {
        $nav = [
            ['label' => 'Dashboard', 'url' => '/admin', 'icon' => '📊', 'active' => true],
            ['label' => 'Billing', 'url' => '/admin/billing', 'icon' => '💳', 'badge' => '3'],
            ['label' => 'Servers', 'url' => '/admin/servers', 'icon' => '🖥️'],
        ];

        $shell = new AdminShell(navigation: $nav);
        $html = $shell->render('<div>Content</div>');

        $this->assertStringContainsString('co-nav-item-active', $html);
        $this->assertStringContainsString('href="/admin/billing"', $html);
        $this->assertStringContainsString('>3</span>', $html); // Badge
        $this->assertStringContainsString('aria-label="Main Navigation"', $html);
    }

    public function testActionCenterSkeletonAndNotificationCount(): void
    {
        $actions = [
            [
                'title' => 'Server Load High',
                'message' => 'Node-01 memory usage exceeded 90%',
                'time' => '5 mins ago',
                'priority' => 'danger',
            ],
            [
                'title' => 'SSL Renewal Due',
                'message' => '3 certificates expiring in 48h',
                'time' => '1 hour ago',
                'priority' => 'warning',
            ],
        ];

        $shell = new AdminShell(actionCenterItems: $actions);
        $html = $shell->render('<div>Content</div>');

        // Action Center toggle with count badge
        $this->assertStringContainsString('<span class="co-notification-dot" aria-hidden="true">2</span>', $html);
        // Action Center drawer container
        $this->assertStringContainsString('id="action-center"', $html);
        $this->assertStringContainsString('aria-label="Operational Action Center"', $html);
        $this->assertStringContainsString('Node-01 memory usage exceeded 90%', $html);
        $this->assertStringContainsString('co-action-card-danger', $html);
        $this->assertStringContainsString('co-action-card-warning', $html);
    }

    public function testGlobalSearchTriggerButton(): void
    {
        $shell = new AdminShell();
        $html = $shell->render('<div>Content</div>');

        $this->assertStringContainsString('class="co-ctrl-k-btn"', $html);
        $this->assertStringContainsString('<kbd class="co-kbd">Ctrl+K</kbd>', $html);
    }
}
