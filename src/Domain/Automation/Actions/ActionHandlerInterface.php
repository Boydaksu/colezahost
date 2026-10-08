<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

use Coleza\Domain\Automation\Triggers\TriggerContext;

interface ActionHandlerInterface
{
    /**
     * Action type this handler manages (e.g. "log", "notification", "context.set").
     */
    public function getType(): string;

    /**
     * Execute the action within the current trigger context.
     */
    public function execute(ActionInterface $action, TriggerContext $context): ActionResult;
}
