<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\DomainContact;
use Coleza\Domain\Domains\DomainService;
use Coleza\Domain\Domains\DomainStateMachine;
use Coleza\Domain\Domains\Registrar\DomainAvailabilityResult;
use Coleza\Domain\Domains\Registrar\DomainRegistrationCommand;
use Coleza\Domain\Domains\Registrar\DomainRenewalCommand;
use Coleza\Domain\Domains\Registrar\DomainRegistrarService;
use Coleza\Domain\Domains\Registrar\DomainTransferCommand;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperation;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationReconciliationService;
use Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository;
use Coleza\Domain\Domains\Registrar\Reconciliation\IdempotentDomainOperationService;
use Coleza\Domain\Domains\Registrar\RegistrarCapability;
use Coleza\Domain\Domains\Registrar\RegistrarOperationResult;
use Coleza\Domain\Domains\Registrar\RegistrarProviderInterface;
use Coleza\Domain\Domains\Registrar\RegistrarRegistry;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DomainOperationIdempotencyAndReconciliationTest extends TestCase
{
    private Connection $db;
    private DomainCatalogService $catalog;
    private DomainService $domainService;
    private RegistrarRegistry $registry;
    private DomainRegistrarService $flowService;
    private DomainOperationRepository $operationRepo;
    private IdempotentDomainOperationService $idempotentService;
    private DomainOperationReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->catalog = new DomainCatalogService($this->db);
        $this->catalog->ensureTables();

        $this->domainService = new DomainService($this->db, $this->catalog);
        $this->domainService->ensureTables();

        $this->registry = new RegistrarRegistry();

        $this->catalog->registerTld([
            'extension' => '.com',
            'is_active' => 1,
            'registrar_id' => 'mock_registrar',
        ]);

        $this->flowService = new DomainRegistrarService(
            $this->domainService,
            $this->registry,
            $this->catalog
        );

        $this->operationRepo = new DomainOperationRepository($this->db);
        $this->operationRepo->ensureTable();

        $this->idempotentService = new IdempotentDomainOperationService(
            $this->operationRepo,
            $this->flowService,
            $this->domainService
        );

        $this->reconciliationService = new DomainOperationReconciliationService(
            $this->operationRepo,
            $this->domainService,
            $this->registry
        );
    }

    public function testIdempotencyReturnsCachedSuccessOnDuplicateCall(): void
    {
        $registerCallCount = 0;
        $mock = $this->createMockRegistrar(onRegister: function ($cmd) use (&$registerCallCount) {
            $registerCallCount++;
            return RegistrarOperationResult::success(
                operation: 'register',
                domain: $cmd->getDomain(),
                remoteTransactionId: 'REMOTE-REG-10101',
                expirationDate: '2028-05-01'
            );
        });
        $this->registry->register($mock);

        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'idempotent-domain.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_registrar',
        ]);

        $key = 'REG-KEY-' . bin2hex(random_bytes(6));

        // 1. First execution: should invoke registrar adapter
        $res1 = $this->idempotentService->executeRegister($domain->getId(), $key);
        $this->assertTrue($res1->isSuccessful());
        $this->assertSame(1, $registerCallCount);
        $this->assertSame('REMOTE-REG-10101', $res1->getRemoteTransactionId());

        // 2. Second execution with same idempotency key: should return cached success WITHOUT calling adapter again!
        $res2 = $this->idempotentService->executeRegister($domain->getId(), $key);
        $this->assertTrue($res2->isSuccessful());
        $this->assertSame(1, $registerCallCount); // Still 1!
        $this->assertSame('REMOTE-REG-10101', $res2->getRemoteTransactionId());

        // Verify operation record is SUCCEEDED in repo
        $op = $this->operationRepo->findByIdempotencyKey($key);
        $this->assertNotNull($op);
        $this->assertTrue($op->isSucceeded());
    }

    public function testIdempotencyPreventsConcurrentProcessing(): void
    {
        $mock = $this->createMockRegistrar();
        $this->registry->register($mock);

        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'concurrent-check.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_registrar',
        ]);

        $key = 'CONCURRENT-KEY-999';

        // Pre-create record in PROCESSING status
        $this->operationRepo->create([
            'domain_id' => $domain->getId(),
            'operation_type' => DomainOperation::TYPE_REGISTER,
            'idempotency_key' => $key,
            'status' => DomainOperation::STATUS_PROCESSING,
        ]);

        // Attempting to execute with this key must throw ValidationException
        $this->expectException(ValidationException::class);
        $this->idempotentService->executeRegister($domain->getId(), $key);
    }

    public function testUncertainTimeoutHandlingOnNetworkException(): void
    {
        $mock = $this->createMockRegistrar(onRegister: function () {
            throw new RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received');
        });
        $this->registry->register($mock);

        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'timeout-domain.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_registrar',
        ]);

        $key = 'TIMEOUT-KEY-001';

        $result = $this->idempotentService->executeRegister($domain->getId(), $key);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('UNCERTAIN_TIMEOUT', $result->getErrorCode());

        // Verify operation state in repository is UNCERTAIN
        $op = $this->operationRepo->findByIdempotencyKey($key);
        $this->assertNotNull($op);
        $this->assertTrue($op->isUncertain());

        // Domain must remain in pending_registration
        $fresh = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $fresh->getStatus());

        // Verify timeline contains operation_uncertain
        $timeline = $this->domainService->getTimeline($domain->getId());
        $types = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('operation_uncertain', $types);
    }

    public function testReconciliationResolvesUncertainRegistrationAsSuccess(): void
    {
        // Mock registrar reports remote nameservers present (registration succeeded on remote side)
        $mock = $this->createMockRegistrar(nameservers: ['ns1.namesilo.com', 'ns2.namesilo.com']);
        $this->registry->register($mock);

        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'reconcile-success.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_registrar',
        ]);

        $op = $this->operationRepo->create([
            'domain_id' => $domain->getId(),
            'operation_type' => DomainOperation::TYPE_REGISTER,
            'idempotency_key' => 'RECON-KEY-SUCCESS',
            'status' => DomainOperation::STATUS_UNCERTAIN,
        ]);

        // Reconcile
        $reconciledOp = $this->reconciliationService->reconcileOperation((int) $op->getId());

        $this->assertTrue($reconciledOp->isSucceeded());
        $this->assertNotNull($reconciledOp->getRemoteTransactionId());

        // Domain should be activated
        $freshDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_ACTIVE, $freshDomain->getStatus());

        // Timeline check
        $timeline = $this->domainService->getTimeline($domain->getId());
        $types = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('reconciled_register_success', $types);
    }

    public function testReconciliationResolvesUncertainRegistrationAsFailedWhenDomainStillAvailable(): void
    {
        // Mock registrar reports domain is still available (registration never happened)
        $mock = $this->createMockRegistrar(nameservers: [], availability: true);
        $this->registry->register($mock);

        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'reconcile-fail.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_registrar',
        ]);

        $op = $this->operationRepo->create([
            'domain_id' => $domain->getId(),
            'operation_type' => DomainOperation::TYPE_REGISTER,
            'idempotency_key' => 'RECON-KEY-FAIL',
            'status' => DomainOperation::STATUS_UNCERTAIN,
        ]);

        $reconciledOp = $this->reconciliationService->reconcileOperation((int) $op->getId());

        $this->assertTrue($reconciledOp->isFailed());
        $this->assertStringContainsString('still available', (string) $reconciledOp->getErrorMessage());

        // Domain remains in pending_registration, safe for customer retry or cancellation
        $freshDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertSame(DomainStateMachine::STATUS_PENDING_REGISTRATION, $freshDomain->getStatus());

        // Timeline check
        $timeline = $this->domainService->getTimeline($domain->getId());
        $types = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('reconciled_register_failed', $types);
    }

    public function testReconciliationResolvesUncertainRenewal(): void
    {
        $mock = $this->createMockRegistrar(isLocked: true);
        $this->registry->register($mock);

        $domain = $this->domainService->createDomain([
            'user_id' => 1,
            'domain' => 'reconcile-renew.com',
            'status' => DomainStateMachine::STATUS_PENDING_REGISTRATION,
            'registrar_id' => 'mock_registrar',
        ]);
        $this->domainService->activateDomain($domain->getId(), expiryDate: '2026-12-31');

        $op = $this->operationRepo->create([
            'domain_id' => $domain->getId(),
            'operation_type' => DomainOperation::TYPE_RENEW,
            'idempotency_key' => 'RECON-RENEW-KEY',
            'status' => DomainOperation::STATUS_UNCERTAIN,
            'payload' => ['years' => 2],
        ]);

        $reconciledOp = $this->reconciliationService->reconcileOperation((int) $op->getId());
        $this->assertTrue($reconciledOp->isSucceeded());

        // Domain expiration extended by 2 years: 2026-12-31 -> 2028-12-31
        $freshDomain = $this->domainService->findDomainById($domain->getId());
        $this->assertSame('2028-12-31', $freshDomain->getExpiryDate());

        $timeline = $this->domainService->getTimeline($domain->getId());
        $types = array_map(fn($e) => $e->getEventType(), $timeline);
        $this->assertContains('reconciled_renew_success', $types);
    }

    public function testReconcileAllUncertainBatch(): void
    {
        $mock = $this->createMockRegistrar(nameservers: ['ns1.namesilo.com']);
        $this->registry->register($mock);

        $d1 = $this->domainService->createDomain(['user_id' => 1, 'domain' => 'b1.com', 'registrar_id' => 'mock_registrar']);
        $d2 = $this->domainService->createDomain(['user_id' => 1, 'domain' => 'b2.com', 'registrar_id' => 'mock_registrar']);

        $this->operationRepo->create([
            'domain_id' => $d1->getId(),
            'operation_type' => DomainOperation::TYPE_REGISTER,
            'idempotency_key' => 'BATCH-1',
            'status' => DomainOperation::STATUS_UNCERTAIN,
        ]);
        $this->operationRepo->create([
            'domain_id' => $d2->getId(),
            'operation_type' => DomainOperation::TYPE_REGISTER,
            'idempotency_key' => 'BATCH-2',
            'status' => DomainOperation::STATUS_UNCERTAIN,
        ]);

        $reconciledList = $this->reconciliationService->reconcileAllUncertain();
        $this->assertCount(2, $reconciledList);
        $this->assertTrue($reconciledList[0]->isSucceeded());
        $this->assertTrue($reconciledList[1]->isSucceeded());

        // Repository should now have 0 uncertain operations
        $this->assertEmpty($this->operationRepo->listUncertainOperations());
    }

    private function createMockRegistrar(
        ?callable $onRegister = null,
        array $nameservers = [],
        bool $isLocked = false,
        bool $availability = true
    ): RegistrarProviderInterface {
        return new class($onRegister, $nameservers, $isLocked, $availability) implements RegistrarProviderInterface {
            public function __construct(
                private $onRegister,
                private array $nameservers,
                private bool $isLocked,
                private bool $availability
            ) {
            }

            public function getRegistrarId(): string
            {
                return 'mock_registrar';
            }

            public function getName(): string
            {
                return 'Mock Registrar';
            }

            public function supportsCapability(string $capability): bool
            {
                return true;
            }

            public function getSupportedCapabilities(): array
            {
                return RegistrarCapability::all();
            }

            public function checkAvailability(string $domain): DomainAvailabilityResult
            {
                return $this->availability
                    ? DomainAvailabilityResult::available($domain)
                    : DomainAvailabilityResult::unavailable($domain, 'Taken');
            }

            public function registerDomain(DomainRegistrationCommand $command): RegistrarOperationResult
            {
                if ($this->onRegister !== null) {
                    return ($this->onRegister)($command);
                }
                return RegistrarOperationResult::success('register', $command->getDomain(), 'DEF-TXN', '2027-01-01');
            }

            public function renewDomain(DomainRenewalCommand $command): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('renew', $command->getDomain(), 'DEF-REN');
            }

            public function transferDomain(DomainTransferCommand $command): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('transfer', $command->getDomain(), 'DEF-TRF');
            }

            public function getNameservers(string $domain): array
            {
                return $this->nameservers;
            }

            public function updateNameservers(string $domain, array $nameservers): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('update_nameservers', $domain);
            }

            public function getRegistrarLock(string $domain): bool
            {
                return $this->isLocked;
            }

            public function setRegistrarLock(string $domain, bool $locked): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('set_lock', $domain);
            }

            public function getEppCode(string $domain): ?string
            {
                return 'EPP-MOCK';
            }

            public function getContacts(string $domain): array
            {
                return [];
            }

            public function updateContacts(string $domain, array $contacts): RegistrarOperationResult
            {
                return RegistrarOperationResult::success('update_contacts', $domain);
            }
        };
    }
}
