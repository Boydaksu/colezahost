<?php
declare(strict_types=1);
namespace Coleza\Foundation\Security;

/** Diagnostic/export boundary only: never apply to operational payloads or authentication inputs. */
final class SecretRedactor
{
    public const MASK = '********';
    private const MAX_DEPTH = 24;
    private const WORDS = ['password', 'passwd', 'passphrase', 'secret', 'token', 'credential', 'apikey', 'appkey', 'applicationkey',
        'privatekey', 'signingkey', 'encryptionkey', 'authorization', 'authheader', 'cookie', 'creditcard', 'cardnumber',
        'securitycode', 'eppcode', 'authcode', 'recoverycode', 'otpcode', 'totpcode', 'encryptedvalue', 'sessionid', 'accesshash', 'sshkey', 'clientkey', 'csrf', 'xsrf', 'oauthstate'];

    public static function sensitive(string $name): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?? '');
        $parts = preg_split('/[^a-zA-Z0-9]+/', strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? '')) ?: [];
        if (array_intersect($parts, ['pass', 'pwd', 'pan', 'cvv', 'cvc', 'otp', 'totp', 'epp', 'sid'])) { return true; }
        if (in_array($normalized, ['key', 'card', 'pan', 'cvv', 'cvc', 'otp', 'totp', 'pin', 'epp', 'sid', 'bindings'], true)) { return true; }
        foreach (self::WORDS as $word) { if (str_contains($normalized, $word)) { return true; } }
        return false;
    }

    public static function sanitize(mixed $value): mixed { return self::walk($value, 0); }

    /** Removes known sensitive values echoed in sibling diagnostic fields too. */
    public static function diagnostic(mixed $value, array $context = []): mixed
    {
        $context = array_merge($context, is_array($value) ? $value : []);
        $safe = self::sanitize($value);
        $scrub = function (mixed $item) use (&$scrub, $context): mixed {
            if (is_array($item)) { return array_map($scrub, $item); }
            return is_string($item) ? self::message($item, $context) : $item;
        };
        return $scrub($safe);
    }

    private static function walk(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) { return self::MASK; }
        if (is_array($value)) {
            $record = false;
            foreach (['setting_key', 'name'] as $field) {
                if (isset($value[$field]) && is_string($value[$field]) && self::sensitive($value[$field])) { $record = true; }
            }
            // Generic schema entries use key/value instead of setting_key/setting_value.
            if (isset($value['key'], $value['value']) && is_string($value['key']) && self::sensitive($value['key'])) { $record = true; }
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[$key] = self::sensitive((string) $key) || ($record && in_array($key, ['value', 'setting_value', 'default'], true))
                    ? self::MASK : self::walk($item, $depth + 1);
            }
            return $safe;
        }
        if (is_int($value) && preg_match('/^[0-9]{13,19}$/D', (string) $value)) { return self::MASK; }
        // Never execute arbitrary JsonSerializable/Stringable hooks or expose public properties/exception traces.
        if (is_object($value)) { return $value instanceof \Throwable ? '[exception]' : '[object]'; }
        if (is_resource($value)) { return '[resource]'; }
        return is_string($value) ? self::text($value, $depth + 1) : $value;
    }

    public static function text(string $text, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) { return self::MASK; }
        $trimmed = trim($text);
        if (str_starts_with($trimmed, '{') || (str_starts_with($trimmed, '[') && preg_match('/^\[\s*(?:["{\[0-9-]|true\b|false\b|null\b|\])/', $trimmed))) {
            try {
                $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) { return json_encode(self::walk($decoded, $depth + 1), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES); }
            } catch (\JsonException) { return self::MASK; }
        }
        // Embedded JSON must be decoded too: regex cannot recognize escaped field names.
        if (preg_match('/[\{\[]\s*(?:["{\[0-9-]|true\b|false\b|null\b)/', $text, $fragment, PREG_OFFSET_CAPTURE) && $fragment[0][1] > 0) {
            $offset = $fragment[0][1];
            $tail = substr($text, $offset); $end = self::jsonFragmentEnd($tail);
            $prefix = self::text(substr($text, 0, $offset), $depth + 1);
            if ($end === null) { return $prefix . self::MASK; }
            return $prefix . self::text(substr($tail, 0, $end), $depth + 1) . self::text(substr($tail, $end), $depth + 1);
        }
        $text = preg_replace_callback('/([?&])([^?&#=\s]+)=([^&#\s]*)/', static function (array $match): string {
            $name = rawurldecode(rawurldecode($match[2]));
            return $match[1] . $match[2] . '=' . (self::sensitive($name) || in_array(strtolower($name), ['code', 'state'], true) ? self::MASK : $match[3]);
        }, $text) ?? self::MASK;
        $text = preg_replace('~\b([a-z][a-z0-9+.-]*://)[^\s/?#@]+@~i', '$1' . self::MASK . '@', $text) ?? self::MASK;
        $text = preg_replace('/\b(?:Bearer|Basic)\s+[^\s,;]+/i', self::MASK, $text) ?? self::MASK;
        $labels = 'password(?:[_. -]?hash)?|passwd|passphrase|pass|pwd|(?:client[_. -]?)?secret|(?:access[_. -]?|refresh[_. -]?|api[_. -]?)?token|(?:api|app|application|private|signing|encryption|client|ssh)[_. -]?key|access[_. -]?hash|csrf|xsrf|authorization|(?:set[_. -]?)?cookie|card[_. -]?number|cvv|cvc|pan|epp(?:[_. -]?code)?|auth[_. -]?code|recovery[_. -]?code|otp(?:[_. -]?code)?|totp(?:[_. -]?code)?';
        $pattern = '~(\b(?:' . $labels . ')\b["\']?\s*[:=]\s*)(?:"(?:\\\\.|[^"\\\\\r\n])*"|\'(?:\\\\.|[^\'\\\\\r\n])*\'|[^\s&;,}\r\n]+)~i';
        $text = preg_replace($pattern, '$1' . self::MASK, $text) ?? self::MASK;
        $text = preg_replace('~\b(?:enc:v[0-9]+:|vault:v[0-9]+:|base64:)[a-zA-Z0-9+/=_:.-]+~', self::MASK, $text) ?? self::MASK;
        $text = preg_replace('~-----BEGIN (?:(?:RSA|EC|DSA|OPENSSH|ENCRYPTED) )?PRIVATE KEY-----[\s\S]*?(?:-----END (?:(?:RSA|EC|DSA|OPENSSH|ENCRYPTED) )?PRIVATE KEY-----|$)~', self::MASK, $text) ?? self::MASK;
        $text = preg_replace('~\beyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\b~', self::MASK, $text) ?? self::MASK;
        return preg_replace('/(?<![a-zA-Z0-9])(?:[0-9][ -]?){12,18}[0-9](?![a-zA-Z0-9])/', self::MASK, $text) ?? self::MASK;
    }

    /** Find only the balanced JSON fragment, preserving ordinary diagnostic suffixes. */
    private static function jsonFragmentEnd(string $text): ?int
    {
        $stack=[]; $quoted=false; $escaped=false;
        for ($i=0,$length=strlen($text);$i<$length;$i++) {
            $char=$text[$i];
            if ($quoted) {
                if ($escaped) { $escaped=false; continue; }
                if ($char==='\\') { $escaped=true; continue; }
                if ($char==='"') { $quoted=false; }
                continue;
            }
            if ($char==='"') { $quoted=true; continue; }
            if ($char==='{' || $char==='[') { $stack[]=$char==='{'?'}':']'; if (count($stack)>64) { return null; } continue; }
            if ($char==='}' || $char===']') {
                if (array_pop($stack)!==$char) { return null; }
                if ($stack===[]) { return $i+1; }
            }
        }
        return null;
    }

    public static function message(string|\Stringable $message, array $context = []): string
    {
        if ($message instanceof \Throwable) { return '[exception]'; }
        // Decode/redact labeled data before replacement can alter a field name.
        $text = self::text((string) $message);
        $secrets = []; self::secrets($context, $secrets, 0);
        $secrets = array_unique($secrets); usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($secrets as $secret) { $text = str_replace($secret, self::MASK, $text); }
        return self::text($text);
    }

    private static function secrets(mixed $value, array &$found, int $depth, bool $sensitive = false): void
    {
        if ($depth > self::MAX_DEPTH) { return; }
        if (is_array($value)) {
            $record = false;
            foreach (['setting_key', 'name', 'key'] as $field) { if (isset($value[$field]) && is_string($value[$field]) && self::sensitive($value[$field])) { $record = true; } }
            foreach ($value as $key => $item) { self::secrets($item, $found, $depth + 1, $sensitive || self::sensitive((string) $key) || ($record && in_array($key, ['value', 'setting_value', 'default'], true))); }
        } elseif (is_scalar($value) && $sensitive) {
            $value = (string) $value; if ($value !== '' && $value !== self::MASK) { $found[] = $value; }
        } elseif (is_string($value) && (str_starts_with(trim($value), '{') || str_starts_with(trim($value), '['))) {
            $decoded = json_decode($value, true, 64); if (is_array($decoded)) { self::secrets($decoded, $found, $depth + 1); }
        }
    }

    public static function route(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') { return '/'; }
        $segments = explode('/', $path); $maskNext = false;
        foreach ($segments as &$segment) {
            $marker = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', rawurldecode(rawurldecode($segment))) ?? '');
            if ($maskNext && $segment !== '') { $segment = self::MASK; $maskNext = false; continue; }
            $maskNext = in_array($marker, ['passwordreset', 'resetpassword', 'reset', 'verifyemail', 'emailverification', 'verification', 'recovery', 'otp', 'tokens', 'token'], true);
        }
        unset($segment);
        return self::text(implode('/', $segments));
    }
}
