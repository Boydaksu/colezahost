<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Catalog;

final class TldPolicy
{
    /**
     * Validate a full domain name syntax against TLD rules and policy.
     *
     * @param array<string, mixed> $additionalData
     */
    public static function validate(
        string $sld,
        Tld $tld,
        int $years = 1,
        array $additionalData = []
    ): DomainValidationResult {
        $errors = [];
        $sldClean = strtolower(trim($sld));
        $extClean = $tld->getExtension();
        $fullDomain = $sldClean . $extClean;

        // 1. Overall length check
        if (strlen($fullDomain) > 253) {
            $errors[] = 'Domain name cannot exceed 253 characters.';
        }

        // 2. SLD length check
        if (strlen($sldClean) < 1 || strlen($sldClean) > 63) {
            $errors[] = 'Second-level domain label must be between 1 and 63 characters.';
        }

        // 3. Leading/trailing hyphen check
        if (str_starts_with($sldClean, '-') || str_ends_with($sldClean, '-')) {
            $errors[] = 'Domain label cannot start or end with a hyphen.';
        }

        // 4. Character set check
        $isIdn = str_starts_with($sldClean, 'xn--') || !mb_check_encoding($sldClean, 'ASCII');
        if ($isIdn && !$tld->isIdnSupported()) {
            $errors[] = "Internationalized Domain Names (IDN) are not supported for {$extClean}.";
        } elseif (!$isIdn) {
            if (!preg_match('/^[a-z0-9-]+$/', $sldClean)) {
                $errors[] = 'Domain label contains invalid characters. Only alphanumeric characters and hyphens are permitted.';
            }

            // Reject double hyphens in position 3-4 if not IDN
            if (strlen($sldClean) >= 4 && substr($sldClean, 2, 2) === '--') {
                $errors[] = 'Domain label cannot contain consecutive hyphens at position 3 and 4 unless it is an IDN punycode.';
            }
        }

        // 5. Registration years range
        if (!$tld->supportsYears($years)) {
            $errors[] = sprintf(
                'Registration period of %d years is not supported for %s (must be between %d and %d years).',
                $years,
                $extClean,
                $tld->getMinYears(),
                $tld->getMaxYears()
            );
        }

        // 6. Additional required fields validation
        $requiredFields = $tld->getAdditionalFields()['required'] ?? [];
        if (is_array($requiredFields)) {
            foreach ($requiredFields as $field) {
                if (!isset($additionalData[$field]) || trim((string) $additionalData[$field]) === '') {
                    $errors[] = "Missing required TLD field '{$field}' for {$extClean}.";
                }
            }
        }

        if (!empty($errors)) {
            return DomainValidationResult::failed($fullDomain, $errors, $sldClean, $extClean);
        }

        return DomainValidationResult::success($fullDomain, $sldClean, $extClean);
    }
}
