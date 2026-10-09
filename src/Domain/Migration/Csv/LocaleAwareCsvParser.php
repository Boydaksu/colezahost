<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Csv;

use DateTimeImmutable;

final class LocaleAwareCsvParser
{
    /**
     * Parses CSV content into structured associative rows.
     *
     * @return array{
     *     delimiter_used: string,
     *     headers: list<string>,
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{line: int, message: string}>
     * }
     */
    public function parseString(string $csvContent, ?CsvLocaleConfig $config = null): array
    {
        $cfg = $config ?? CsvLocaleConfig::autoDetect();

        // 1. Strip UTF-8 BOM if present
        $cleanContent = $this->stripBom($csvContent);

        // 2. Determine delimiter
        $delimiter = $cfg->getDelimiter() ?? $this->detectDelimiter($cleanContent);

        // 3. Parse lines using str_getcsv
        $lines = preg_split("/\r\n|\n|\r/", $cleanContent);
        if ($lines === false || empty($lines)) {
            return [
                'delimiter_used' => $delimiter,
                'headers' => [],
                'rows' => [],
                'errors' => [['line' => 1, 'message' => 'Empty CSV content']],
            ];
        }

        $headers = [];
        $rows = [];
        $errors = [];
        $headerCount = 0;
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue; // Skip empty line
            }

            $fields = str_getcsv(
                string: $trimmedLine,
                separator: $delimiter,
                enclosure: $cfg->getEnclosure(),
                escape: $cfg->getEscape()
            );

            if ($fields === [null] || empty($fields)) {
                continue;
            }

            // First non-empty row is header
            if (empty($headers)) {
                $headers = array_map(fn ($h) => trim((string) $h), $fields);
                $headerCount = count($headers);
                continue;
            }

            // Data row
            $fieldCount = count($fields);
            if ($fieldCount !== $headerCount) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => sprintf(
                        'Column count mismatch: expected %d columns, found %d columns.',
                        $headerCount,
                        $fieldCount
                    ),
                ];
                continue;
            }

            // Build associative row with trimmed keys
            $row = [];
            foreach ($headers as $index => $headerName) {
                $row[$headerName] = trim((string) ($fields[$index] ?? ''));
            }

            $row['_line_number'] = $lineNumber;
            $rows[] = $row;
        }

        return [
            'delimiter_used' => $delimiter,
            'headers' => $headers,
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * Parses a localized numeric string (e.g., "1.250,50 €" or "$1,250.50" or "(50.00)") into a standard float.
     */
    public function parseLocalizedNumber(
        string $value,
        string $decimalSeparator = '.',
        string $thousandsSeparator = ','
    ): ?float {
        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        $isNegative = false;
        // Accounting negative notation: "(123.45)"
        if (str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
            $isNegative = true;
            $clean = substr($clean, 1, -1);
        } elseif (str_starts_with($clean, '-')) {
            $isNegative = true;
            $clean = substr($clean, 1);
        }

        // Strip non-numeric and non-separator characters (currency symbols, spaces, letters)
        // Keep digits, plus the separators
        $pattern = sprintf('/[^0-9\%s\%s]/', $decimalSeparator, $thousandsSeparator);
        $clean = preg_replace($pattern, '', $clean);
        if ($clean === null || $clean === '') {
            return null;
        }

        // Remove thousands separators
        if ($thousandsSeparator !== '') {
            $clean = str_replace($thousandsSeparator, '', $clean);
        }

        // Convert decimal separator to standard dot
        if ($decimalSeparator !== '.') {
            $clean = str_replace($decimalSeparator, '.', $clean);
        }

        if (!is_numeric($clean)) {
            return null;
        }

        $num = (float) $clean;
        return $isNegative ? -$num : $num;
    }

    /**
     * Parses a localized date string into ISO 8601 "YYYY-MM-DD".
     */
    public function parseLocalizedDate(
        string $value,
        LocaleDateFormat $preference = LocaleDateFormat::AUTO_DETECT
    ): ?string {
        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        // Strip time portion if present (e.g., "2026-10-15 14:30:00" -> "2026-10-15")
        $datePart = explode(' ', $clean)[0];
        $datePart = explode('T', $datePart)[0];

        // 1. Try ISO standard YYYY-MM-DD or YYYY/MM/DD
        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $datePart, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // 2. Delimited formats with 2-part or 3-part (DD/MM/YYYY or MM/DD/YYYY)
        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{2,4})$/', $datePart, $matches)) {
            $p1 = (int) $matches[1];
            $p2 = (int) $matches[2];
            $year = (int) $matches[3];

            // Normalize 2-digit years
            if ($year < 100) {
                $year += ($year > 50 ? 1900 : 2000);
            }

            // Disambiguation
            if ($p1 > 12) {
                // p1 cannot be month -> must be Day (DMY)
                $day = $p1;
                $month = $p2;
            } elseif ($p2 > 12) {
                // p2 cannot be month -> must be Day (MDY)
                $day = $p2;
                $month = $p1;
            } elseif ($preference === LocaleDateFormat::DMY) {
                $day = $p1;
                $month = $p2;
            } elseif ($preference === LocaleDateFormat::MDY) {
                $month = $p1;
                $day = $p2;
            } else {
                // Default fallback: DMY (standard international)
                $day = $p1;
                $month = $p2;
            }

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // 3. Fallback to PHP's DateTimeImmutable
        try {
            $dt = new DateTimeImmutable($clean);
            return $dt->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Auto-detects delimiter by frequency analysis across the first rows.
     */
    public function detectDelimiter(string $content): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if ($lines === false || empty($lines)) {
            return ',';
        }

        // Take up to 5 non-empty lines for sample
        $sampleLines = [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $sampleLines[] = $line;
                if (count($sampleLines) >= 5) {
                    break;
                }
            }
        }

        if (empty($sampleLines)) {
            return ',';
        }

        $scores = array_fill_keys($delimiters, 0);
        foreach ($sampleLines as $line) {
            foreach ($delimiters as $delim) {
                $scores[$delim] += substr_count($line, $delim);
            }
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? (string) $best : ',';
    }

    /**
     * Strips UTF-8 BOM (\xEF\xBB\xBF) if present.
     */
    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        return $content;
    }
}
