<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Engine;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Actions\ActionRegistryInterface;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Approval\ApprovalManagerInterface;
use Coleza\Domain\Automation\Delay\DelayManagerInterface;
use Coleza\Domain\Automation\Execution\AutomationRunRecord;
use Coleza\Domain\Automation\Execution\ExecutionMode;
use Coleza\Domain\Automation\Execution\RunHistoryRepositoryInterface;
use Coleza\Domain\Automation\Execution\RunStatus;
use Coleza\Domain\Automation\Safety\BlastRadiusLimiter;
use Coleza\Domain\Automation\Safety\EmergencyPauseManagerInterface;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Psr\Log\LoggerInterface;
use Throwable;

final class AutomationEngine
{
    public function __construct(
        private readonly ActionRegistryInterface $actionRegistry = new ActionRegistry(),
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ApprovalManagerInterface $approvalManager = null,
        private readonly ?DelayManagerInterface $delayManager = null,
        private readonly ?RunHistoryRepositoryInterface $historyRepository = null,
        private readonly ?EmergencyPauseManagerInterface $emergencyPauseManager = null,
        private readonly ?BlastRadiusLimiter $blastRadiusLimiter = null,
        private readonly ?\Coleza\Domain\Automation\Versioning\RuleVersionManagerInterface $versionManager = null,
        private readonly ?\Coleza\Domain\Automation\Approval\ApprovalDelayCoordinatorInterface $approvalDelayCoordinator = null
    ) {
    }

    public function getVersionManager(): ?\Coleza\Domain\Automation\Versioning\RuleVersionManagerInterface { return $this->versionManager; }
    public function getApprovalDelayCoordinator(): ?\Coleza\Domain\Automation\Approval\ApprovalDelayCoordinatorInterface { return $this->approvalDelayCoordinator; }

    public function executeVersion(string $ruleId, int $version, TriggerContext $context, ?ExecutionMode $mode = null): RuleExecutionResult
    {
        $record = $this->versionManager?->getVersion($ruleId, $version);
        if ($record === null) { throw new \InvalidArgumentException('Stored rule version not found'); }
        return $this->executeRule(\Coleza\Domain\Automation\Versioning\RuleSnapshotCodec::restore($record->getSnapshot(), $version), $context, $mode);
    }

    public function getActionRegistry(): ActionRegistryInterface
    {
        return $this->actionRegistry;
    }

    public function getApprovalManager(): ?ApprovalManagerInterface
    {
        return $this->approvalManager;
    }

    public function getDelayManager(): ?DelayManagerInterface
    {
        return $this->delayManager;
    }

    public function getHistoryRepository(): ?RunHistoryRepositoryInterface
    {
        return $this->historyRepository;
    }

    public function getEmergencyPauseManager(): ?EmergencyPauseManagerInterface
    {
        return $this->emergencyPauseManager;
    }

    public function getBlastRadiusLimiter(): ?BlastRadiusLimiter
    {
        return $this->blastRadiusLimiter;
    }

    /**
     * Evaluate and execute a single automation rule against the trigger context.
     */
    public function executeRule(
        AutomationRule $rule,
        TriggerContext $context,
        ?ExecutionMode $overrideMode = null
    ): RuleExecutionResult {
        $storedVersion = $this->versionManager?->getVersion($rule->getId(), $rule->getVersion());
        if ($storedVersion !== null && $storedVersion->getSnapshot() !== \Coleza\Domain\Automation\Versioning\RuleSnapshotCodec::snapshot($rule)) {
            throw new \LogicException('Rule differs from its recorded version; record a new version before executing');
        }
        if (!$rule->isEnabled()) {
            $skipped = RuleExecutionResult::skipped(
                $rule->getId(),
                $rule->getName(),
                'Rule is disabled'
            );
            $this->recordRunHistory($rule, $skipped, $context);
            return $skipped;
        }

        if (!$rule->matchesTrigger($context)) {
            $skipped = RuleExecutionResult::skipped(
                $rule->getId(),
                $rule->getName(),
                'Trigger criteria did not match'
            );
            $this->recordRunHistory($rule, $skipped, $context);
            return $skipped;
        }

        if ($this->emergencyPauseManager !== null && $this->emergencyPauseManager->isPaused(scope: 'ALL')) {
            $reason = 'Emergency pause active: ' . ($this->emergencyPauseManager->getState()->getReason() ?? 'Global pause');
            $paused = RuleExecutionResult::paused(
                $rule->getId(),
                $rule->getName(),
                $reason
            );
            $this->recordRunHistory($rule, $paused, $context);
            $this->logExecution($rule, $paused, $context);
            return $paused;
        }

        $start = microtime(true);
        $effectiveMode = $overrideMode ?? $rule->getExecutionMode();

        $branch = $rule->getBranch();
        $conditionPassed = $branch->evaluateCondition($context);
        $branchTaken = $conditionPassed ? 'THEN' : ($branch->getElseActions() === [] ? 'NONE' : 'ELSE');
        $actions = $conditionPassed ? $branch->getThenActions() : $branch->getElseActions();
        $executionSnapshot = ['rule_version' => $storedVersion?->getVersionNumber() ?? 0,
            'delay_seconds' => $rule->getDelaySeconds(),
            'condition_passed' => $conditionPassed, 'branch_taken' => $branchTaken,
            'rule_fingerprint' => $storedVersion === null ? null : hash('sha256', \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($storedVersion->getSnapshot()))];

        // Dry-run mode: simulate actions without executing side-effects
        if ($effectiveMode->isDryRun()) {
            $simulatedResults = [];
            foreach ($actions as $action) {
                $resolvedParams = $action->resolveParameters($context);
                $simulatedResults[] = ActionResult::success(
                    $action->getId(),
                    $action->getType(),
                    ['dry_run' => true, 'simulated' => true, 'resolved_parameters' => $resolvedParams]
                );
            }

            $durationMs = (microtime(true) - $start) * 1000;
            $result = RuleExecutionResult::dryRun(
                $rule->getId(),
                $rule->getName(),
                $conditionPassed,
                $branchTaken,
                $simulatedResults,
                $durationMs
            );

            $this->recordRunHistory($rule, $result, $context);
            $this->logExecution($rule, $result, $context);
            return $result;
        }

        // Observe mode: monitor and log actions that would execute
        if ($effectiveMode->isObserve()) {
            $observedResults = [];
            foreach ($actions as $action) {
                $resolvedParams = $action->resolveParameters($context);
                $observedResults[] = ActionResult::success(
                    $action->getId(),
                    $action->getType(),
                    ['observed' => true, 'would_execute' => true, 'resolved_parameters' => $resolvedParams]
                );
            }

            $durationMs = (microtime(true) - $start) * 1000;
            $result = RuleExecutionResult::observed(
                $rule->getId(),
                $rule->getName(),
                $conditionPassed,
                $branchTaken,
                $observedResults,
                $durationMs
            );

            $this->recordRunHistory($rule, $result, $context);
            $this->logExecution($rule, $result, $context);
            return $result;
        }

        // Active mode: check if actions require human approval
        if (!empty($actions) && $rule->requiresApproval() && $this->approvalManager === null) {
            throw new \LogicException('Approval-required rule has no approval manager');
        }
        if (!empty($actions) && $rule->hasDelay() && $this->delayManager === null) {
            throw new \LogicException('Delayed rule has no delay manager');
        }
        if (!empty($actions) && $rule->requiresApproval() && $this->approvalManager !== null) {
            $pending = $this->approvalManager->createRequest(
                $rule->getId(),
                $rule->getName(),
                $context,
                $actions,
                $rule->getApprovalRequirement(),
                $executionSnapshot
            );

            $durationMs = (microtime(true) - $start) * 1000;
            $result = RuleExecutionResult::pendingApproval(
                $rule->getId(),
                $rule->getName(),
                $pending->getId(),
                $conditionPassed,
                $branchTaken,
                $durationMs
            );

            $this->recordRunHistory($rule, $result, $context);
            $this->logExecution($rule, $result, $context);
            return $result;
        }

        // Active mode: check if execution is delayed
        if (!empty($actions) && $rule->hasDelay() && $this->delayManager !== null) {
            $delayed = $this->delayManager->schedule(
                $rule->getId(),
                $rule->getName(),
                $context,
                $actions,
                $rule->getDelaySeconds(),
                $executionSnapshot
            );

            $durationMs = (microtime(true) - $start) * 1000;
            $result = RuleExecutionResult::delayed(
                $rule->getId(),
                $rule->getName(),
                $delayed->getId(),
                $conditionPassed,
                $branchTaken,
                $durationMs
            );

            $this->recordRunHistory($rule, $result, $context);
            $this->logExecution($rule, $result, $context);
            return $result;
        }

        // Active mode: execute actions live
        $actionResults = [];
        foreach ($actions as $action) {
            $actionResults[] = $this->executeAction($action, $context);
        }

        $durationMs = (microtime(true) - $start) * 1000;

        $hasFailures = false;
        foreach ($actionResults as $ar) {
            if ($ar->isFailed()) {
                $hasFailures = true;
                break;
            }
        }

        $result = RuleExecutionResult::executed(
            $rule->getId(),
            $rule->getName(),
            $conditionPassed,
            $branchTaken,
            $actionResults,
            $durationMs,
            $hasFailures ? RunStatus::FAILED : RunStatus::SUCCESS,
            ExecutionMode::ACTIVE
        );

        $this->recordRunHistory($rule, $result, $context);
        $this->logExecution($rule, $result, $context);

        return $result;
    }

    /**
     * Process an event across a list of rules, ordered by priority.
     *
     * @param array<int, AutomationRule> $rules
     */
    public function processEvent(
        TriggerContext $context,
        array $rules,
        ?ExecutionMode $overrideMode = null
    ): AutomationExecutionReport {
        $start = microtime(true);

        // Sort rules by priority ascending (e.g. 10 before 100)
        usort($rules, fn (AutomationRule $a, AutomationRule $b) => $a->getPriority() <=> $b->getPriority());

        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->executeRule($rule, $context, $overrideMode);
        }

        $totalDurationMs = (microtime(true) - $start) * 1000;

        return new AutomationExecutionReport($context, $results, $totalDurationMs);
    }

    /**
     * Resume execution of an approved pending approval request.
     */
    public function executeApprovedRequest(string $approvalId, string $approvedBy): RuleExecutionResult
    {
        if ($this->approvalManager === null) {
            throw new \RuntimeException('No ApprovalManager configured on AutomationEngine');
        }

        if ($this->emergencyPauseManager?->isPaused(scope: 'ALL')) {
            throw new \LogicException('Emergency pause active; approval execution deferred');
        }
        $pending = $this->approvalManager->findById($approvalId);
        if ($pending === null) { throw new \InvalidArgumentException('Approval request not found'); }
        if ($pending !== null && $this->actionsPaused($pending->getActionsToExecute())) {
            throw new \LogicException('Emergency pause blocks approval actions; execution deferred');
        }
        $delaySeconds = \Coleza\Domain\Automation\Approval\ApprovalExecutionPolicy::delaySeconds($pending, $this->versionManager);
        if ($delaySeconds > 0) {
            if ($this->approvalDelayCoordinator === null) { throw new \LogicException('Atomic approval/delay coordinator not configured'); }
            $delayed = $this->approvalDelayCoordinator->scheduleApproved($approvalId, $approvedBy);
            return RuleExecutionResult::delayed($pending->getRuleId(), $pending->getRuleName(), $delayed->getId(),
                $pending->getExecutionSnapshot()['condition_passed'] ?? true, $pending->getExecutionSnapshot()['branch_taken'] ?? 'THEN', 0.0);
        }
        $approved = $this->approvalManager->approve($approvalId, $approvedBy);
        $start = microtime(true);

        $actionResults = [];
        foreach ($approved->getActionsToExecute() as $action) {
            $actionResults[] = $this->executeAction($action, $approved->getContext());
        }

        $durationMs = (microtime(true) - $start) * 1000;

        $hasFailures = false;
        foreach ($actionResults as $ar) {
            if ($ar->isFailed()) {
                $hasFailures = true;
                break;
            }
        }

        $result = RuleExecutionResult::executed(
            $approved->getRuleId(),
            $approved->getRuleName(),
            $approved->getExecutionSnapshot()['condition_passed'] ?? true,
            $approved->getExecutionSnapshot()['branch_taken'] ?? 'THEN',
            $actionResults,
            $durationMs,
            $hasFailures ? RunStatus::FAILED : RunStatus::SUCCESS,
            ExecutionMode::ACTIVE
        );
        $this->recordResumedHistory($approved->getRuleId(), $approved->getRuleName(), $approved->getContext(), $result, ['approval_id' => $approvalId], $approved->getExecutionSnapshot());
        return $result;
    }

    /**
     * Dispatch due delayed executions.
     *
     * @return array<string, RuleExecutionResult>
     */
    public function dispatchDueDelayedExecutions(): array
    {
        if ($this->delayManager === null) {
            return [];
        }

        $due = $this->delayManager->getDueExecutions();
        $dispatchedResults = [];

        foreach ($due as $execution) {
            if ($this->emergencyPauseManager?->isPaused(scope: 'ALL')) { break; }
            if ($this->actionsPaused($execution->getActionsToExecute())) { continue; }
            if (!$this->delayManager->claimForDispatch($execution->getId())) { continue; }
            $start = microtime(true);
            $actionResults = [];
            foreach ($execution->getActionsToExecute() as $action) {
                $actionResults[] = $this->executeAction($action, $execution->getContext());
            }

            $durationMs = (microtime(true) - $start) * 1000;
            $this->delayManager->markDispatched($execution->getId());

            $hasFailures = false;
            foreach ($actionResults as $ar) {
                if ($ar->isFailed()) {
                    $hasFailures = true;
                    break;
                }
            }

            $dispatchedResults[$execution->getId()] = RuleExecutionResult::executed(
                $execution->getRuleId(),
                $execution->getRuleName(),
                $execution->getExecutionSnapshot()['condition_passed'] ?? true,
                $execution->getExecutionSnapshot()['branch_taken'] ?? 'THEN',
                $actionResults,
                $durationMs,
                $hasFailures ? RunStatus::FAILED : RunStatus::SUCCESS,
                ExecutionMode::ACTIVE
            );
            $this->recordResumedHistory($execution->getRuleId(), $execution->getRuleName(), $execution->getContext(),
                $dispatchedResults[$execution->getId()], ['delay_id' => $execution->getId()] +
                    (isset($execution->getExecutionSnapshot()['approval_id']) ? ['approval_id' => $execution->getExecutionSnapshot()['approval_id']] : []), $execution->getExecutionSnapshot());
        }

        return $dispatchedResults;
    }

    private function executeAction(ActionInterface $action, TriggerContext $context): ActionResult
    {
        $actionType = $action->getType();

        if ($this->emergencyPauseManager !== null && $this->emergencyPauseManager->isPaused(actionType: $actionType)) {
            $scope = $this->emergencyPauseManager->getState()->getScope();
            $reason = $this->emergencyPauseManager->getState()->getReason() ?? 'Emergency pause';
            return ActionResult::failed(
                $action->getId(),
                $actionType,
                "Action execution blocked by emergency pause (scope: {$scope}): {$reason}"
            );
        }

        if ($this->blastRadiusLimiter !== null && !$this->blastRadiusLimiter->canExecute($actionType)) {
            $this->blastRadiusLimiter->recordAndCheck($actionType);
            return ActionResult::failed(
                $action->getId(),
                $actionType,
                "Action execution blocked: blast radius limit exceeded for destructive action '{$actionType}'"
            );
        }

        if (!$this->actionRegistry->has($actionType)) {
            return ActionResult::failed(
                $action->getId(),
                $actionType,
                "No action handler registered for type '{$actionType}'"
            );
        }

        $handler = $this->actionRegistry->get($actionType);

        try {
            $result = $handler->execute($action, $context);
            if ($result->isSuccessful() && $this->blastRadiusLimiter !== null) {
                $this->blastRadiusLimiter->recordAndCheck($actionType);
            }
            return $result;
        } catch (Throwable $e) {
            return ActionResult::failed(
                $action->getId(),
                $actionType,
                'Action execution failed with exception: ' . $e->getMessage()
            );
        }
    }

    private function recordResumedHistory(string $ruleId, string $ruleName, TriggerContext $context, RuleExecutionResult $result, array $metadata, array $snapshot = []): void
    {
        $this->historyRepository?->save(new AutomationRunRecord('run_' . bin2hex(random_bytes(8)), $ruleId, $ruleName,
            $snapshot['rule_version'] ?? 0, $context->getEventName(), $context->getCorrelationId(), ExecutionMode::ACTIVE, $result->getStatus(),
            $snapshot['condition_passed'] ?? false, $snapshot['branch_taken'] ?? 'UNKNOWN', array_map(fn(ActionResult $r) => $r->toArray(), $result->getActionResults()),
            $context->all(), $result->getExecutionTimeMs(), metadata: $metadata + [
                'rule_version_unknown' => ($snapshot['rule_version'] ?? 0) === 0,
                'branch_unknown' => !array_key_exists('branch_taken', $snapshot),
                'condition_unknown' => !array_key_exists('condition_passed', $snapshot),
                'rule_fingerprint' => $snapshot['rule_fingerprint'] ?? null,
            ]));
    }

    /** Keep paused work pending instead of consuming its decision/dispatch state. */
    private function actionsPaused(array $actions): bool
    {
        foreach ($actions as $action) {
            if ($this->emergencyPauseManager?->isPaused(actionType: $action->getType())) { return true; }
        }
        return false;
    }

    private function recordRunHistory(
        AutomationRule $rule,
        RuleExecutionResult $result,
        TriggerContext $context
    ): void {
        if ($this->historyRepository === null) {
            return;
        }

        $runId = 'run_' . bin2hex(random_bytes(8));

        $record = new AutomationRunRecord(
            $runId,
            $rule->getId(),
            $rule->getName(),
            $rule->getVersion(),
            $context->getEventName(),
            $context->getCorrelationId(),
            $result->getExecutionMode(),
            $result->getStatus(),
            $result->isConditionPassed(),
            $result->getBranchTaken(),
            array_map(fn (ActionResult $r) => $r->toArray(), $result->getActionResults()),
            $context->all(),
            $result->getExecutionTimeMs()
        );

        $this->historyRepository->save($record);
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
            'status' => $result->getStatus()->value,
            'mode' => $result->getExecutionMode()->value,
            'is_successful' => $result->isSuccessful(),
        ]);
    }
}
