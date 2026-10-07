<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentVerificationRequest
{
    /**
     * @param array<string, mixed> $rawCallbackData
     */
    public function __construct(
        private string $token,
        private array $rawCallbackData = []
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawCallbackData(): array
    {
        return $this->rawCallbackData;
    }
}
