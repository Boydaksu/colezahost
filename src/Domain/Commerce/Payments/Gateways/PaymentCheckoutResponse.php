<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentCheckoutResponse
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        private bool $success,
        private ?string $token = null,
        private ?string $checkoutFormContent = null,
        private ?string $checkoutUrl = null,
        private ?string $errorMessage = null,
        private array $rawPayload = []
    ) {
    }

    public static function successful(
        string $token,
        ?string $checkoutFormContent = null,
        ?string $checkoutUrl = null,
        array $rawPayload = []
    ): self {
        return new self(
            success: true,
            token: $token,
            checkoutFormContent: $checkoutFormContent,
            checkoutUrl: $checkoutUrl,
            errorMessage: null,
            rawPayload: $rawPayload
        );
    }

    public static function failure(string $errorMessage, array $rawPayload = []): self
    {
        return new self(
            success: false,
            token: null,
            checkoutFormContent: null,
            checkoutUrl: null,
            errorMessage: $errorMessage,
            rawPayload: $rawPayload
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getCheckoutFormContent(): ?string
    {
        return $this->checkoutFormContent;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }
}
