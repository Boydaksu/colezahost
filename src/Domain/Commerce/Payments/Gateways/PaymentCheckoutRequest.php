<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments\Gateways;

final class PaymentCheckoutRequest
{
    /**
     * @param array<array{id: string, name: string, category: string, priceMinor: int}> $items
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $paymentNumber,
        private int $invoiceId,
        private int $amountMinor,
        private string $currencyCode,
        private string $callbackUrl,
        private int $buyerId,
        private string $buyerName,
        private string $buyerSurname,
        private string $buyerEmail,
        private string $buyerIp,
        private ?string $buyerGsm = null,
        private string $buyerAddress = 'Default Address',
        private string $buyerCity = 'Istanbul',
        private string $buyerCountry = 'Turkey',
        private array $items = [],
        private array $metadata = []
    ) {
    }

    public function getPaymentNumber(): string
    {
        return $this->paymentNumber;
    }

    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getAmountDecimal(): float
    {
        return $this->amountMinor / 100.0;
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    public function getBuyerId(): int
    {
        return $this->buyerId;
    }

    public function getBuyerName(): string
    {
        return $this->buyerName;
    }

    public function getBuyerSurname(): string
    {
        return $this->buyerSurname;
    }

    public function getBuyerEmail(): string
    {
        return $this->buyerEmail;
    }

    public function getBuyerIp(): string
    {
        return $this->buyerIp;
    }

    public function getBuyerGsm(): ?string
    {
        return $this->buyerGsm;
    }

    public function getBuyerAddress(): string
    {
        return $this->buyerAddress;
    }

    public function getBuyerCity(): string
    {
        return $this->buyerCity;
    }

    public function getBuyerCountry(): string
    {
        return $this->buyerCountry;
    }

    /**
     * @return array<array{id: string, name: string, category: string, priceMinor: int}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
