<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Operations;

use Coleza\Domain\Providers\DTO\ServerConnectionDto;

interface OperationInterface
{
    public function getServiceId(): int;

    public function getOperationType(): string;

    public function getServer(): ?ServerConnectionDto;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
