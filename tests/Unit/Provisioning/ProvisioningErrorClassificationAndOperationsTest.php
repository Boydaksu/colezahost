<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorCategory;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProvisioningErrorClassificationAndOperationsTest extends TestCase
{
    private Connection $db;
    private ProvisioningOperationService $opService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->opService = new ProvisioningOperationService($this->db);
        $this->opService->ensureTables();
    }

    public function testErrorCategoryEnumerationAndRetryability(): void
    {
        $all = ProvisioningErrorCategory::all();
        $this->assertContains(ProvisioningErrorCategory::TRANSIENT_NETWORK, $all);
        $this->assertContains(ProvisioningErrorCategory::RATE_LIMITED, $all);
        $this->assertContains(ProvisioningErrorCategory::AUTHENTICATION, $all);
        $this->assertContains(ProvisioningErrorCategory::RESOURCE_EXHAUSTED, $all);
        $this->assertContains(ProvisioningErrorCategory::VALIDATION, $all);
        $this->assertContains(ProvisioningErrorCategory::CONFLICT, $all);
        $this->assertContains(ProvisioningErrorCategory::PROVIDER_FAULT, $all);
        $this->assertContains(ProvisioningErrorCategory::UNKNOWN, $all);

        $this->assertTrue(ProvisioningErrorCategory::isRetryable(ProvisioningErrorCategory::TRANSIENT_NETWORK));
        $this->assertTrue(ProvisioningErrorCategory::isRetryable(ProvisioningErrorCategory::RATE_LIMITED));
        $this->assertFalse(ProvisioningErrorCategory::isRetryable(ProvisioningErrorCategory::AUTHENTICATION));
        $this->assertFalse(ProvisioningErrorCategory::isRetryable(ProvisioningErrorCategory::VALIDATION));
        $this->assertFalse(ProvisioningErrorCategory::isRetryable(ProvisioningErrorCategory::CONFLICT));
    }

    public function testClassifierDifferentiatesErrorPatterns(): void
    {
        // 1. Transient Network
        $net = ProvisioningErrorClassifier::classify('Connection timed out after 30000ms', 'TIMEOUT', 504);
        $this->assertSame(ProvisioningErrorCategory::TRANSIENT_NETWORK, $net->getCategory());
        $this->assertTrue($net->isRetryable());
        $this->assertSame(60, $net->getSuggestedRetryDelaySeconds());

        // 2. Rate Limit
        $rate = ProvisioningErrorClassifier::classify('Rate limit exceeded', 'RATE_LIMIT', 429);
        $this->assertSame(ProvisioningErrorCategory::RATE_LIMITED, $rate->getCategory());
        $this->assertTrue($rate->isRetryable());
        $this->assertSame(300, $rate->getSuggestedRetryDelaySeconds());

        // 3. Authentication
        $auth = ProvisioningErrorClassifier::classify('Access denied: invalid whm api token', 'UNAUTHORIZED', 401);
        $this->assertSame(ProvisioningErrorCategory::AUTHENTICATION, $auth->getCategory());
        $this->assertFalse($auth->isRetryable());
        $this->assertTrue($auth->requiresAdminIntervention());

        // 4. Resource Conflict
        $conflict = ProvisioningErrorClassifier::classify('Account already exists for domain example.com', 'CONFLICT', 409);
        $this->assertSame(ProvisioningErrorCategory::CONFLICT, $conflict->getCategory());
        $this->assertFalse($conflict->isRetryable());

        // 5. Resource Exhausted
        $exhaust = ProvisioningErrorClassifier::classify('Disk full on /home partition', 'DISK_FULL');
        $this->assertSame(ProvisioningErrorCategory::RESOURCE_EXHAUSTED, $exhaust->getCategory());
        $this->assertFalse($exhaust->isRetryable());

        // 6. Validation
        $valid = ProvisioningErrorClassifier::classify('Invalid username: must be alphanumeric', 'BAD_REQUEST', 422);
        $this->assertSame(ProvisioningErrorCategory::VALIDATION, $valid->getCategory());
        $this->assertFalse($valid->isRetryable());

        // 7. Provider Fault
        $fault = ProvisioningErrorClassifier::classify('Internal server error in cpanel daemon', 'INTERNAL_ERROR', 500);
        $this->assertSame(ProvisioningErrorCategory::PROVIDER_FAULT, $fault->getCategory());
        $this->assertFalse($fault->isRetryable());
    }

    public function testClassifierHandlesExceptionsAndOperationResults(): void
    {
        // Exception
        $ex = new RuntimeException('cURL error 28: Operation timed out');
        $classifiedEx = ProvisioningErrorClassifier::classifyThrowable($ex);
        $this->assertSame(ProvisioningErrorCategory::TRANSIENT_NETWORK, $classifiedEx->getCategory());

        // Operation result
        $res = ProviderOperationResult::failure(
            operationType: ProviderCapability::CREATE_ACCOUNT,
            message: 'User exists on this node',
            errorCode: 'DUPLICATE_USER'
        );
        $classifiedRes = ProvisioningErrorClassifier::classifyResult($res);
        $this->assertSame(ProvisioningErrorCategory::CONFLICT, $classifiedRes->getCategory());
    }

    public function testClientSafeVersusAdminActionableMessages(): void
    {
        $auth = ProvisioningErrorClassification::authentication('WHM token expired on node 1');
        $this->assertStringContainsString('pending review', $auth->getClientSafeMessage());
        $this->assertStringNotContainsString('WHM token', $auth->getClientSafeMessage());
        $this->assertStringContainsString('WHM token expired', $auth->getAdminActionableMessage());
    }

    public function testQueueOperationAndCorrelationIdTracking(): void
    {
        $op = $this->opService->queueOperation(
            serviceId: 10,
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: ['username' => 'testuser', 'domain' => 'test.com'],
            serverId: 2,
            maxAttempts: 3,
            correlationId: 'REQ-ABC-123'
        );

        $this->assertSame(10, $op->getServiceId());
        $this->assertSame('cpanel', $op->getProviderSlug());
        $this->assertSame('create_account', $op->getAction());
        $this->assertSame(2, $op->getServerId());
        $this->assertSame('REQ-ABC-123', $op->getCorrelationId());
        $this->assertSame(ProvisioningOperation::STATUS_QUEUED, $op->getStatus());
        $this->assertSame(0, $op->getAttemptCount());
        $this->assertSame(3, $op->getMaxAttempts());
        $this->assertTrue($op->isQueued());
    }

    public function testRecordSuccessfulAttemptTransitionsToCompleted(): void
    {
        $op = $this->opService->queueOperation(
            serviceId: 11,
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: ['username' => 'user11']
        );

        $res = ProviderOperationResult::success(
            operationType: 'create_account',
            message: 'Account created successfully',
            externalIdentifier: 'ext-user11',
            data: ['account_id' => 99]
        );

        $completedOp = $this->opService->recordAttempt($op->getOperationUuid(), $res);

        $this->assertTrue($completedOp->isCompleted());
        $this->assertSame(1, $completedOp->getAttemptCount());
        $this->assertNull($completedOp->getNextAttemptAt());
        $this->assertSame(['account_id' => 99], $completedOp->getResultData());

        // Verify audit log
        $logs = $this->opService->listAuditLogsForOperation($op->getId());
        $this->assertCount(1, $logs);
        $this->assertTrue($logs[0]->isSuccess());
        $this->assertSame(1, $logs[0]->getAttemptNumber());
    }

    public function testRecordTransientFailureTriggersRetryingState(): void
    {
        $op = $this->opService->queueOperation(
            serviceId: 12,
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: ['username' => 'user12'],
            maxAttempts: 3
        );

        $timeoutRes = ProviderOperationResult::failure(
            operationType: 'create_account',
            message: 'Connection timed out',
            errorCode: 'TIMEOUT'
        );

        $retryingOp = $this->opService->recordAttempt($op->getOperationUuid(), $timeoutRes);

        $this->assertTrue($retryingOp->isRetrying());
        $this->assertSame(1, $retryingOp->getAttemptCount());
        $this->assertNotNull($retryingOp->getNextAttemptAt());
        $this->assertSame(ProvisioningErrorCategory::TRANSIENT_NETWORK, $retryingOp->getErrorClassification()?->getCategory());
        $this->assertTrue($retryingOp->canRetry());

        // Verify audit log
        $logs = $this->opService->listAuditLogsForOperation($op->getId());
        $this->assertCount(1, $logs);
        $this->assertFalse($logs[0]->isSuccess());
        $this->assertSame(ProvisioningErrorCategory::TRANSIENT_NETWORK, $logs[0]->getErrorCategory());
    }

    public function testMaxAttemptsExhaustedTransitionsToFailed(): void
    {
        $op = $this->opService->queueOperation(
            serviceId: 13,
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: ['username' => 'user13'],
            maxAttempts: 2
        );

        $timeoutRes = ProviderOperationResult::failure('create_account', 'Connection timed out');

        // Attempt 1 -> retrying
        $op1 = $this->opService->recordAttempt($op->getOperationUuid(), $timeoutRes);
        $this->assertTrue($op1->isRetrying());
        $this->assertSame(1, $op1->getAttemptCount());

        // Attempt 2 -> max attempts hit -> failed
        $op2 = $this->opService->recordAttempt($op->getOperationUuid(), $timeoutRes);
        $this->assertTrue($op2->isFailed());
        $this->assertSame(2, $op2->getAttemptCount());
        $this->assertNull($op2->getNextAttemptAt());
        $this->assertFalse($op2->canRetry());

        $logs = $this->opService->listAuditLogsForOperation($op->getId());
        $this->assertCount(2, $logs);
    }

    public function testNonRetryableFailureImmediatelyTransitionsToFailed(): void
    {
        $op = $this->opService->queueOperation(
            serviceId: 14,
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: ['username' => 'user14'],
            maxAttempts: 5
        );

        $authRes = ProviderOperationResult::failure('create_account', 'Access denied: invalid token', 'UNAUTHORIZED');

        $failedOp = $this->opService->recordAttempt($op->getOperationUuid(), $authRes);
        $this->assertTrue($failedOp->isFailed());
        $this->assertFalse($failedOp->isRetrying());
        $this->assertSame(1, $failedOp->getAttemptCount());
        $this->assertNull($failedOp->getNextAttemptAt());
        $this->assertSame(ProvisioningErrorCategory::AUTHENTICATION, $failedOp->getErrorClassification()?->getCategory());
    }

    public function testDueRetryOperationsQuery(): void
    {
        $op = $this->opService->queueOperation(
            serviceId: 15,
            providerSlug: 'cpanel',
            action: 'create_account',
            payload: []
        );

        // Record a failure with 60s delay
        $classified = ProvisioningErrorClassification::transientNetwork('timeout', 'TIMEOUT', 60);
        $this->opService->recordAttempt(
            $op->getOperationUuid(),
            ProviderOperationResult::failure('create_account', 'timeout'),
            $classified
        );

        // Immediate check: not due yet
        $dueNow = $this->opService->getDueRetryOperations(date('Y-m-d H:i:s'));
        $this->assertCount(0, $dueNow);

        // Check 2 minutes in future: due
        $futureTime = date('Y-m-d H:i:s', time() + 120);
        $dueFuture = $this->opService->getDueRetryOperations($futureTime);
        $this->assertCount(1, $dueFuture);
        $this->assertSame($op->getId(), $dueFuture[0]->getId());
    }
}
