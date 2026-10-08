<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Automation;

use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Actions\ActionRegistry;
use Coleza\Domain\Automation\Actions\ActionResult;
use Coleza\Domain\Automation\Actions\ActionStatus;
use Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler;
use Coleza\Domain\Automation\Actions\Handlers\LogActionHandler;
use Coleza\Domain\Automation\Actions\Handlers\SetContextActionHandler;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testActionDefinitionTemplateInterpolation(): void
    {
        $action = new ActionDefinition('a_test', 'notify', [
            'to' => '{{ user.email }}',
            'subject' => 'Service {{ service.name }} suspended (ID: {{ service.id }})',
            'amount' => '{{ invoice.amount }}',
            'nested' => [
                'code' => 'CODE-{{ code }}',
            ],
        ]);

        $context = new TriggerContext('event', [
            'user' => ['email' => 'admin@coleza.com'],
            'service' => ['name' => 'HostPro', 'id' => 'SRV-888'],
            'invoice' => ['amount' => 49.99],
            'code' => 'XYZ',
        ]);

        $resolved = $action->resolveParameters($context);

        $this->assertSame('admin@coleza.com', $resolved['to']);
        $this->assertSame('Service HostPro suspended (ID: SRV-888)', $resolved['subject']);
        $this->assertSame('49.99', $resolved['amount']);
        $this->assertSame('CODE-XYZ', $resolved['nested']['code']);
    }

    public function testActionRegistry(): void
    {
        $registry = new ActionRegistry();
        $handler = new CallbackActionHandler('custom_op', fn () => ['status' => 'done']);

        $this->assertFalse($registry->has('custom_op'));
        $registry->register($handler);
        $this->assertTrue($registry->has('custom_op'));
        $this->assertSame($handler, $registry->get('custom_op'));

        $this->expectException(InvalidArgumentException::class);
        $registry->get('unregistered');
    }

    public function testCallbackActionHandler(): void
    {
        $calledWith = null;
        $handler = new CallbackActionHandler('calc', function (ActionInterface $action, TriggerContext $context) use (&$calledWith) {
            $calledWith = $action->resolveParameters($context);
            return ['computed' => $calledWith['val'] * 2];
        });

        $action = new ActionDefinition('c1', 'calc', ['val' => 21]);
        $context = new TriggerContext('ev', []);

        $result = $handler->execute($action, $context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->getOutput()['computed']);
        $this->assertSame(['val' => 21], $calledWith);
    }

    public function testLogActionHandler(): void
    {
        $handler = new LogActionHandler();
        $action = new ActionDefinition('l1', 'log', [
            'level' => 'warning',
            'message' => 'Service {{ service.id }} hit quota limit',
        ]);
        $context = new TriggerContext('quota.warning', ['service' => ['id' => 'SRV-001']]);

        $result = $handler->execute($action, $context);

        $this->assertTrue($result->isSuccess());
        $messages = $handler->getLoggedMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0]['level']);
        $this->assertSame('Service SRV-001 hit quota limit', $messages[0]['message']);
        $this->assertSame('quota.warning', $messages[0]['context']['event']);
    }

    public function testSetContextActionHandler(): void
    {
        $handler = new SetContextActionHandler();
        $action = new ActionDefinition('set1', 'context.set', [
            'key' => 'computed_state.risk_score',
            'value' => 85,
        ]);
        $context = new TriggerContext('risk.eval', []);

        $result = $handler->execute($action, $context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(85, $context->get('computed_state.risk_score'));
    }
}
