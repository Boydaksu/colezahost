<?php

declare(strict_types=1);

namespace Coleza\Domain\Privacy\Export;

interface PrivacyDataCollectorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function collectUserData(int $userId): array;
}
