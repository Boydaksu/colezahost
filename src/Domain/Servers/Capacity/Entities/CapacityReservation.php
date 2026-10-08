<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Capacity\Entities;

use DateTimeImmutable;

final class CapacityReservation
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        private ?int $id,
        private string $token,
        private int $serverId,
        private ?int $serverPoolId,
        private ?int $serviceId,
        private ?int $orderId,
        private ?int $orderItemId,
        private int $accountsCount,
        private int $diskMb,
        private int $bandwidthMb,
        private string $status,
        private ?string $releaseReason,
        private string $expiresAt,
        private ?string $committedAt = null,
        private ?string $releasedAt = null,
        private ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getServerId(): int
    {
        return $this->serverId;
    }

    public function getServerPoolId(): ?int
    {
        return $this->serverPoolId;
    }

    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getOrderItemId(): ?int
    {
        return $this->orderItemId;
    }

    public function getAccountsCount(): int
    {
        return $this->accountsCount;
    }

    public function getDiskMb(): int
    {
        return $this->diskMb;
    }

    public function getBandwidthMb(): int
    {
        return $this->bandwidthMb;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReleaseReason(): ?string
    {
        return $this->releaseReason;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }

    public function getCommittedAt(): ?string
    {
        return $this->committedAt;
    }

    public function getReleasedAt(): ?string
    {
        return $this->releasedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function hasExpired(?string $referenceTime = null): bool
    {
        $ref = new DateTimeImmutable($referenceTime ?? 'now');
        $exp = new DateTimeImmutable($this->expiresAt);
        return $ref > $exp;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'server_id' => $this->serverId,
            'server_pool_id' => $this->serverPoolId,
            'service_id' => $this->serviceId,
            'order_id' => $this->orderId,
            'order_item_id' => $this->orderItemId,
            'accounts_count' => $this->accountsCount,
            'disk_mb' => $this->diskMb,
            'bandwidth_mb' => $this->bandwidthMb,
            'status' => $this->status,
            'release_reason' => $this->releaseReason,
            'expires_at' => $this->expiresAt,
            'committed_at' => $this->committedAt,
            'released_at' => $this->releasedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
