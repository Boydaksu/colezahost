<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Documents;

use Coleza\Domain\Documents\Contracts\ContractService;
use Coleza\Domain\Documents\Contracts\ContractStatus;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContractServiceTest extends TestCase
{
    private PDO $pdo;
    private ContractService $contractService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->contractService = new ContractService($this->pdo);
    }

    public function testCreateContractCommitment(): void
    {
        $contract = $this->contractService->createContract(
            userId: 30,
            title: 'Managed Dedicated Server MSA & SLA Commitment',
            termsVersion: 'v2.1',
            termsText: 'Hosting terms and SLA agreement with 99.95% uptime guarantee.',
            commitmentPeriodMonths: 24,
            slaCommitmentUptimePercent: 99.95,
            startDate: '2026-10-01',
            serviceId: 55,
            notes: 'Enterprise SLA Tier 1'
        );

        $this->assertNotNull($contract->getId());
        $this->assertSame(ContractStatus::DRAFT, $contract->getStatus());
        $this->assertStringStartsWith('CTR-', $contract->getContractNumber());
        $this->assertSame(24, $contract->getCommitmentPeriodMonths());
        $this->assertSame('2026-10-01', $contract->getStartDate());
        $this->assertSame('2028-10-01', $contract->getEndDate());
        $this->assertSame(99.95, $contract->getSlaCommitmentUptimePercent());
        $this->assertSame(55, $contract->getServiceId());
        $this->assertFalse($contract->isClientAccepted());
        $this->assertFalse($contract->isInternalAccepted());
        $this->assertFalse($contract->isFullyAccepted());
    }

    public function testSubmitForAcceptance(): void
    {
        $contract = $this->contractService->createContract(
            userId: 30,
            title: 'Shared Hosting Annual Terms',
            termsVersion: 'v1.0',
            termsText: 'Standard terms',
            commitmentPeriodMonths: 12
        );

        $pending = $this->contractService->submitForAcceptance($contract->getId());
        $this->assertSame(ContractStatus::PENDING_ACCEPTANCE, $pending->getStatus());
    }

    public function testDualAcceptanceActivatesContract(): void
    {
        $contract = $this->contractService->createContract(
            userId: 42,
            title: 'Enterprise High-Availability Cluster Commitment',
            termsVersion: 'v2.0',
            termsText: 'Full HA failover cluster SLA terms.',
            commitmentPeriodMonths: 12
        );

        $this->contractService->submitForAcceptance($contract->getId());

        // 1. Client signs digitally
        $clientSigned = $this->contractService->recordClientAcceptance(
            id: $contract->getId(),
            clientIp: '198.51.100.22',
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            typedSignature: 'Mehmet Yilmaz (CEO)',
            autoActivate: false
        );

        $this->assertTrue($clientSigned->isClientAccepted());
        $this->assertFalse($clientSigned->isInternalAccepted());
        $this->assertSame('198.51.100.22', $clientSigned->getClientAcceptedIp());
        $this->assertSame('Mehmet Yilmaz (CEO)', $clientSigned->getClientAcceptedSignature());
        $this->assertSame(ContractStatus::PENDING_ACCEPTANCE, $clientSigned->getStatus());

        // 2. Admin internal legal review & acceptance activates the contract
        $fullyActive = $this->contractService->recordInternalAcceptance(
            id: $contract->getId(),
            adminUserId: 1,
            autoActivate: true
        );

        $this->assertTrue($fullyActive->isFullyAccepted());
        $this->assertSame(1, $fullyActive->getInternalAcceptedByUserId());
        $this->assertNotNull($fullyActive->getInternalAcceptedAt());
        $this->assertSame(ContractStatus::ACTIVE, $fullyActive->getStatus());
    }

    public function testContractEarlyTerminationAndPenaltyCalculation(): void
    {
        $contract = $this->contractService->createContract(
            userId: 50,
            title: 'Colocation Rack Lease (12 Months)',
            termsVersion: 'v1.0',
            termsText: 'Rack lease terms',
            commitmentPeriodMonths: 12,
            startDate: '2026-01-01'
        );

        $this->contractService->submitForAcceptance($contract->getId());
        $this->contractService->recordClientAcceptance(
            id: $contract->getId(),
            clientIp: '127.0.0.1',
            userAgent: 'Browser',
            typedSignature: 'Signed User',
            autoActivate: true
        );

        // Early termination calculation:
        // Monthly rate = 2,000 TRY (200000 minor)
        // 12 month contract ending 2027-01-01
        // Terminating on 2026-07-01 (approx 6 months remaining)
        // 50% penalty on remaining 6 months = 6 * 200,000 * 50% = 600,000 minor (6,000 TRY)
        $penalty = $this->contractService->calculateEarlyTerminationFee(
            id: $contract->getId(),
            monthlyRateMinor: 200000,
            penaltyPercent: 50.0,
            terminationDate: '2026-07-01'
        );

        $this->assertGreaterThan(0, $penalty);
        $this->assertEquals(600000, $penalty);

        // Execute termination
        $terminated = $this->contractService->terminateContract(
            id: $contract->getId(),
            reason: 'Customer downsizing physical infrastructure',
            earlyTerminationFeeMinor: $penalty
        );

        $this->assertSame(ContractStatus::TERMINATED, $terminated->getStatus());
        $this->assertSame('Customer downsizing physical infrastructure', $terminated->getTerminationReason());
        $this->assertSame(600000, $terminated->getEarlyTerminationFeeMinor());
    }

    public function testFindByServiceId(): void
    {
        $c1 = $this->contractService->createContract(
            userId: 60,
            title: 'SLA for Service 88',
            termsVersion: 'v1.0',
            termsText: 'SLA',
            serviceId: 88
        );

        $c2 = $this->contractService->createContract(
            userId: 60,
            title: 'Another contract for Service 99',
            termsVersion: 'v1.0',
            termsText: 'SLA',
            serviceId: 99
        );

        $matches = $this->contractService->findByServiceId(88);
        $this->assertCount(1, $matches);
        $this->assertSame($c1->getContractNumber(), $matches[0]->getContractNumber());
    }
}
