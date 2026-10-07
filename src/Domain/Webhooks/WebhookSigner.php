<?php

declare(strict_types=1);

namespace Coleza\Domain\Webhooks;

final class WebhookSigner
{
    public const HEADER_NAME = 'X-Coleza-Signature';

    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();
        $signedPayload = "{$time}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$time},v1={$signature}";
    }

    public static function verify(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = 300
    ): bool {
        if (!preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $matches)) {
            return false;
        }

        $timestamp = (int) $matches[1];
        $signature = $matches[2];

        // Replay defense
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expectedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
