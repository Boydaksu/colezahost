<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ApprovalDelayTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ApprovalDelayCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testFourSameActorRetriesShareOneDelayAndHandoffRecord(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $results = $this->runParallel('approvalDelaySameActor', 0, $id);
        self::assertCount(1, array_unique(array_column($results, 'delay_id')));
        self::assertCount(4, array_filter($results, static fn($r) => $r['status'] === 'ok'));
        self::assertSame(1, $this->childCount());
        self::assertSame(2, $engine->getHistoryRepository()->count());
    }
    public function testFourDifferentActorsProduceOnlyOneDecisionAndChild(): void
    {
        $this->installCombined();
        [$engine, $id] = $this->makePending();
        $results = $this->runParallel('approvalDelayDifferentActors', 0, $id);
        self::assertCount(1, array_filter($results, static fn($r) => $r['status'] === 'ok'));
        self::assertCount(3, array_filter($results, static fn($r) => $r['status'] === 'rejected'));
        self::assertSame(1, $this->childCount());
        self::assertSame(2, $engine->getHistoryRepository()->count());
    }
}
