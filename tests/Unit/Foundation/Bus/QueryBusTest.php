<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Bus;

use Coleza\Foundation\Bus\QueryBus;
use Coleza\Foundation\Bus\QueryHandlerInterface;
use Coleza\Foundation\Bus\QueryInterface;
use Coleza\Foundation\Container\Container;
use PHPUnit\Framework\TestCase;

class GetUserByIdQuery implements QueryInterface
{
    public function __construct(public int $id) {}
}

class GetUserByIdHandler implements QueryHandlerInterface
{
    public function handle(QueryInterface $query): array
    {
        /** @var GetUserByIdQuery $query */
        return ['id' => $query->id, 'name' => 'Coleza Tester'];
    }
}

final class QueryBusTest extends TestCase
{
    public function testAsksQueryAndReceivesResult(): void
    {
        $container = new Container();
        $bus = new QueryBus($container);

        $bus->register(GetUserByIdQuery::class, GetUserByIdHandler::class);

        $result = $bus->ask(new GetUserByIdQuery(42));
        $this->assertSame(['id' => 42, 'name' => 'Coleza Tester'], $result);
    }
}
