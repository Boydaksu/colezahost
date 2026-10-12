<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Versioning;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\{ConditionInterface, ConditionGroup, FieldCondition, LogicalOperator, ConditionOperator};
use Coleza\Domain\Automation\Triggers\{EventTrigger, ManualTrigger, ScheduleTrigger};
use Coleza\Domain\Automation\Approval\ApprovalRequirement;
use Coleza\Domain\Automation\Execution\ExecutionMode;
use Coleza\Domain\Automation\Persistence\AutomationCodec;

/** Explicit data-only format; unknown executable types are rejected. */
final class RuleSnapshotCodec
{
    public static function snapshot(AutomationRule $rule): array
    {
        $trigger = $rule->getTrigger();
        $t = match (true) {
            $trigger instanceof EventTrigger => ['type' => 'event', 'pattern' => $trigger->getName(), 'filters' => $trigger->getPayloadFilters()],
            $trigger instanceof ManualTrigger => ['type' => 'manual', 'scope' => $trigger->getTargetScope()],
            $trigger instanceof ScheduleTrigger => ['type' => 'schedule', 'interval' => $trigger->getScheduleInterval()],
            default => throw new \InvalidArgumentException('Unsupported persistent trigger'),
        };
        $approval = $rule->getApprovalRequirement();
        $data = ['format_version' => 1, 'id' => $rule->getId(), 'name' => $rule->getName(), 'description' => $rule->getDescription(),
            'enabled' => $rule->isEnabled(), 'priority' => $rule->getPriority(), 'execution_mode' => $rule->getExecutionMode()->value,
            'delay_seconds' => $rule->getDelaySeconds(), 'requires_approval' => $rule->requiresApproval(), 'metadata' => $rule->getMetadata(),
            'trigger' => $t, 'branch' => ['condition' => self::condition($rule->getBranch()->getCondition()),
                'then' => self::actions($rule->getBranch()->getThenActions()), 'else' => self::actions($rule->getBranch()->getElseActions())],
            'approval' => $approval === null ? null : ['role' => $approval->getRequiredRole(), 'timeout' => $approval->getTimeoutSeconds(), 'reason' => $approval->getReason()]];
        AutomationCodec::encode($data);
        return $data;
    }
    private static function actions(array $actions): array
    {
        return array_map(static function ($action): array {
            if (!$action instanceof ActionDefinition) { throw new \InvalidArgumentException('Unsupported persistent action'); }
            return ['id' => $action->getId(), 'type' => $action->getType(), 'parameters' => $action->getParameters()];
        }, $actions);
    }
    private static function condition(ConditionInterface $condition, int $depth = 0): array
    {
        if ($depth > 32) { throw new \InvalidArgumentException('Condition nesting too deep'); }
        if ($condition instanceof FieldCondition) {
            return ['type' => 'field', 'field' => $condition->getField(), 'operator' => $condition->getOperator()->value, 'value' => $condition->getExpectedValue()];
        }
        if ($condition instanceof ConditionGroup) {
            return ['type' => 'group', 'operator' => $condition->getLogicalOperator()->value,
                'conditions' => array_map(static fn($c) => self::condition($c, $depth + 1), $condition->getConditions())];
        }
        throw new \InvalidArgumentException('Unsupported persistent condition');
    }
    public static function restore(array $s, int $version): AutomationRule
    {
        if (($s['format_version'] ?? null) !== 1) { throw new \UnexpectedValueException('Unknown rule snapshot format'); }
        $t = $s['trigger'];
        $trigger = match ($t['type']) {
            'event' => new EventTrigger($t['pattern'], $t['filters']),
            'manual' => new ManualTrigger($t['scope']),
            'schedule' => new ScheduleTrigger($t['interval']),
            default => throw new \UnexpectedValueException('Unknown trigger type'),
        };
        $actions = static fn(array $list) => array_map(static fn(array $a) => new ActionDefinition($a['id'], $a['type'], $a['parameters']), $list);
        $a = $s['approval'];
        return new AutomationRule($s['id'], $s['name'], $trigger, new IfElseBranch(self::restoreCondition($s['branch']['condition']),
            $actions($s['branch']['then']), $actions($s['branch']['else'])), $s['enabled'], $s['priority'], $s['description'], $s['metadata'],
            $version, ExecutionMode::from($s['execution_mode']), $a === null ? null : new ApprovalRequirement($a['role'], $a['timeout'], $a['reason']), $s['delay_seconds']);
    }
    private static function restoreCondition(array $c, int $depth = 0): ConditionInterface
    {
        if ($depth > 32) { throw new \UnexpectedValueException('Condition nesting too deep'); }
        return match ($c['type']) {
            'field' => new FieldCondition($c['field'], ConditionOperator::from($c['operator']), $c['value']),
            'group' => new ConditionGroup(LogicalOperator::from($c['operator']), array_map(static fn($item) => self::restoreCondition($item, $depth + 1), $c['conditions'])),
            default => throw new \UnexpectedValueException('Unknown condition type'),
        };
    }
}
