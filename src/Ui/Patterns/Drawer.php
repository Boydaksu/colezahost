<?php

declare(strict_types=1);

namespace Coleza\Ui\Patterns;

final class Drawer
{
    /**
     * Render an accessible slide-over drawer for contextual detail.
     * In accordance with UI Constitution: "Contextual detail=drawer".
     */
    public static function render(
        string $id,
        string $title,
        string $bodyHtml,
        string $position = 'right'
    ): string {
        return sprintf(
            '<div id="%s" class="co-drawer co-drawer-%s" role="region" aria-label="%s" hidden>
                <div class="co-drawer-header">
                    <h3 class="co-drawer-title">%s</h3>
                    <button type="button" class="co-drawer-close" aria-label="Close panel">&times;</button>
                </div>
                <div class="co-drawer-body">
                    %s
                </div>
            </div>',
            $id,
            htmlspecialchars($position, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            $bodyHtml
        );
    }
}
