<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Conditions\ConditionGroup;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Conditions\LogicalOperator;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use PHPUnit\Framework\TestCase;

final class ConditionTest extends TestCase
{
    public function testEqualityOperators(): void
    {
        $context = new TriggerContext('test', [
            'service' => ['status' => 'active', 'port' => 2083],
            'customer_verified' => true,
        ]);

        $eq = new FieldCondition('service.status', ConditionOperator::EQUALS, 'active');
        $this->assertTrue($eq->evaluate($context));

        $eqStr = new FieldCondition('service.status', 'equals', 'active');
        $this->assertTrue($eqStr->evaluate($context));

        $neq = new FieldCondition('service.status', ConditionOperator::NOT_EQUALS, 'suspended');
        $this->assertTrue($neq->evaluate($context));

        $boolEq = new FieldCondition('customer_verified', ConditionOperator::EQUALS, true);
        $this->assertTrue($boolEq->evaluate($context));
    }

    public function testNumericComparisons(): void
    {
        $context = new TriggerContext('test', [
            'days_overdue' => 7,
            'balance' => 99.50,
        ]);

        $gt = new FieldCondition('days_overdue', ConditionOperator::GREATER_THAN, 5);
        $this->assertTrue($gt->evaluate($context));

        $gte = new FieldCondition('days_overdue', ConditionOperator::GREATER_THAN_OR_EQUAL, 7);
        $this->assertTrue($gte->evaluate($context));

        $lt = new FieldCondition('days_overdue', ConditionOperator::LESS_THAN, 10);
        $this->assertTrue($lt->evaluate($context));

        $lte = new FieldCondition('balance', ConditionOperator::LESS_THAN_OR_EQUAL, 99.50);
        $this->assertTrue($lte->evaluate($context));

        $between = new FieldCondition('balance', ConditionOperator::BETWEEN, [50, 100]);
        $this->assertTrue($between->evaluate($context));

        $notBetween = new FieldCondition('balance', ConditionOperator::BETWEEN, [100, 200]);
        $this->assertFalse($notBetween->evaluate($context));
    }

    public function testStringAndCollectionOperators(): void
    {
        $context = new TriggerContext('test', [
            'domain' => 'example.co.uk',
            'tags' => ['cpanel', 'shared', 'vip'],
            'tier' => 'gold',
            'notes' => null,
        ]);

        $contains = new FieldCondition('tags', ConditionOperator::CONTAINS, 'vip');
        $this->assertTrue($contains->evaluate($context));

        $notContains = new FieldCondition('tags', ConditionOperator::NOT_CONTAINS, 'reseller');
        $this->assertTrue($notContains->evaluate($context));

        $stringContains = new FieldCondition('domain', ConditionOperator::CONTAINS, 'co.uk');
        $this->assertTrue($stringContains->evaluate($context));

        $startsWith = new FieldCondition('domain', ConditionOperator::STARTS_WITH, 'example');
        $this->assertTrue($startsWith->evaluate($context));

        $endsWith = new FieldCondition('domain', ConditionOperator::ENDS_WITH, '.uk');
        $this->assertTrue($endsWith->evaluate($context));

        $inList = new FieldCondition('tier', ConditionOperator::IN, ['bronze', 'silver', 'gold']);
        $this->assertTrue($inList->evaluate($context));

        $notInList = new FieldCondition('tier', ConditionOperator::NOT_IN, ['platinum', 'diamond']);
        $this->assertTrue($notInList->evaluate($context));

        $isNull = new FieldCondition('notes', ConditionOperator::IS_NULL);
        $this->assertTrue($isNull->evaluate($context));

        $isNotNull = new FieldCondition('domain', ConditionOperator::IS_NOT_NULL);
        $this->assertTrue($isNotNull->evaluate($context));

        $regex = new FieldCondition('domain', ConditionOperator::MATCHES_REGEX, '/^[a-z0-9.-]+\.uk$/');
        $this->assertTrue($regex->evaluate($context));
    }

    public function testConditionGroupAndLogic(): void
    {
        $context = new TriggerContext('test', [
            'service' => ['status' => 'suspended', 'days' => 14],
        ]);

        $group = ConditionGroup::and(
            new FieldCondition('service.status', ConditionOperator::EQUALS, 'suspended'),
            new FieldCondition('service.days', ConditionOperator::GREATER_THAN, 7)
        );

        $this->assertTrue($group->evaluate($context));

        $groupFail = ConditionGroup::and(
            new FieldCondition('service.status', ConditionOperator::EQUALS, 'suspended'),
            new FieldCondition('service.days', ConditionOperator::GREATER_THAN, 30)
        );

        $this->assertFalse($groupFail->evaluate($context));
    }

    public function testConditionGroupOrLogic(): void
    {
        $context = new TriggerContext('test', [
            'plan' => 'enterprise',
            'vip' => false,
        ]);

        $group = ConditionGroup::or(
            new FieldCondition('vip', ConditionOperator::EQUALS, true),
            new FieldCondition('plan', ConditionOperator::EQUALS, 'enterprise')
        );

        $this->assertTrue($group->evaluate($context));
    }

    public function testNestedConditionGroups(): void
    {
        // (status == 'active' AND quota > 90) OR (status == 'overdue' AND days > 3)
        $rule = ConditionGroup::or(
            ConditionGroup::and(
                new FieldCondition('status', ConditionOperator::EQUALS, 'active'),
                new FieldCondition('quota', ConditionOperator::GREATER_THAN, 90)
            ),
            ConditionGroup::and(
                new FieldCondition('status', ConditionOperator::EQUALS, 'overdue'),
                new FieldCondition('days', ConditionOperator::GREATER_THAN, 3)
            )
        );

        // Case 1: matches second branch
        $context1 = new TriggerContext('test', [
            'status' => 'overdue',
            'days' => 5,
            'quota' => 40,
        ]);
        $this->assertTrue($rule->evaluate($context1));

        // Case 2: matches first branch
        $context2 = new TriggerContext('test', [
            'status' => 'active',
            'quota' => 95,
            'days' => 0,
        ]);
        $this->assertTrue($rule->evaluate($context2));

        // Case 3: matches neither branch
        $context3 = new TriggerContext('test', [
            'status' => 'pending',
            'quota' => 10,
            'days' => 0,
        ]);
        $this->assertFalse($rule->evaluate($context3));
    }
}
