<?php

declare(strict_types=1);

namespace Coleza\Foundation\Telemetry;

use Coleza\Foundation\Http\Request;

final class CorrelationContext
{
    private static ?self $current = null;

    public function __construct(
        private string $requestId,
        private string $correlationId,
        private ?string $operationId = null,
        private ?string $userId = null,
        private ?string $orgId = null
    ) {
    }

    public static function current(): self
    {
        if (self::$current === null) {
            self::$current = self::generate();
        }

        return self::$current;
    }

    public static function setCurrent(?self $context): void
    {
        self::$current = $context;
    }

    public static function generate(?string $correlationId = null): self
    {
        $reqId = self::generateUuid();
        $corrId = $correlationId ?: $reqId;

        return new self(
            requestId: $reqId,
            correlationId: $corrId
        );
    }

    public static function fromRequest(Request $request): self
    {
        $reqId = $request->getHeader('x-request-id') ?: self::generateUuid();
        $corrId = $request->getHeader('x-correlation-id') ?: $reqId;

        return new self(
            requestId: $reqId,
            correlationId: $corrId
        );
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getOperationId(): ?string
    {
        return $this->operationId;
    }

    public function setOperationId(?string $operationId): self
    {
        $this->operationId = $operationId;
        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getOrgId(): ?string
    {
        return $this->orgId;
    }

    public function setOrgId(?string $orgId): self
    {
        $this->orgId = $orgId;
        return $this;
    }

    /**
     * @return array{request_id: string, correlation_id: string, operation_id: ?string, user_id: ?string, org_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'operation_id' => $this->operationId,
            'user_id' => $this->userId,
            'org_id' => $this->orgId,
        ];
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // Version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
