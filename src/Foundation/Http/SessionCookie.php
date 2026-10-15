<?php
declare(strict_types=1);
namespace Coleza\Foundation\Http;
/** Host-only secure cookie policy for the interactive session, including logout. */
final class SessionCookie
{
    public static function issue(string $sessionId): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$sessionId)) { throw new \InvalidArgumentException('Invalid session cookie.'); }
        return '__Host-coleza_session=' . $sessionId . '; Path=/; Secure; HttpOnly; SameSite=Lax';
    }
    public static function expire(): string { return '__Host-coleza_session=; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=0'; }
}
