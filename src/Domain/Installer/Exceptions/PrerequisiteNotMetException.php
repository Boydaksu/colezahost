<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer\Exceptions;

use RuntimeException;

final class PrerequisiteNotMetException extends RuntimeException
{
    /**
     * @param list<string> $unmetRequirements
     */
    public static function withUnmet(array $unmetRequirements): self
    {
        return new self(sprintf(
            'System prerequisites not satisfied: %s. Installation cannot proceed.',
            implode(', ', $unmetRequirements)
        ));
    }
}
