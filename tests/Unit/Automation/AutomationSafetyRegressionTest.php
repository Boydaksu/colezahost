<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Automation\Engine\AutomationEngine;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Conditions\ConditionOperator;
use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Approval\ApprovalRequirement;

final class AutomationSafetyRegressionTest extends \PHPUnit\Framework\TestCase
{
    public function testEventIdentityAndTimeRemainStable(): void
    {
        $context = new TriggerContext('invoice.paid');
        self::assertSame($context->getCorrelationId(), $context->getCorrelationId());
        self::assertSame($context->getOccurredAt(), $context->getOccurredAt());
        self::assertSame($context->getCorrelationId(), $context->with('invoice_id', 3)->getCorrelationId());
    }

    public function testMissingApprovalManagerCannotBypassApproval(): void
    {
        $rule = new AutomationRule('r', 'Restricted', new EventTrigger('event'),
            new IfElseBranch(new FieldCondition('ok', ConditionOperator::EQUALS, true), [new ActionDefinition('a', 'log')]),
            approvalRequirement: new ApprovalRequirement());
        $this->expectException(\LogicException::class);
        (new AutomationEngine())->executeRule($rule, new TriggerContext('event', ['ok' => true]));
    }
}
