<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Risk;

final class RiskContext
{
    /**
     * @param array<string, mixed> $customAttributes
     */
    public function __construct(
        private readonly string $entityType,
        private readonly ?int $entityId = null,
        private readonly ?int $userId = null,
        private readonly ?int $organizationId = null,
        private readonly ?string $email = null,
        private readonly ?string $clientIp = null,
        private readonly ?string $userAgent = null,
        private readonly ?string $billingCountry = null,
        private readonly ?string $geoIpCountry = null,
        private readonly bool $isProxyOrVpn = false,
        private readonly bool $isTor = false,
        private readonly ?string $paymentMethod = null,
        private readonly ?string $cardIssuingCountry = null,
        private readonly bool $cardIsPrepaid = false,
        private readonly float $orderAmount = 0.0,
        private readonly string $currency = 'USD',
        private readonly int $orderItemCount = 1,
        private readonly bool $isNewCustomer = true,
        private readonly int $priorSuccessfulOrdersCount = 0,
        private readonly array $customAttributes = []
    ) {
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getBillingCountry(): ?string
    {
        return $this->billingCountry;
    }

    public function getGeoIpCountry(): ?string
    {
        return $this->geoIpCountry;
    }

    public function isProxyOrVpn(): bool
    {
        return $this->isProxyOrVpn;
    }

    public function isTor(): bool
    {
        return $this->isTor;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function getCardIssuingCountry(): ?string
    {
        return $this->cardIssuingCountry;
    }

    public function isCardPrepaid(): bool
    {
        return $this->cardIsPrepaid;
    }

    public function getOrderAmount(): float
    {
        return $this->orderAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getOrderItemCount(): int
    {
        return $this->orderItemCount;
    }

    public function isNewCustomer(): bool
    {
        return $this->isNewCustomer;
    }

    public function getPriorSuccessfulOrdersCount(): int
    {
        return $this->priorSuccessfulOrdersCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomAttributes(): array
    {
        return $this->customAttributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'email' => $this->email,
            'client_ip' => $this->clientIp,
            'user_agent' => $this->userAgent,
            'billing_country' => $this->billingCountry,
            'geo_ip_country' => $this->geoIpCountry,
            'is_proxy_or_vpn' => $this->isProxyOrVpn,
            'is_tor' => $this->isTor,
            'payment_method' => $this->paymentMethod,
            'card_issuing_country' => $this->cardIssuingCountry,
            'card_is_prepaid' => $this->cardIsPrepaid,
            'order_amount' => $this->orderAmount,
            'currency' => $this->currency,
            'order_item_count' => $this->orderItemCount,
            'is_new_customer' => $this->isNewCustomer,
            'prior_successful_orders_count' => $this->priorSuccessfulOrdersCount,
            'custom_attributes' => $this->customAttributes,
        ];
    }
}
