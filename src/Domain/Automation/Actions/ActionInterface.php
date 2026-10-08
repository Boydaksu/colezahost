<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

use Coleza\Domain\Automation\Triggers\TriggerContext;

interface ActionInterface
{
    /**
     * Unique identifier for this action instance within the rule.
     */
    public function getId(): string;

    /**
     * The type of action (e.g. "log", "notification", "service.suspend", "custom").
     */
    public function getType(): string;

    /**
     * Raw parameters for the action.
     * @return array<string, mixed>
     */
    public function getParameters(): array;

    /**
     * Returns parameters with template placeholders ({{ field }}) resolved from the context.
     * @return array<string, mixed>
     */
    public function resolveParameters(TriggerContext $context): array;
}
