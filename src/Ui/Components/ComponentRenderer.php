<?php

declare(strict_types=1);

namespace Coleza\Ui\Components;

final class ComponentRenderer
{
    /**
     * Render an accessible Button.
     *
     * @param array<string, string> $attributes
     */
    public static function button(string $label, string $variant = 'primary', array $attributes = []): string
    {
        $class = match ($variant) {
            'secondary' => 'co-btn co-btn-secondary',
            'danger' => 'co-btn co-btn-danger',
            'outline' => 'co-btn co-btn-outline',
            default => 'co-btn co-btn-primary',
        };

        $type = $attributes['type'] ?? 'button';
        $attrHtml = self::renderAttributes($attributes, ['type', 'class']);

        return sprintf(
            '<button type="%s" class="%s" %s>%s</button>',
            htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
            $class,
            $attrHtml,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Render an accessible Status Badge (avoids color-only indicator per UI Constitution).
     */
    public static function badge(string $label, string $status = 'neutral'): string
    {
        $icon = match ($status) {
            'success' => '✓',
            'warning' => '!',
            'danger' => '✕',
            default => '•',
        };

        $class = 'co-badge co-badge-' . $status;

        return sprintf(
            '<span class="%s" role="status"><span aria-hidden="true" class="co-badge-icon">%s</span> %s</span>',
            $class,
            $icon,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Render an accessible Form Input with label and ARIA error binding.
     */
    public static function input(string $name, string $label, string $value = '', ?string $error = null, array $attributes = []): string
    {
        $id = $attributes['id'] ?? ('input_' . $name);
        $type = $attributes['type'] ?? 'text';
        $describedBy = $error !== null ? ('aria-describedby="' . $id . '_error"') : '';
        $invalid = $error !== null ? 'aria-invalid="true"' : '';

        $errorHtml = $error !== null
            ? sprintf('<span id="%s_error" class="co-form-error" role="alert">%s</span>', $id, htmlspecialchars($error, ENT_QUOTES, 'UTF-8'))
            : '';

        $attrHtml = self::renderAttributes($attributes, ['id', 'type', 'name', 'value', 'aria-describedby', 'aria-invalid']);

        return sprintf(
            '<div class="co-form-group">
                <label for="%s" class="co-form-label">%s</label>
                <input id="%s" type="%s" name="%s" value="%s" class="co-input%s" %s %s %s/>
                %s
            </div>',
            $id,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $id,
            $type,
            $name,
            htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            $error !== null ? ' co-input-invalid' : '',
            $describedBy,
            $invalid,
            $attrHtml,
            $errorHtml
        );
    }

    /**
     * Render an accessible Alert banner.
     */
    public static function alert(string $message, string $level = 'info'): string
    {
        $role = in_array($level, ['danger', 'warning'], true) ? 'alert' : 'status';
        return sprintf(
            '<div class="co-alert co-alert-%s" role="%s">%s</div>',
            $level,
            $role,
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * @param array<string, string> $attributes
     * @param array<int, string> $exclude
     */
    private static function renderAttributes(array $attributes, array $exclude): string
    {
        $parts = [];
        foreach ($attributes as $k => $v) {
            if (!in_array($k, $exclude, true)) {
                $parts[] = sprintf('%s="%s"', htmlspecialchars($k, ENT_QUOTES, 'UTF-8'), htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
            }
        }
        return implode(' ', $parts);
    }
}
