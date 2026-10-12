<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Automation;
final class ResumedHistoryCaptureTest extends \PHPUnit\Framework\TestCase
{
    private int $calls = 0;
    private function fixture(?\Coleza\Domain\Automation\Execution\RunHistoryRepositoryInterface $history = null): array
    {
        $approval = new \Coleza\Domain\Automation\Approval\ApprovalManager();
        $registry = new \Coleza\Domain\Automation\Actions\ActionRegistry();
        $registry->register(new \Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler('send', function () { $this->calls++; return []; }));
        $engine = new \Coleza\Domain\Automation\Engine\AutomationEngine($registry, approvalManager: $approval, historyRepository: $history);
        $entry = $approval->createRequest('r', 'Rule', new \Coleza\Domain\Automation\Triggers\TriggerContext('event', ['value' => new \stdClass()]),
            [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'send')], new \Coleza\Domain\Automation\Approval\ApprovalRequirement(), ['delay_seconds' => 0]);
        return [$engine, $approval, $entry];
    }
    public function testMemoryExecutionWithoutHistoryKeepsObjectContextCompatibility(): void
    {
        [$engine, , $entry] = $this->fixture();
        self::assertTrue($engine->executeApprovedRequest($entry->getId(), 'actor')->isSuccessful());
        self::assertSame(1, $this->calls);
    }
    public function testNonPersistentHistoryKeepsObjectContextCompatibility(): void
    {
        $history = new \Coleza\Domain\Automation\Execution\InMemoryRunHistoryRepository();
        [$engine, , $entry] = $this->fixture($history);
        self::assertTrue($engine->executeApprovedRequest($entry->getId(), 'actor')->isSuccessful());
        self::assertSame(1, $this->calls);
        self::assertSame(1, $history->count());
    }
    public function testPersistentEvidenceValidationHappensBeforeDecisionAndExternalEffect(): void
    {
        $db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:'));
        \Coleza\Domain\Automation\Persistence\AutomationSchema::installSchema($db);
        $history = new \Coleza\Domain\Automation\Execution\DatabaseRunHistoryRepository($db);
        [$engine, $approval, $entry] = $this->fixture($history);
        try { $engine->executeApprovedRequest($entry->getId(), 'actor'); self::fail('Unsupported persistent inputs accepted'); }
        catch (\InvalidArgumentException) {
            self::assertSame(0, $this->calls);
            self::assertSame('PENDING', $approval->findById($entry->getId())->getStatus()->value);
            self::assertSame(0, $history->count());
        }
    }
}
