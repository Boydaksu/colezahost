<?php

declare(strict_types=1);

namespace Coleza\Api\Response;

final class ApiResponse
{
    /**
     * Standardized success payload.
     *
     * @param array<string, mixed>|object $data
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function success(mixed $data = [], array $meta = [], int $status = 200): array
    {
        $response = [
            'status' => 'success',
            'code' => $status,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return $response;
    }

    /**
     * Standardized paginated success payload.
     *
     * @param array<int, mixed> $items
     * @return array<string, mixed>
     */
    public static function paginate(array $items, int $total, int $page, int $perPage): array
    {
        $totalPages = (int) ceil($total / max(1, $perPage));

        return [
            'status' => 'success',
            'code' => 200,
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
        ];
    }

    /**
     * Standardized error payload matching platform exception taxonomy.
     *
     * @param array<string, array<int, string>> $errors
     * @return array<string, mixed>
     */
    public static function error(string $message, string $errorCode = 'INTERNAL_ERROR', int $status = 500, array $errors = []): array
    {
        $payload = [
            'status' => 'error',
            'code' => $status,
            'error_code' => preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $errorCode) ? $errorCode : 'INTERNAL_ERROR',
            'message' => $status >= 500 ? 'An internal error occurred.' : \Coleza\Foundation\Security\SecretRedactor::message($message, $errors),
        ];

        if (!empty($errors)) {
            $payload['errors'] = $status >= 500 ? [] : \Coleza\Foundation\Security\SecretRedactor::diagnostic($errors);
        }

        return $payload;
    }
}
