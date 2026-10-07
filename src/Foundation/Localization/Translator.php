<?php

declare(strict_types=1);

namespace Coleza\Foundation\Localization;

use InvalidArgumentException;

final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $translations = [];
    private string $defaultLocale = 'tr_TR';
    private string $fallbackLocale = 'en_US';

    public function __construct(
        private string $resourcesPath,
        string $defaultLocale = 'tr_TR'
    ) {
        $this->defaultLocale = $defaultLocale;
    }

    public function loadLocale(string $locale): void
    {
        if (isset($this->translations[$locale])) {
            return;
        }

        $file = sprintf('%s/%s.php', rtrim($this->resourcesPath, '/\\'), $locale);
        if (file_exists($file)) {
            $data = require $file;
            if (is_array($data)) {
                $this->translations[$locale] = $data;
                return;
            }
        }

        $this->translations[$locale] = [];
    }

    /**
     * Resolve hierarchical locale: User -> Organization -> Brand -> System.
     */
    public function resolveLocale(?string $userLocale, ?string $orgLocale, ?string $brandLocale): string
    {
        if (!empty($userLocale)) {
            return $userLocale;
        }
        if (!empty($orgLocale)) {
            return $orgLocale;
        }
        if (!empty($brandLocale)) {
            return $brandLocale;
        }
        return $this->defaultLocale;
    }

    /**
     * Translate key with optional parameter substitution.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function get(string $key, array $parameters = [], ?string $locale = null): string
    {
        $targetLocale = $locale ?? $this->defaultLocale;
        $this->loadLocale($targetLocale);

        $line = $this->translations[$targetLocale][$key] ?? null;

        if ($line === null && $targetLocale !== $this->fallbackLocale) {
            $this->loadLocale($this->fallbackLocale);
            $line = $this->translations[$this->fallbackLocale][$key] ?? null;
        }

        if ($line === null) {
            return $key;
        }

        foreach ($parameters as $paramKey => $val) {
            $line = str_replace(':' . $paramKey, (string) $val, $line);
        }

        return $line;
    }

    /**
     * Verify 100% key and placeholder parity between two locales.
     *
     * @return array{
     *     missing_in_secondary: array<int, string>,
     *     missing_in_primary: array<int, string>,
     *     mismatched_placeholders: array<string, array{primary: array<int, string>, secondary: array<int, string>}>
     * }
     */
    public function verifyParity(string $primaryLocale = 'tr_TR', string $secondaryLocale = 'en_US'): array
    {
        $this->loadLocale($primaryLocale);
        $this->loadLocale($secondaryLocale);

        $primaryKeys = array_keys($this->translations[$primaryLocale] ?? []);
        $secondaryKeys = array_keys($this->translations[$secondaryLocale] ?? []);

        $missingInSecondary = array_values(array_diff($primaryKeys, $secondaryKeys));
        $missingInPrimary = array_values(array_diff($secondaryKeys, $primaryKeys));

        $mismatchedPlaceholders = [];
        $commonKeys = array_intersect($primaryKeys, $secondaryKeys);

        foreach ($commonKeys as $k) {
            $str1 = $this->translations[$primaryLocale][$k];
            $str2 = $this->translations[$secondaryLocale][$k];

            preg_match_all('/:([a-zA-Z0-9_]+)/', $str1, $matches1);
            preg_match_all('/:([a-zA-Z0-9_]+)/', $str2, $matches2);

            $placeholders1 = array_unique($matches1[1]);
            $placeholders2 = array_unique($matches2[1]);
            sort($placeholders1);
            sort($placeholders2);

            if ($placeholders1 !== $placeholders2) {
                $mismatchedPlaceholders[$k] = [
                    'primary' => $placeholders1,
                    'secondary' => $placeholders2,
                ];
            }
        }

        return [
            'missing_in_secondary' => $missingInSecondary,
            'missing_in_primary' => $missingInPrimary,
            'mismatched_placeholders' => $mismatchedPlaceholders,
        ];
    }
}
