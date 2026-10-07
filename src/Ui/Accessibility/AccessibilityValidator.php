<?php

declare(strict_types=1);

namespace Coleza\Ui\Accessibility;

final class AccessibilityValidator
{
    /**
     * Inspect HTML snippet for standard WCAG / Coleza UI Constitution rules.
     *
     * @return array<int, string> List of accessibility violations
     */
    public static function validate(string $html): array
    {
        $violations = [];

        // Rule 1: Form controls must have associated label or aria-label
        if (preg_match_all('/<input\b(?![^>]*\btype=["\'](?:hidden|submit|button|reset)["\'])[^>]*>/i', $html, $inputs)) {
            foreach ($inputs[0] as $inputTag) {
                $hasAriaLabel = preg_match('/\b(aria-label|aria-labelledby)=/i', $inputTag);
                $hasId = preg_match('/\bid=["\']([^"\']+)["\']/i', $inputTag, $idMatch);

                $labelAssociated = false;
                if ($hasId) {
                    $id = preg_quote($idMatch[1], '/');
                    if (preg_match('/<label\b[^>]*\bfor=["\']' . $id . '["\']/i', $html)) {
                        $labelAssociated = true;
                    }
                }

                if (!$hasAriaLabel && !$labelAssociated) {
                    $violations[] = sprintf('Input tag [%s] lacks an accessible label or associated <label for="...">.', htmlspecialchars($inputTag));
                }
            }
        }

        // Rule 2: Dialogs must have role="dialog" and aria-labelledby or aria-label
        if (preg_match_all('/<div\b[^>]*\bclass=["\'][^"\']*modal-(?:backdrop|dialog)[^"\']*["\'][^>]*>/i', $html, $modals)) {
            // Check if any container in the snippet provides role="dialog" and aria label
            $hasRoleDialog = (bool) preg_match('/\brole=["\']dialog["\']/i', $html);
            $hasAriaLabel = (bool) preg_match('/\b(aria-label|aria-labelledby)=/i', $html);

            if (!$hasRoleDialog) {
                $violations[] = 'Modal container is missing role="dialog".';
            }
            if (!$hasAriaLabel) {
                $violations[] = 'Modal dialog is missing aria-label or aria-labelledby.';
            }
        }

        // Rule 3: Images must have alt attribute
        if (preg_match_all('/<img\b(?![^>]*\balt=)[^>]*>/i', $html, $imgs)) {
            foreach ($imgs[0] as $imgTag) {
                $violations[] = sprintf('Image tag [%s] is missing an alt attribute.', htmlspecialchars($imgTag));
            }
        }

        // Rule 4: Buttons must have non-empty text content or aria-label
        if (preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $html, $buttons, PREG_SET_ORDER)) {
            foreach ($buttons as $btn) {
                $attr = $btn[1];
                $content = trim(strip_tags($btn[2]));
                $hasAria = (bool) preg_match('/\baria-label=["\'][^"\']+["\']/i', $attr);

                if ($content === '' && !$hasAria) {
                    $violations[] = sprintf('Button tag [%s] has no readable text content and lacks aria-label.', htmlspecialchars($btn[0]));
                }
            }
        }

        return $violations;
    }

    /**
     * Calculate WCAG 2.1 contrast ratio between two hex colors.
     * Returns ratio (e.g. 4.5, 7.1).
     */
    public static function contrastRatio(string $hex1, string $hex2): float
    {
        $l1 = self::relativeLuminance($hex1);
        $l2 = self::relativeLuminance($hex2);

        $brightest = max($l1, $l2);
        $darkest = min($l1, $l2);

        return round(($brightest + 0.05) / ($darkest + 0.05), 2);
    }

    private static function relativeLuminance(string $hex): float
    {
        $clean = ltrim($hex, '#');
        if (strlen($clean) === 3) {
            $clean = $clean[0] . $clean[0] . $clean[1] . $clean[1] . $clean[2] . $clean[2];
        }

        $r = hexdec(substr($clean, 0, 2)) / 255.0;
        $g = hexdec(substr($clean, 2, 2)) / 255.0;
        $b = hexdec(substr($clean, 4, 2)) / 255.0;

        $r = ($r <= 0.03928) ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = ($g <= 0.03928) ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = ($b <= 0.03928) ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

        return (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    }
}
