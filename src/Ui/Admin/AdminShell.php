<?php

declare(strict_types=1);

namespace Coleza\Ui\Admin;

final class AdminShell
{
    /**
     * @param array<int, array{label: string, url: string, icon: string, active?: bool, badge?: string}> $navigation
     * @param array<int, array{title: string, message: string, time: string, priority: string}> $actionCenterItems
     */
    public function __construct(
        private string $title = 'Coleza Host Admin',
        private string $activeBrand = 'Coleza Host',
        private string $theme = 'light',
        private string $density = 'comfortable',
        private array $navigation = [],
        private array $actionCenterItems = []
    ) {
    }

    /**
     * Render complete Admin Layout Shell.
     */
    public function render(string $contentHtml, ?string $currentAdminName = null): string
    {
        $themeAttr = sprintf('data-theme="%s"', htmlspecialchars($this->theme, ENT_QUOTES, 'UTF-8'));
        $densityAttr = sprintf('data-density="%s"', htmlspecialchars($this->density, ENT_QUOTES, 'UTF-8'));

        $sidebarHtml = $this->renderSidebar();
        $headerHtml = $this->renderHeader($currentAdminName);
        $actionCenterHtml = $this->renderActionCenter();

        return sprintf(
            '<!DOCTYPE html>
<html lang="tr" %s %s>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>%s</title>
    <link rel="stylesheet" href="/assets/tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="co-admin-body">
    <div class="co-admin-layout">
        %s
        <div class="co-admin-main">
            %s
            <main id="main-content" class="co-admin-content" tabindex="-1">
                %s
            </main>
        </div>
        %s
    </div>
</body>
</html>',
            $themeAttr,
            $densityAttr,
            htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8'),
            $sidebarHtml,
            $headerHtml,
            $contentHtml,
            $actionCenterHtml
        );
    }

    private function renderSidebar(): string
    {
        $itemsHtml = '';
        foreach ($this->navigation as $item) {
            $activeClass = !empty($item['active']) ? ' co-nav-item-active' : '';
            $badgeHtml = !empty($item['badge'])
                ? sprintf('<span class="co-nav-badge">%s</span>', htmlspecialchars($item['badge'], ENT_QUOTES, 'UTF-8'))
                : '';

            $itemsHtml .= sprintf(
                '<li class="co-nav-item%s">
                    <a href="%s" class="co-nav-link">
                        <span class="co-nav-icon" aria-hidden="true">%s</span>
                        <span class="co-nav-label">%s</span>
                        %s
                    </a>
                </li>',
                $activeClass,
                htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'),
                $badgeHtml
            );
        }

        return sprintf(
            '<aside class="co-admin-sidebar" aria-label="Main Navigation">
                <div class="co-sidebar-brand">
                    <span class="co-brand-name">%s</span>
                </div>
                <nav class="co-sidebar-nav">
                    <ul class="co-nav-list">%s</ul>
                </nav>
            </aside>',
            htmlspecialchars($this->activeBrand, ENT_QUOTES, 'UTF-8'),
            $itemsHtml
        );
    }

    private function renderHeader(?string $currentAdminName): string
    {
        $adminDisplay = $currentAdminName ?? 'Administrator';

        return sprintf(
            '<header class="co-admin-header">
                <div class="co-header-search">
                    <button type="button" class="co-ctrl-k-btn" aria-label="Global Search">
                        <span class="co-ctrl-k-label">Search or jump to...</span>
                        <kbd class="co-kbd">Ctrl+K</kbd>
                    </button>
                </div>
                <div class="co-header-actions">
                    <button type="button" class="co-action-center-toggle" aria-label="Open Action Center" aria-expanded="false">
                        <span class="co-icon" aria-hidden="true">🔔</span>
                        %s
                    </button>
                    <div class="co-admin-profile">
                        <span class="co-admin-name">%s</span>
                    </div>
                </div>
            </header>',
            count($this->actionCenterItems) > 0 ? sprintf('<span class="co-notification-dot" aria-hidden="true">%d</span>', count($this->actionCenterItems)) : '',
            htmlspecialchars($adminDisplay, ENT_QUOTES, 'UTF-8')
        );
    }

    private function renderActionCenter(): string
    {
        $cardsHtml = '';
        if (empty($this->actionCenterItems)) {
            $cardsHtml = '<p class="co-empty-state">No pending operational actions.</p>';
        } else {
            foreach ($this->actionCenterItems as $item) {
                $cardsHtml .= sprintf(
                    '<div class="co-action-card co-action-card-%s">
                        <div class="co-action-title">%s</div>
                        <div class="co-action-message">%s</div>
                        <div class="co-action-time">%s</div>
                    </div>',
                    htmlspecialchars($item['priority'] ?? 'info', ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return sprintf(
            '<aside id="action-center" class="co-action-center" aria-label="Operational Action Center" hidden>
                <div class="co-action-center-header">
                    <h2>Action Center</h2>
                </div>
                <div class="co-action-center-body">
                    %s
                </div>
            </aside>',
            $cardsHtml
        );
    }
}
