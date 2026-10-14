<?php
declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;
final class PrivacySignature
{
    public const PREFIX = 'hmac:v2:';
    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) { throw new \InvalidArgumentException('Privacy record exceeds nesting limit.'); }
        if (is_array($value)) {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as &$item) { $item = self::canonical($item, $depth + 1); } unset($item);
        } elseif ($value !== null && !is_scalar($value)) { throw new \InvalidArgumentException('Privacy integrity accepts data only.'); }
        return $value;
    }
    public static function encode(array $data): string
    {
        return json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public static function sign(string $purpose, array $data, #[\SensitiveParameter] string $key): string
    {
        $mac = hash_hmac('sha256', self::encode(['purpose' => $purpose . ':v2', 'data' => $data]), PrivacySigningKey::raw($key), true);
        return self::PREFIX . rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
    public static function verify(string $purpose, array $data, string $signature, #[\SensitiveParameter] string $key): bool
    {
        return preg_match('/^hmac:v2:[A-Za-z0-9_-]{43}$/D', $signature) === 1 && hash_equals(self::sign($purpose, $data, $key), $signature);
    }
}
