<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Unsubscribe;

final class UnsubscribeService
{
    public function __construct(
        private string $secretKey = 'coleza_default_unsubscribe_secret_change_in_prod'
    ) {
    }

    public function generateToken(int $userId, string $email, string $category = 'marketing'): string
    {
        $payload = sprintf('%d:%s:%s', $userId, strtolower(trim($email)), $category);
        $signature = hash_hmac('sha256', $payload, $this->secretKey);

        return base64_encode(json_encode([
            'u' => $userId,
            'e' => strtolower(trim($email)),
            'c' => $category,
            's' => substr($signature, 0, 32),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $token
     * @return array{userId: int, email: string, category: string}|null
     */
    public function validateAndDecode(string $token): ?array
    {
        try {
            $json = base64_decode($token, true);
            if ($json === false) {
                return null;
            }

            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($decoded['u'], $decoded['e'], $decoded['c'], $decoded['s'])) {
                return null;
            }

            $userId = (int) $decoded['u'];
            $email = (string) $decoded['e'];
            $category = (string) $decoded['c'];
            $sig = (string) $decoded['s'];

            $payload = sprintf('%d:%s:%s', $userId, $email, $category);
            $expectedSignature = substr(hash_hmac('sha256', $payload, $this->secretKey), 0, 32);

            if (!hash_equals($expectedSignature, $sig)) {
                return null;
            }

            return [
                'userId' => $userId,
                'email' => $email,
                'category' => $category,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Generates RFC 8058 compliant List-Unsubscribe headers for marketing emails.
     *
     * @param string $unsubscribeUrl
     * @return array<string, string>
     */
    public function buildListUnsubscribeHeaders(string $unsubscribeUrl): array
    {
        return [
            'List-Unsubscribe' => "<{$unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}
