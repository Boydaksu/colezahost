<?php

declare(strict_types=1);
namespace Coleza\Foundation\Database;

/** Diagnostic SQL shape only: raw SQL and bindings are used exclusively by PDO. */
final class SqlDiagnostics
{
    public static function statement(string $sql): string
    {
        $escaped = self::shape($sql, true);
        $literal = self::shape($sql, false);
        // SQLite / NO_BACKSLASH_ESCAPES and default MySQL disagree about escaped quotes.
        // Omit ambiguous diagnostics instead of retaining a literal under either interpretation.
        return $escaped === $literal ? $escaped : '[SQL statement omitted: ambiguous quoting]';
    }

    private static function shape(string $sql, bool $backslashEscapes): string
    {
        $safe = ''; $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i]; $next = $sql[$i + 1] ?? '';
            if (($char === '-' && $next === '-') || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") { $i++; }
                $safe .= ' '; continue;
            }
            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i < $length && !(($sql[$i] ?? '') === '*' && ($sql[$i + 1] ?? '') === '/')) { $i++; }
                $i++; $safe .= ' '; continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                for ($i++; $i < $length; $i++) {
                    if ($backslashEscapes && $sql[$i] === '\\') { $i++; continue; }
                    if ($sql[$i] === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) { $i++; continue; }
                        break;
                    }
                }
                $safe .= "'?'"; continue;
            }
            // Numeric literals may be OTPs/PANs too; parameter values are never diagnostic data.
            if (ctype_digit($char) && ($i === 0 || !preg_match('/[a-zA-Z0-9_]/', $sql[$i - 1]))) {
                while ($i + 1 < $length && preg_match('/[a-zA-Z0-9_.+-]/', $sql[$i + 1])) { $i++; }
                $safe .= '?'; continue;
            }
            $safe .= $char;
        }
        return \Coleza\Foundation\Security\SecretRedactor::text(trim($safe));
    }
}
