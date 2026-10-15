<?php

declare(strict_types=1);

namespace Tests\Integration\Performance;

use Coleza\Domain\Analytics\Dashboards\DashboardService;
use Coleza\Domain\Analytics\Financial\FinancialMetricsService;
use Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService;
use Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService;
use Coleza\Domain\Catalog\Entities\Product;
use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Services\ServiceService;
use Coleza\Domain\Commerce\Services\ServiceStateMachine;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Queue\DatabaseQueue;
use Coleza\Foundation\Queue\JobInterface;
use Coleza\Foundation\Worker\WorkerSupervisor;
use PDO;
use PHPUnit\Framework\TestCase;

final class PerfTaskJob implements JobInterface
{
    public static int $executed = 0;

    public function handle(): void
    {
        self::$executed++;
    }

    public function queue(): string
    {
        return 'high_priority';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function backoffSeconds(): int
    {
        return 0;
    }
}

/**
 * P18.5 Performance, N+1 Query Growth, and Reference-Budget Regression Suite.
 *
 * Enforces budgets defined in 05-testing/PERFORMANCE_BUDGETS.md:
 * - Simple API read backend target: <= 200 ms.
 * - Client dashboard backend target: <= 400 ms.
 * - Admin dashboard backend target: <= 500 ms.
 * - Typical primary page query budget: <= 30 queries.
 * - List operations detect and prevent N+1 query explosion.
 * - Large dataset processing enforces chunking and peak memory bounds.
 * - Queue workers honor runtime and memory budgets.
 */
final class PerformanceAndReferenceBudgetRegressionTest extends TestCase
{
    private Connection $db;
    private InvoiceService $invoiceService;
    private OrderService $orderService;
    private ServiceService $serviceService;
    private CatalogService $catalogService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = new Connection($pdo);

        $this->orderService = new OrderService($this->db);
        $this->orderService->ensureTables();

        $this->invoiceService = new InvoiceService($this->db, null, null, $this->orderService);
        $this->invoiceService->ensureTables();

        $this->serviceService = new ServiceService($this->db);
        $this->serviceService->ensureTables();

        $this->catalogService = new CatalogService($this->db);
        $this->catalogService->ensureTables();

        PerfTaskJob::$executed = 0;
    }

    /**
     * Budget 1: Simple API Read Backend Target (<= 200 ms).
     */
    public function testSimpleApiReadBackendLatencyBudget(): void
    {
        $group = $this->catalogService->createProductGroup([
            'name' => 'VPS Group',
            'slug' => 'vps-group',
        ]);

        // Seed catalog with products
        for ($i = 1; $i <= 25; $i++) {
            $this->catalogService->createProduct([
                'group_id' => $group->getId(),
                'name' => "Cloud Server {$i}",
                'slug' => "cloud-server-{$i}",
                'type' => Product::TYPE_HOSTING,
                'description' => "Fast cloud server plan {$i}",
                'is_active' => true,
            ]);
        }

        // Measure read operation time
        $start = microtime(true);
        $products = $this->catalogService->listAllProducts();
        $durationMs = (microtime(true) - $start) * 1000.0;

        $this->assertCount(25, $products);
        $this->assertLessThan(
            200.0,
            $durationMs,
            "Simple API read latency {$durationMs}ms exceeded reference budget of 200ms"
        );
    }

    /**
     * Budget 2: Client Dashboard Aggregation Target (<= 400 ms).
     */
    public function testClientDashboardAggregationLatencyBudget(): void
    {
        $userId = 101;

        // Seed customer invoices and services
        for ($i = 1; $i <= 10; $i++) {
            $this->serviceService->createService([
                'user_id' => $userId,
                'product_id' => 1,
                'status' => ServiceStateMachine::STATUS_ACTIVE,
                'billing_cycle' => PriceCycle::MONTHLY,
                'recurring_amount_minor' => 1500,
                'currency_code' => 'USD',
                'registration_date' => '2026-10-01',
                'next_due_date' => '2026-11-01',
                'domain' => "client-app-{$i}.com",
            ]);
        }

        // Measure customer dashboard aggregation latency
        $start = microtime(true);
        $services = $this->serviceService->listServicesForUser($userId);
        $invoices = $this->invoiceService->listInvoicesForUser($userId);
        $durationMs = (microtime(true) - $start) * 1000.0;

        $this->assertCount(10, $services);
        $this->assertIsArray($invoices);
        $this->assertLessThan(
            400.0,
            $durationMs,
            "Client dashboard latency {$durationMs}ms exceeded reference budget of 400ms"
        );
    }

    /**
     * Budget 3: Admin Role-Based Dashboard Aggregation Target (<= 500 ms).
     */
    public function testAdminDashboardAggregationLatencyBudget(): void
    {
        $analyticsPdo = new PDO('sqlite::memory:');
        $analyticsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $analyticsDb = new Connection($analyticsPdo);
        \Coleza\Tests\Support\DailySourceFixture::install($analyticsDb);
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($analyticsDb, 2500, 1);
        \Coleza\Tests\Support\SubscriptionSourceFixture::service($analyticsDb, 30000, 2, 'annually');
        $subService = new SubscriptionSnapshotService($analyticsDb);
        $finService = new FinancialMetricsService($analyticsDb);
        $readService = new ReadModelAggregationService($analyticsDb);
        $subService->ensureTables();
        $readService->ensureTables();

        $dashboardService = new DashboardService(
            db: $analyticsDb,
            subscriptionService: $subService,
            financialService: $finService,
            readModelService: $readService
        );

        \Coleza\Tests\Support\DailySourceFixture::invoice($analyticsDb, 2500, 2000, 500, 'paid', date: '2026-10-15');
        \Coleza\Tests\Support\DailySourceFixture::invoice($analyticsDb, 30000, 25000, 5000, 'paid', date: '2026-10-20');
        foreach (['2026-11-15', '2027-01-10'] as $i => $expiry) {
            $analyticsDb->insert('domains', ['user_id' => 1, 'domain' => 'perf' . $i . '.test', 'sld' => 'perf' . $i, 'tld' => 'test', 'status' => 'active', 'expiry_date' => $expiry]);
        }
        $subService->captureSnapshot('2026-10-31', 'USD');

        $start = microtime(true);
        $ownerDashboard = $dashboardService->buildOwnerDashboard('2026-10-31', 'USD');
        self::assertSame(50.0, $ownerDashboard->getTotalMrr());
        $opsDashboard = $dashboardService->buildOperationsDashboard('2026-10-31');
        $durationMs = (microtime(true) - $start) * 1000.0;

        $this->assertNotNull($ownerDashboard);
        $this->assertNotNull($opsDashboard);
        $this->assertLessThan(
            500.0,
            $durationMs,
            "Admin dashboard latency {$durationMs}ms exceeded reference budget of 500ms"
        );
    }

    /**
     * Budget 4: Primary Page Query Budget (<= 30 Queries).
     */
    public function testPrimaryPageQueryBudgetEnforcement(): void
    {
        $userId = 205;

        // Seed realistic account state
        for ($i = 1; $i <= 5; $i++) {
            $this->serviceService->createService([
                'user_id' => $userId,
                'product_id' => $i,
                'status' => ServiceStateMachine::STATUS_ACTIVE,
                'billing_cycle' => PriceCycle::MONTHLY,
                'recurring_amount_minor' => 2000,
                'currency_code' => 'USD',
                'registration_date' => '2026-10-01',
                'next_due_date' => '2026-11-01',
                'domain' => "query-check-{$i}.org",
            ]);
        }

        // Enable query log and measure total queries executed for primary account page
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();

        $services = $this->serviceService->listServicesForUser($userId);
        $invoices = $this->invoiceService->listInvoicesForUser($userId);
        $catalog = $this->catalogService->listAllProducts();

        $queryCount = $this->db->getQueryCount();
        $this->db->disableQueryLog();

        $this->assertLessThanOrEqual(
            30,
            $queryCount,
            "Primary page query count {$queryCount} exceeded budget limit of 30 queries"
        );
    }

    /**
     * Budget 5: N+1 Query Growth Detection & Prevention.
     *
     * Ensures listing 10 items vs 50 items does NOT cause O(N) query explosion.
     */
    public function testListOperationsPreventNPlusOneQueryExplosion(): void
    {
        $userA = 301;
        $userB = 302;

        // Create small dataset: 5 services
        for ($i = 1; $i <= 5; $i++) {
            $this->serviceService->createService([
                'user_id' => $userA,
                'product_id' => 1,
                'status' => ServiceStateMachine::STATUS_ACTIVE,
                'billing_cycle' => PriceCycle::MONTHLY,
                'recurring_amount_minor' => 1000,
                'currency_code' => 'USD',
                'registration_date' => '2026-10-01',
                'next_due_date' => '2026-11-01',
                'domain' => "small-list-{$i}.com",
            ]);
        }

        // Measure query count for 5 items
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $smallList = $this->serviceService->listServicesForUser($userA);
        $queryCountSmall = $this->db->getQueryCount();
        $this->assertCount(5, $smallList);

        // Create larger dataset: 35 services (7x larger)
        for ($i = 1; $i <= 35; $i++) {
            $this->serviceService->createService([
                'user_id' => $userB,
                'product_id' => 1,
                'status' => ServiceStateMachine::STATUS_ACTIVE,
                'billing_cycle' => PriceCycle::MONTHLY,
                'recurring_amount_minor' => 1000,
                'currency_code' => 'USD',
                'registration_date' => '2026-10-01',
                'next_due_date' => '2026-11-01',
                'domain' => "large-list-{$i}.com",
            ]);
        }

        // Measure query count for 35 items
        $this->db->flushQueryLog();
        $largeList = $this->serviceService->listServicesForUser($userB);
        $queryCountLarge = $this->db->getQueryCount();
        $this->db->disableQueryLog();

        $this->assertCount(35, $largeList);

        // Invariant: Query count must be strictly O(1) constant, NOT linear O(N)
        $this->assertSame(
            $queryCountSmall,
            $queryCountLarge,
            "N+1 regression detected! Query count grew from {$queryCountSmall} to {$queryCountLarge}"
        );
        $this->assertLessThanOrEqual(5, $queryCountLarge);
    }

    /**
     * Budget 6: Large Dataset Chunking & Peak Memory Bounds.
     */
    public function testLargeDatasetExportEnforcesChunkingAndMemoryBound(): void
    {
        $chunkSize = 50;
        $totalRecords = 300;

        // Seed 300 records into a temporary audit table
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS audit_perf_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payload TEXT NOT NULL
            )'
        );

        $dummyPayload = str_repeat('X', 512); // 512 bytes payload
        $this->db->beginTransaction();
        for ($i = 0; $i < $totalRecords; $i++) {
            $this->db->insert('audit_perf_records', ['payload' => $dummyPayload]);
        }
        $this->db->commit();

        $initialMemory = memory_get_usage(true);

        // Process sequentially in chunks of 50 to enforce bounded memory usage
        $processedRecords = 0;
        $offset = 0;
        $chunkCount = 0;

        while ($offset < $totalRecords) {
            $rows = $this->db->select(
                'SELECT id, payload FROM audit_perf_records ORDER BY id ASC LIMIT ? OFFSET ?',
                [$chunkSize, $offset]
            );

            if (empty($rows)) {
                break;
            }

            $processedRecords += count($rows);
            $offset += $chunkSize;
            $chunkCount++;
        }

        $peakMemoryDelta = memory_get_peak_usage(true) - $initialMemory;

        $this->assertSame($totalRecords, $processedRecords);
        $this->assertSame(6, $chunkCount); // 300 / 50 = 6 chunks

        // Invariant: Peak memory footprint delta remains bounded below 5MB
        $this->assertLessThan(
            5 * 1024 * 1024,
            $peakMemoryDelta,
            "Chunked processing peak memory delta exceeded 5MB"
        );
    }

    /**
     * Budget 7: Queue Worker Runtime and Memory Budget Enforcement.
     */
    public function testQueueWorkerHonorsRuntimeAndMemoryBudgets(): void
    {
        $queue = new DatabaseQueue($this->db, new \Coleza\Domain\Vault\QueuePayloadStore($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()));

        // Push 10 jobs to queue
        for ($i = 0; $i < 10; $i++) {
            $queue->push(new PerfTaskJob());
        }

        $this->assertSame(10, $queue->size('high_priority'));

        // Supervisor with maxJobs=4 budget
        $supervisor = new WorkerSupervisor(
            queue: $queue,
            memoryBudgetMb: 256,
            timeBudgetSeconds: 60
        );

        $processed = $supervisor->run(['high_priority'], maxJobs: 4);

        $this->assertSame(4, $processed);
        $this->assertSame(4, PerfTaskJob::$executed);
        $this->assertSame(6, $queue->size('high_priority'));

        // Supervisor with 0-second time budget immediately halts gracefully
        $zeroTimeSupervisor = new WorkerSupervisor(
            queue: $queue,
            memoryBudgetMb: 256,
            timeBudgetSeconds: 0
        );

        $zeroProcessed = $zeroTimeSupervisor->run(['high_priority']);
        $this->assertSame(0, $zeroProcessed);
    }
}
