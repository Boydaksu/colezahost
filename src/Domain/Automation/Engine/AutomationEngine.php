<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Engine;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Actions\ActionRegistryInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Psr\Log\LoggerInterface;
use Throwable;

final class AutomationEngine
{
    public function __construct(
        private readonly ActionRegistryInterface $actionRegistry = new ActionRegistry(),
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getActionRegistry(): ActionRegistryInterface
    {
        return $this->actionRegistry;
    }

    /**
     * Evaluate and execute a single automation rule against the trigger context.
     */
    public function executeRule(AutomationRule $rule, TriggerContext $context): RuleExecutionResult
    {
        if (!$rule->isEnabled()) {
            return RuleExecutionResult::skipped(
                $rule->getId(),
                $rule->getName(),
                'Rule is disabled'
            );
        }

        if (!$rule->matchesTrigger($context)) {
            return RuleExecutionResult::skipped(
                $rule->getId(),
                $rule->getName(),
                'Trigger criteria did not match'
            );
        }

        $start = microtime(true);
        $branch = $rule->getBranch();
        $conditionPassed = $branch->evaluateCondition($context);
        $branchTaken = $branch->determineBranch($context);
        $actions = $branch->getActionsToExecute($context);

        $actionResults = [];
        foreach ($actions as $action) {
            $actionResults[] = $this->executeAction($action, $context);
        }

        $durationMs = (microtime(true) - $start) * 1000;

        $result = RuleExecutionResult::executed(
            $rule->getId(),
            $rule->getName(),
            $conditionPassed,
            $branchTaken,
            $actionResults,
            $durationMs
        );

        $this->logExecution($rule, $result, $context);

        return $result;
    }

    /**
     * Process an event across a list of rules, ordered by priority.
     *
     * @param array<int, AutomationRule> $rules
     */
    public function processEvent(TriggerContext $context, array $rules): AutomationExecutionReport
    {
        $start = microtime(true);

        // Sort rules by priority ascending (e.g. 10 before 100)
        usort($rules, fn (AutomationRule $a, AutomationRule $b) => $a->getPriority() <=> $b->getPriority());

        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->executeRule($rule, $context);
        }

        $totalDurationMs = (microtime(true) - $start) * 1000;

        return new AutomationExecutionReport($context, $results, $totalDurationMs);
    }

    private function executeAction(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $actionType = $action->getType();

        if (!$this->actionRegistry->has($actionType)) {
            return ActionResult::failed(
                $action->getId(),
                $actionType,
                "No action handler registered for type '{$actionType}'"
            );
        }

        $handler = $this->actionRegistry->get($actionType);

        try {
            return $handler->execute($action, $context);
        } catch (Throwable $e) {
            return ActionResult::failed(
                $action->getId(),
                $actionType,
                'Action execution failed with exception: ' . $e->getMessage()
            );
        }
    }

    private function logExecution(AutomationRule $rule, RuleExecutionResult $result, TriggerContext $context): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->info("Automation rule '{$rule->getName()}' executed", [
            'rule_id' => $rule->getId(),
            'event' => $context->getEventName(),
            'branch_taken' => $result->getBranchTaken(),
            'condition_passed' => $result->isConditionPassed(),
            'actions_count' => count($result->getActionResults()),
            'is_successful' => $result->isSuccessful(),
        ]);
    }
}
