<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Persistence;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Approval\{PendingApproval, ApprovalRequirement, ApprovalStatus};
use Coleza\Domain\Automation\Delay\{DelayedExecution, DelayStatus};
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;

/** Only data is stored. PHP objects and executable custom actions are never serialized. */
final class AutomationCodec
{
    public static function encode(array $data): string
    {
        self::validate($data);
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > 64) { throw new \InvalidArgumentException('Automation data exceeds the nesting limit.'); }
        if (is_array($value)) {
            foreach ($value as $item) { self::validate($item, $depth + 1); }
        } elseif ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Persistent automation requires scalar and array data.');
        }
    }

    public static function decode(string $json): array
    {
        $data = json_decode($json, true, 65, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \UnexpectedValueException('Invalid automation record.'); }
        return $data;
    }

    public static function pending(PendingApproval|DelayedExecution $entry): array
    {
        $context = $entry->getContext();
        $data = $entry->toArray();
        if ($entry instanceof PendingApproval) {
            $data['requested_at'] = $entry->getRequestedAt()->format('Y-m-d\TH:i:s.uP');
            $data['expires_at'] = $entry->getExpiresAt()?->format('Y-m-d\TH:i:s.uP');
            $data['decided_at'] = $entry->getDecidedAt()?->format('Y-m-d\TH:i:s.uP');
        } else {
            $data['scheduled_at'] = $entry->getScheduledAt()->format('Y-m-d\TH:i:s.uP');
            $data['execute_at'] = $entry->getExecuteAt()->format('Y-m-d\TH:i:s.uP');
            $data['dispatched_at'] = $entry->getDispatchedAt()?->format('Y-m-d\TH:i:s.uP');
        }
        $data['context'] = ['event' => $context->getEventName(), 'payload' => $context->all(),
            'occurred_at' => $context->getOccurredAt()->format('Y-m-d\TH:i:s.uP'), 'correlation_id' => $context->getCorrelationId()];
        $data['actions'] = [];
        foreach ($entry->getActionsToExecute() as $action) {
            if (!$action instanceof ActionDefinition) {
                throw new \InvalidArgumentException('Persistent automation supports ActionDefinition only.');
            }
            $data['actions'][] = ['id' => $action->getId(), 'type' => $action->getType(), 'parameters' => $action->getParameters()];
        }
        if ($entry instanceof PendingApproval) { $data['timeout_seconds'] = $entry->getRequirement()->getTimeoutSeconds(); }
        return $data;
    }

    public static function hydrate(array $data, string $kind): PendingApproval|DelayedExecution
    {
        $c = $data['context'];
        $context = new TriggerContext($c['event'], $c['payload'], new DateTimeImmutable($c['occurred_at']), $c['correlation_id']);
        $actions = array_map(static fn(array $a) => new ActionDefinition($a['id'], $a['type'], $a['parameters']), $data['actions']);
        if ($kind === 'approval') {
            return PendingApproval::restore(new PendingApproval($data['id'], $data['rule_id'], $data['rule_name'], $context, $actions,
                new ApprovalRequirement($data['required_role'], $data['timeout_seconds'], $data['reason']),
                new DateTimeImmutable($data['requested_at']), self::date($data['expires_at'])),
                ApprovalStatus::from($data['status']), $data['decided_by'], $data['decision_notes'], self::date($data['decided_at']));
        }
        if ($kind !== 'delay') { throw new \UnexpectedValueException('Unknown automation entry kind.'); }
        return DelayedExecution::restore(new DelayedExecution($data['id'], $data['rule_id'], $data['rule_name'], $context, $actions,
            new DateTimeImmutable($data['execute_at']), new DateTimeImmutable($data['scheduled_at'])),
            DelayStatus::from($data['status']), self::date($data['dispatched_at']));
    }

    public static function date(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
