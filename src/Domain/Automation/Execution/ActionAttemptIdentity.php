<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Approval\PendingApproval;
use Coleza\Domain\Automation\Delay\DelayedExecution;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use Coleza\Domain\Automation\Triggers\TriggerContext;

/** Local evidence binding only. Hashes do not authorize execution or prove a provider effect. */
final class ActionAttemptIdentity
{
    public static function definitionFingerprint(ActionInterface $action): string
    {
        return hash('sha256', AutomationCodec::encode(['id' => $action->getId(), 'type' => $action->getType(), 'parameters' => $action->getParameters()]));
    }
    public static function inputFingerprint(ActionInterface $action, TriggerContext $context): string
    {
        return hash('sha256', AutomationCodec::encode(['action_id' => $action->getId(), 'action_type' => $action->getType(),
            'parameters' => $action->getParameters(), 'resolved_parameters' => $action->resolveParameters($context),
            'event' => $context->getEventName(), 'context' => $context->all(), 'correlation_id' => $context->getCorrelationId()]));
    }

    /** Every ordered action must have one coherent, final observation from the same dispatch. */
    public static function matchesPendingOutcome(PendingApproval|DelayedExecution $entry, array $run, array $attempts, array $dispatchSizes, array $pendingSizes): bool
    {
        $ids = $run['metadata']['action_attempt_ids'] ?? null;
        $actions = $entry->getActionsToExecute();
        if (!is_array($ids) || !array_is_list($ids) || count($ids) !== count($actions)) { return false; }
        $snapshot = $entry->getExecutionSnapshot();
        $pendingFingerprint = PendingExecutionIdentity::fingerprint($entry);
        $kind = $entry instanceof PendingApproval ? 'approval' : 'delay';
        if (($pendingSizes[$kind][$entry->getId()] ?? 0) !== count($actions)) { return false; }
        $earliest = $entry instanceof PendingApproval ? $entry->getDecidedAt() : $entry->getExecuteAt();
        $runTime = self::time($run['executed_at'] ?? null);
        if ($earliest === null || $runTime === null) { return false; }
        $dispatch = null;
        $seen = [];
        $previousFinish = null;
        foreach ($actions as $position => $action) {
            $id = $ids[$position];
            if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || isset($seen[$id])) { return false; }
            $seen[$id] = true;
            $row = $attempts[$id] ?? null;
            if ($row === null) { return false; }
            $dispatch ??= $row['dispatch_id'];
            $input = self::inputFingerprint($action, $entry->getContext());
            if (!is_string($dispatch) || !preg_match('/^[a-f0-9]{32}$/D', $dispatch)
                || $row['dispatch_id'] !== $dispatch || $row['id'] !== $id
                || $id !== hash('sha256', $dispatch . ':' . $position) || (int) $row['action_position'] !== $position
                || ($dispatchSizes[$dispatch] ?? 0) !== count($actions)
                || $row['rule_id'] !== $entry->getRuleId() || $row['correlation_id'] !== $entry->getContext()->getCorrelationId()
                || $row['pending_kind'] !== $kind || $row['pending_id'] !== $entry->getId()
                || $row['pending_fingerprint'] !== $pendingFingerprint
                || (int) $row['rule_version'] !== ($snapshot['rule_version'] ?? 0)
                || $row['rule_fingerprint'] !== ($snapshot['rule_fingerprint'] ?? null)
                || $row['action_id'] !== $action->getId() || $row['action_type'] !== $action->getType()
                || $row['action_definition_fingerprint'] !== self::definitionFingerprint($action)
                || $row['planned_input_fingerprint'] !== $input || $row['input_fingerprint'] !== $input
                || $row['status'] !== 'REPORTED_SUCCESS' || $row['reason_code'] !== 'HANDLER_REPORTED_SUCCESS') { return false; }
            $created = self::time($row['created_at']);
            $started = self::time($row['started_at']);
            $finished = self::time($row['finished_at']);
            if ($created === null || $started === null || $finished === null || $created < $earliest
                || $started < $created || $finished < $started || $finished > $runTime
                || ($previousFinish !== null && $started < $previousFinish)) { return false; }
            $previousFinish = $finished;
        }
        return true;
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) { return null; }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value);
        return $time !== false && $time->format('Y-m-d\TH:i:s.uP') === $value ? $time : null;
    }
}
