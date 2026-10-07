<?php

declare(strict_types=1);

namespace Coleza\Ui\Patterns;

final class Modal
{
    /**
     * Render an accessible dialog modal for small atomic actions.
     * In accordance with UI Constitution: "Small action=modal".
     * Supports typed confirmation for destructive actions.
     */
    public static function render(
        string $id,
        string $title,
        string $bodyHtml,
        string $confirmLabel = 'Confirm',
        string $confirmVariant = 'primary',
        ?string $typedConfirmationText = null
    ): string {
        $typedInputHtml = '';
        if ($typedConfirmationText !== null) {
            $typedInputHtml = sprintf(
                '<div class="co-modal-typed-confirm">
                    <label for="%s_typed" class="co-form-label">Type <strong>%s</strong> to confirm:</label>
                    <input id="%s_typed" type="text" class="co-input" data-expected="%s" autocomplete="off" required/>
                </div>',
                $id,
                htmlspecialchars($typedConfirmationText, ENT_QUOTES, 'UTF-8'),
                $id,
                htmlspecialchars($typedConfirmationText, ENT_QUOTES, 'UTF-8')
            );
        }

        return sprintf(
            '<div id="%s" class="co-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="%s_title" hidden>
                <div class="co-modal-dialog">
                    <div class="co-modal-header">
                        <h2 id="%s_title" class="co-modal-title">%s</h2>
                        <button type="button" class="co-modal-close" aria-label="Close dialog">&times;</button>
                    </div>
                    <div class="co-modal-body">
                        %s
                        %s
                    </div>
                    <div class="co-modal-footer">
                        <button type="button" class="co-btn co-btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" class="co-btn co-btn-%s" id="%s_confirm_btn">%s</button>
                    </div>
                </div>
            </div>',
            $id,
            $id,
            $id,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            $bodyHtml,
            $typedInputHtml,
            htmlspecialchars($confirmVariant, ENT_QUOTES, 'UTF-8'),
            $id,
            htmlspecialchars($confirmLabel, ENT_QUOTES, 'UTF-8')
        );
    }
}
