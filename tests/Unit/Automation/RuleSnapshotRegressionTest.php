<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Automation;
use Coleza\Domain\Automation\Versioning\RuleVersionManager;
use Coleza\Domain\Automation\Engine\AutomationRule;
use Coleza\Domain\Automation\Triggers\EventTrigger;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Conditions\FieldCondition;
use Coleza\Domain\Automation\Actions\ActionDefinition;
final class RuleSnapshotRegressionTest extends \PHPUnit\Framework\TestCase
{
    public function testBehaviorChangesAppearInVersionDiff(): void
    {
        $manager = new RuleVersionManager();
        foreach ([1, 2] as $value) {
            $manager->recordVersion(new AutomationRule('r', 'Rule', new EventTrigger('event', ['brand' => $value]),
                new IfElseBranch(new FieldCondition('days', '>', $value), [new ActionDefinition('a', 'send', ['amount' => $value])])), 'actor', 'change');
        }
        self::assertArrayHasKey('trigger', $manager->diff('r', 1, 2));
        self::assertArrayHasKey('branch', $manager->diff('r', 1, 2));
    }
}
