<?php

declare(strict_types=1);

namespace Coleza\Ui\Client;

final class ClientShell
{
    /**
     * @param array<int, array{label: string, url: string, active?: bool}> $primaryNav
     * @param array<int, array{id: int, name: string}> $organizations
     */
    public function __construct(
        private string $title = 'Client Portal',
        private string $brandName = 'Coleza Host',
        private string $theme = 'light',
        private array $primaryNav = [],
        private ?string $currentUserName = null,
        private ?string $activeOrganizationName = null,
        private array $organizations = []
    ) {
    }

    /**
     * Render complete Client Layout Shell.
     */
    public function render(string $contentHtml): string
    {
        $themeAttr = sprintf('data-theme="%s"', htmlspecialchars($this->theme, ENT_QUOTES, 'UTF-8'));
        $navHtml = $this->renderNav();
        $userSectionHtml = $this->renderUserSection();

        return sprintf(
            '<!DOCTYPE html>
<html lang="tr" %s>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>%s - %s</title>
    <link rel="stylesheet" href="/assets/tokens.css">
    <link rel="stylesheet" href="/assets/client.css">
</head>
<body class="co-client-body">
    <header class="co-client-header">
        <div class="co-client-header-container">
            <div class="co-client-brand">
                <a href="/client" class="co-brand-link">
                    <span class="co-brand-logo">%s</span>
                </a>
            </div>
            <nav class="co-client-nav" aria-label="Client Portal Navigation">
                <ul class="co-client-nav-list">%s</ul>
            </nav>
            <div class="co-client-user-menu">
                %s
            </div>
        </div>
    </header>
    <main id="main-content" class="co-client-main" tabindex="-1">
        <div class="co-client-container">
            %s
        </div>
    </main>
    <footer class="co-client-footer">
        <div class="co-client-container">
            <p>&copy; %s %s. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>',
            $themeAttr,
            htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->brandName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->brandName, ENT_QUOTES, 'UTF-8'),
            $navHtml,
            $userSectionHtml,
            $contentHtml,
            date('Y'),
            htmlspecialchars($this->brandName, ENT_QUOTES, 'UTF-8')
        );
    }

    private function renderNav(): string
    {
        $html = '';
        foreach ($this->primaryNav as $item) {
            $activeClass = !empty($item['active']) ? ' co-nav-item-active' : '';
            $ariaCurrent = !empty($item['active']) ? ' aria-current="page"' : '';

            $html .= sprintf(
                '<li class="co-nav-item%s">
                    <a href="%s" class="co-nav-link"%s>%s</a>
                </li>',
                $activeClass,
                htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'),
                $ariaCurrent,
                htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8')
            );
        }
        return $html;
    }

    private function renderUserSection(): string
    {
        if ($this->currentUserName === null) {
            return '<a href="/login" class="co-btn co-btn-primary">Sign In</a>';
        }

        $orgSwitcherHtml = '';
        if (!empty($this->organizations)) {
            $optionsHtml = '';
            foreach ($this->organizations as $org) {
                $selected = ($org['name'] === $this->activeOrganizationName) ? ' selected' : '';
                $optionsHtml .= sprintf(
                    '<option value="%d"%s>%s</option>',
                    $org['id'],
                    $selected,
                    htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8')
                );
            }

            $orgSwitcherHtml = sprintf(
                '<div class="co-org-switcher">
                    <label for="org_select" class="visually-hidden">Active Organization</label>
                    <select id="org_select" name="active_organization_id" class="co-select-org">
                        %s
                    </select>
                </div>',
                $optionsHtml
            );
        }

        return sprintf(
            '<div class="co-user-profile">
                %s
                <span class="co-user-name">%s</span>
                <a href="/logout" class="co-logout-link">Sign Out</a>
            </div>',
            $orgSwitcherHtml,
            htmlspecialchars($this->currentUserName, ENT_QUOTES, 'UTF-8')
        );
    }
}
