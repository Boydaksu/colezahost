<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Canonical;

use JsonSerializable;

/**
 * Common contract for all canonical domain transfer objects in the migration engine.
 */
interface CanonicalEntityInterface extends JsonSerializable
{
    public function getSourceId(): string;

    public function getSourceSystem(): string;

    public function getEntityType(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
