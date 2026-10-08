<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Classification;

use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Throwable;

final class ProvisioningErrorClassifier
{
    /**
     * Classifies a provider operation result into a structured error classification.
     */
    public static function classifyResult(ProviderOperationResult $result): ProvisioningErrorClassification
    {
        return self::classify(
            message: $result->getMessage(),
            errorCode: $result->getErrorCode(),
            rawResponse: $result->getRawResponse(),
            data: $result->getData()
        );
    }

    /**
     * Classifies an arbitrary exception thrown during provisioning.
     */
    public static function classifyThrowable(Throwable $exception): ProvisioningErrorClassification
    {
        return self::classify(
            message: $exception->getMessage(),
            errorCode: (string)$exception->getCode(),
            rawResponse: ['exception' => get_class($exception), 'trace' => $exception->getTraceAsString()]
        );
    }

    /**
     * Classify an error string, error code, and HTTP status code.
     *
     * @param array<string, mixed>|null $rawResponse
     * @param array<string, mixed> $data
     */
    public static function classify(
        string $message,
        ?string $errorCode = null,
        ?int $httpStatusCode = null,
        ?array $rawResponse = null,
        array $data = []
    ): ProvisioningErrorClassification {
        $msgLower = strtolower($message);
        $code = $errorCode ?? 'PROVISIONING_FAILED';
        $details = ['message' => $message, 'raw_response' => $rawResponse, 'data' => $data];

        // 1. Rate Limiting (429 or rate limit keywords)
        if ($httpStatusCode === 429 || str_contains($msgLower, 'rate limit') || str_contains($msgLower, 'too many requests')) {
            return ProvisioningErrorClassification::rateLimited($message, 300, $details);
        }

        // 2. Authentication / Authorization (401, 403 or auth keywords)
        if (
            $httpStatusCode === 401 ||
            $httpStatusCode === 403 ||
            str_contains($msgLower, 'auth') ||
            str_contains($msgLower, 'access denied') ||
            str_contains($msgLower, 'invalid token') ||
            str_contains($msgLower, 'unauthorized') ||
            str_contains($msgLower, 'permission denied')
        ) {
            return ProvisioningErrorClassification::authentication($message, $code, $details);
        }

        // 3. Resource Conflict (409, already exists, duplicate)
        if (
            $httpStatusCode === 409 ||
            str_contains($msgLower, 'already exists') ||
            str_contains($msgLower, 'duplicate') ||
            str_contains($msgLower, 'domain in use') ||
            str_contains($msgLower, 'user exists') ||
            str_contains($msgLower, 'conflict')
        ) {
            return ProvisioningErrorClassification::conflict($message, $code, $details);
        }

        // 4. Resource Exhaustion (disk full, out of memory, quota exceeded)
        if (
            str_contains($msgLower, 'disk full') ||
            str_contains($msgLower, 'no space left') ||
            str_contains($msgLower, 'quota exceeded') ||
            str_contains($msgLower, 'out of memory') ||
            str_contains($msgLower, 'capacity reached') ||
            str_contains($msgLower, 'limit reached')
        ) {
            return ProvisioningErrorClassification::resourceExhausted($message, $code, $details);
        }

        // 5. Input Validation (400, 422, invalid parameters)
        if (
            $httpStatusCode === 400 ||
            $httpStatusCode === 422 ||
            str_contains($msgLower, 'invalid username') ||
            str_contains($msgLower, 'invalid password') ||
            str_contains($msgLower, 'validation') ||
            str_contains($msgLower, 'illegal character') ||
            str_contains($msgLower, 'bad request')
        ) {
            return ProvisioningErrorClassification::validation($message, $code, $details);
        }

        // 6. Transient Network Failures (502, 503, 504, timeout, connection drop)
        if (
            in_array($httpStatusCode, [502, 503, 504], true) ||
            str_contains($msgLower, 'timeout') ||
            str_contains($msgLower, 'timed out') ||
            str_contains($msgLower, 'connection reset') ||
            str_contains($msgLower, 'connection refused') ||
            str_contains($msgLower, 'could not resolve host') ||
            str_contains($msgLower, 'network unreachable') ||
            str_contains($msgLower, 'socket error') ||
            str_contains($msgLower, 'curl error 28') ||
            str_contains($msgLower, 'curl error 7')
        ) {
            return ProvisioningErrorClassification::transientNetwork($message, $code, 60, $details);
        }

        // 7. Provider Internal Fault (500, daemon crash, internal server error)
        if (
            $httpStatusCode === 500 ||
            str_contains($msgLower, 'internal server error') ||
            str_contains($msgLower, 'provider fault') ||
            str_contains($msgLower, 'daemon error')
        ) {
            return ProvisioningErrorClassification::providerFault($message, $code, $details);
        }

        // 8. Default to Unknown
        return ProvisioningErrorClassification::unknown($message, $code, $details);
    }
}
