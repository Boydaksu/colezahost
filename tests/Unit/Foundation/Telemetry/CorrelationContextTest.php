<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Telemetry;

use Coleza\Foundation\Http\Request;
use Coleza\Foundation\Telemetry\CorrelationContext;
use PHPUnit\Framework\TestCase;

final class CorrelationContextTest extends TestCase
{
    protected function tearDown(): void
    {
        CorrelationContext::setCurrent(null);
        parent::tearDown();
    }

    public function testGeneratesValidContextWithIds(): void
    {
        $context = CorrelationContext::generate();

        $this->assertNotEmpty($context->getRequestId());
        $this->assertNotEmpty($context->getCorrelationId());
        $this->assertSame($context->getRequestId(), $context->getCorrelationId());
    }

    public function testExtractsHeaderIdsFromRequest(): void
    {
        $req = new Request('GET', '/', headers: [
            'x-request-id' => 'req-abc-123',
            'x-correlation-id' => 'corr-xyz-789',
        ]);

        $context = CorrelationContext::fromRequest($req);

        $this->assertSame('req-abc-123', $context->getRequestId());
        $this->assertSame('corr-xyz-789', $context->getCorrelationId());
    }

    public function testContextMetadataAndArrayRepresentation(): void
    {
        $context = CorrelationContext::generate();
        $context->setUserId('usr_1');
        $context->setOrgId('org_99');
        $context->setOperationId('op_charge');

        $arr = $context->toArray();

        $this->assertSame('usr_1', $arr['user_id']);
        $this->assertSame('org_99', $arr['org_id']);
        $this->assertSame('op_charge', $arr['operation_id']);
    }
}
