<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions\Handlers;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;

final class SetContextActionHandler implements ActionHandlerInterface
{
    public function getType(): string
    {
        return 'context.set';
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        $resolved = $action->resolveParameters($context);

        $key = (string) ($resolved['key'] ?? '');
        $value = $resolved['value'] ?? null;

        if ($key === '') {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed($action->getId(), $this->getType(), "Missing required 'key' parameter", [], $durationMs);
        }

        $context->set($key, $value);
        $durationMs = (microtime(true) - $start) * 1000;

        return ActionResult::success($action->getId(), $this->getType(), [
            'key' => $key,
            'value' => $value,
        ], $durationMs);
    }
}
