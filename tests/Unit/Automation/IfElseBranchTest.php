<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use PHPUnit\Framework\TestCase;

final class IfElseBranchTest extends TestCase
{
    public function testThenBranchTakenWhenConditionIsTrue(): void
    {
        $condition = new FieldCondition('service.status', ConditionOperator::EQUALS, 'active');
        $thenAction = new ActionDefinition('a1', 'log', ['message' => 'Service is active']);
        $elseAction = new ActionDefinition('a2', 'log', ['message' => 'Service is not active']);

        $branch = new IfElseBranch($condition, [$thenAction], [$elseAction]);
        $context = new TriggerContext('event', ['service' => ['status' => 'active']]);

        $this->assertTrue($branch->evaluateCondition($context));
        $this->assertSame('THEN', $branch->determineBranch($context));

        $actions = $branch->getActionsToExecute($context);
        $this->assertCount(1, $actions);
        $this->assertSame('a1', $actions[0]->getId());
    }

    public function testElseBranchTakenWhenConditionIsFalse(): void
    {
        $condition = new FieldCondition('service.status', ConditionOperator::EQUALS, 'active');
        $thenAction = new ActionDefinition('a1', 'log', ['message' => 'Service is active']);
        $elseAction = new ActionDefinition('a2', 'log', ['message' => 'Service is not active']);

        $branch = new IfElseBranch($condition, [$thenAction], [$elseAction]);
        $context = new TriggerContext('event', ['service' => ['status' => 'suspended']]);

        $this->assertFalse($branch->evaluateCondition($context));
        $this->assertSame('ELSE', $branch->determineBranch($context));

        $actions = $branch->getActionsToExecute($context);
        $this->assertCount(1, $actions);
        $this->assertSame('a2', $actions[0]->getId());
    }

    public function testNoneBranchWhenConditionIsFalseAndNoElseActions(): void
    {
        $condition = new FieldCondition('service.status', ConditionOperator::EQUALS, 'active');
        $thenAction = new ActionDefinition('a1', 'log', ['message' => 'Service is active']);

        $branch = new IfElseBranch($condition, [$thenAction], []);
        $context = new TriggerContext('event', ['service' => ['status' => 'terminated']]);

        $this->assertFalse($branch->evaluateCondition($context));
        $this->assertSame('NONE', $branch->determineBranch($context));

        $actions = $branch->getActionsToExecute($context);
        $this->assertCount(0, $actions);
    }
}
