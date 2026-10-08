<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions\Handlers;

use Coleza\Domain\Automation\Actions\ActionHandlerInterface;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Closure;
use Throwable;

final class CallbackActionHandler implements ActionHandlerInterface
{
    /**
     * @param Closure(ActionInterface, TriggerContext): ActionResult $callback
     */
    public function __construct(
        private readonly string $type,
        private readonly Closure $callback
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function execute(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $start = microtime(true);
        try {
            $result = ($this->callback)($action, $context);
            if ($result instanceof ActionResult) {
                return $result;
            }
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::success($action->getId(), $this->type, is_array($result) ? $result : ['result' => $result], $durationMs);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            return ActionResult::failed($action->getId(), $this->type, $e->getMessage(), [], $durationMs);
        }
    }
}
