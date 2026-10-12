<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;

use Coleza\Domain\Automation\Approval\PendingApproval;
use Coleza\Domain\Automation\Delay\DelayedExecution;
use Coleza\Domain\Automation\Persistence\AutomationCodec;

/** Binds local completion evidence to immutable pending inputs, not to its changing lifecycle state. */
final class PendingExecutionIdentity
{
    public static function fingerprint(PendingApproval|DelayedExecution $entry): string
    {
        $context = $entry->getContext();
        $actions = array_map(static fn(\Coleza\Domain\Automation\Actions\ActionInterface $action): array =>
            ['id' => $action->getId(), 'type' => $action->getType(), 'parameters' => $action->getParameters()], $entry->getActionsToExecute());
        return hash('sha256', AutomationCodec::encode(['rule_id' => $entry->getRuleId(), 'context' => [
            'event' => $context->getEventName(), 'payload' => $context->all(), 'occurred_at' => $context->getOccurredAt()->format('Y-m-d\TH:i:s.uP'),
            'correlation_id' => $context->getCorrelationId()], 'actions' => $actions, 'execution_snapshot' => $entry->getExecutionSnapshot()]));
    }
    public static function matchesSuccessfulOutcome(PendingApproval|DelayedExecution $entry, array $run): bool
    {
        $snapshot = $entry->getExecutionSnapshot();
        $metadata = $run['metadata'] ?? [];
        if (!is_array($metadata) || ($metadata['pending_execution_fingerprint'] ?? null) !== self::fingerprint($entry)
            || ($run['status'] ?? null) !== RunStatus::SUCCESS->value || ($run['execution_mode'] ?? null) !== ExecutionMode::ACTIVE->value
            || ($run['rule_id'] ?? null) !== $entry->getRuleId() || ($run['correlation_id'] ?? null) !== $entry->getContext()->getCorrelationId()
            || ($run['event_name'] ?? null) !== $entry->getContext()->getEventName() || ($run['context_summary'] ?? null) !== $entry->getContext()->all()
            || ($run['rule_version'] ?? null) !== ($snapshot['rule_version'] ?? 0)
            || ($run['condition_passed'] ?? null) !== ($snapshot['condition_passed'] ?? false)
            || ($run['branch_taken'] ?? null) !== ($snapshot['branch_taken'] ?? 'UNKNOWN')
            || ($metadata['rule_fingerprint'] ?? null) !== ($snapshot['rule_fingerprint'] ?? null)
            || ($run['error_message'] ?? null) !== null) { return false; }
        if ($entry instanceof PendingApproval) {
            if (($metadata['approval_id'] ?? null) !== $entry->getId() || isset($metadata['delay_id'])) { return false; }
            $startedAt = $entry->getDecidedAt();
        } else {
            if (($metadata['delay_id'] ?? null) !== $entry->getId()
                || ($metadata['approval_id'] ?? null) !== ($snapshot['approval_id'] ?? null)) { return false; }
            $startedAt = $entry->getDispatchedAt();
        }
        if ($startedAt === null || !is_string($run['executed_at'] ?? null)
            || new \DateTimeImmutable($run['executed_at']) < $startedAt) { return false; }
        $results = $run['action_results'] ?? null;
        $actions = $entry->getActionsToExecute();
        if (!is_array($results) || !array_is_list($results) || count($results) !== count($actions)) { return false; }
        foreach ($actions as $index => $action) {
            $result = $results[$index];
            if (!is_array($result) || ($result['action_id'] ?? null) !== $action->getId() || ($result['action_type'] ?? null) !== $action->getType()
                || ($result['status'] ?? null) !== 'SUCCESS' || ($result['error_message'] ?? null) !== null) { return false; }
        }
        return true;
    }
}
