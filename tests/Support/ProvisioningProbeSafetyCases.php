<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Commerce\Services\{ServiceService, ServiceStateMachine};
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Domain\Providers\Cpanel\{CpanelMemoryTransport, CpanelProvider};
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Provisioning\Services\ProvisioningOperationService;
use Coleza\Domain\Provisioning\Reconciliation\UncertainResponseReconciliationService;

trait ProvisioningProbeSafetyCases
{
    private function probeFixture(array $payload = ['username' => 'fixture', 'domain' => 'fixture.test', 'package' => 'starter'], string $action = 'create_account'): array
    {
        $services = new ServiceService($this->db); $services->ensureTables();
        $servers = new ServerService($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()); $servers->ensureTables();
        $ops = new ProvisioningOperationService($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor()); $ops->ensureTables();
        $server = $servers->createServer(['name' => 'Fixture', 'hostname' => 'fixture.test', 'ip_address' => '10.0.0.1', 'provider_slug' => 'cpanel',
            'auth_type' => 'api_token', 'auth_secret' => 'local-fixture-only', 'status' => 'active']);
        $service = $services->createService(['user_id' => 1, 'product_id' => 1, 'status' => ServiceStateMachine::STATUS_PENDING,
            'billing_cycle' => 'monthly', 'recurring_amount_minor' => 1000, 'currency_code' => 'TRY', 'registration_date' => '2026-10-12',
            'next_due_date' => '2026-11-12', 'domain' => 'fixture.test']);
        $op = $ops->queueOperation($service->getId(), 'cpanel', $action, $payload, $server->getId());
        $transport = new CpanelMemoryTransport();
        $probe = new UncertainResponseReconciliationService($servers, new ProviderRegistry([new CpanelProvider($transport)]), $services, $ops);
        return [$probe, $transport, $op, $services, $ops];
    }
    public function testMissingTargetIsUnknownRatherThanAbsent(): void
    {
        [$probe, , $op] = $this->probeFixture(['domain' => 'fixture.test', 'package' => 'starter']);
        $result = $probe->reconcile($op);
        self::assertFalse($result->isNotFound(), 'Missing target is not proof of remote absence');
    }
    public function testSameUsernameWithWrongDomainCannotCompleteLocalService(): void
    {
        [$probe, $transport, $op, $services, $ops] = $this->probeFixture();
        $transport->stageResponse('accountsummary', 200, ['metadata' => ['result' => 1], 'data' => ['acct' => [
            ['user' => 'fixture', 'domain' => 'other.test', 'plan' => 'starter', 'ip' => '10.0.0.1', 'suspended' => 0]]]]);
        self::assertFalse($probe->reconcile($op)->isExists(), 'Foreign account cannot be adopted as completed');
        self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
        self::assertSame('queued', $ops->findOperationByUuid($op->getOperationUuid())->getStatus());
    }
    public function testApiErrorIsUnknownRatherThanAuthoritativeAbsence(): void
    {
        [$probe, $transport, $op] = $this->probeFixture();
        $transport->stageResponse('accountsummary', 200, ['metadata' => ['result' => 0, 'reason' => 'Not authorized or not found']]);
        self::assertFalse($probe->reconcile($op)->isNotFound());
    }
    private function stageMatching(CpanelMemoryTransport $transport, array $changes = []): void
    {
        $transport->stageResponse('accountsummary', 200, ['metadata' => ['result' => 1], 'data' => ['acct' => [array_replace(
            ['user' => 'fixture', 'domain' => 'fixture.test', 'plan' => 'starter', 'ip' => '10.0.0.1', 'suspended' => 0], $changes)]]]);
    }
    public function testMatchingActiveAccountCommitsLocalStateAndSanitizedEvidence(): void
    {
        [$probe, $transport, $op, $services, $ops] = $this->probeFixture();
        $this->stageMatching($transport, ['password' => 'must-not-store', 'email' => 'private@fixture.test']);
        self::assertTrue($probe->reconcile($op)->isExists());
        self::assertSame('active', $services->findServiceById($op->getServiceId())->getStatus());
        $stored = $ops->findOperationByUuid($op->getOperationUuid());
        self::assertSame('completed', $stored->getStatus());
        self::assertFalse($stored->getResultData()['historical_operation_applied_proven']);
        self::assertFalse($stored->getResultData()['automatic_retry_allowed']);
        self::assertSame('ACCOUNT_POSTCONDITION', $stored->getResultData()['evidence_type']);
        self::assertStringNotContainsString('must-not-store', json_encode($stored->getResultData()));
        self::assertSame(1, $stored->getAttemptCount());
        self::assertCount(1, $ops->listAuditLogsForOperation($op->getId()));
        $rawAudit = $this->db->selectOne('SELECT raw_response_json FROM provisioning_audit_logs WHERE operation_id = ?', [$op->getId()])['raw_response_json'];
        self::assertStringContainsString('ACCOUNT_POSTCONDITION', $rawAudit);
        self::assertStringNotContainsString('must-not-store', $rawAudit);
    }
    public function testUnknownObservationPersistsWithoutChangingAttemptBudgetOrState(): void
    {
        [$probe, , $op, $services, $ops] = $this->probeFixture(['domain' => 'fixture.test']);
        self::assertTrue($probe->reconcile($op)->isUnknown());
        $fresh = new ProvisioningOperationService($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        self::assertSame('queued', $fresh->findOperationByUuid($op->getOperationUuid())->getStatus());
        self::assertSame(0, $fresh->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
        self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
        $row = $this->db->selectOne("SELECT * FROM provisioning_audit_logs WHERE operation_id = ?", [$op->getId()]);
        $evidence = json_decode($row['raw_response_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('reconciliation', $row['action']);
        self::assertSame('reconciliation_required', $evidence['status']);
        self::assertFalse($evidence['automatic_retry_allowed']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $evidence['input_fingerprint']);
    }
    public function testSuspendedWrongPlanAndBadIpAreNotHealthyCompletion(): void
    {
        [$probe, $transport, $op, $services, $ops] = $this->probeFixture();
        foreach ([['suspended' => 1], ['plan' => 'other'], ['ip' => 'bad']] as $change) {
            $this->stageMatching($transport, $change);
            self::assertTrue($probe->reconcile($op)->isUnknown());
            self::assertSame('queued', $ops->findOperationByUuid($op->getOperationUuid())->getStatus());
        }
        self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
    }
    public function testMalformedMultipleAndWrongUserResponsesNeverProveAbsence(): void
    {
        [$probe, $transport, $op, , $ops] = $this->probeFixture();
        $base = ['user' => 'fixture', 'domain' => 'fixture.test', 'plan' => 'starter', 'ip' => '10.0.0.1', 'suspended' => 0];
        foreach ([[], [$base, $base], [array_replace($base, ['user' => 'foreign'])], [array_replace($base, ['suspended' => true])], [array_diff_key($base, ['suspended' => 0])]] as $accounts) {
            $transport->stageResponse('accountsummary', 200, ['metadata' => ['result' => 1], 'data' => ['acct' => $accounts]]);
            $result = $probe->reconcile($op);
            self::assertFalse($result->isExists()); self::assertFalse($result->isNotFound());
        }
        self::assertSame('queued', $ops->findOperationByUuid($op->getOperationUuid())->getStatus());
    }
    public function testHttpErrorsWithSuccessLookingBodyDoNotReconcile(): void
    {
        [$probe, $transport, $op] = $this->probeFixture();
        foreach ([302, 400, 401, 403, 404, 503] as $code) {
            $transport->stageResponse('accountsummary', $code, ['metadata' => ['result' => 1], 'data' => ['acct' => [
                ['user' => 'fixture', 'domain' => 'fixture.test', 'plan' => 'starter', 'ip' => '10.0.0.1', 'suspended' => 0]]]]);
            self::assertFalse($probe->reconcile($op)->isExists());
        }
    }
    public function testUnsupportedActionDoesNotProbeOrCreateAnything(): void
    {
        [$probe, $transport, $op] = $this->probeFixture(action: 'suspend_account');
        self::assertTrue($probe->reconcile($op)->isUnknown());
        self::assertSame([], $transport->getRecordedRequests());
    }
    private function triggerFailure(string $table, string $event): void
    {
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reconciliation_fail BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'"
            : "CREATE TRIGGER reconciliation_fail BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    }
    public function testAuditFailureRollsBackPlacementActivationAndCompletion(): void
    {
        [$probe, $transport, $op, $services, $ops] = $this->probeFixture();
        $this->stageMatching($transport); $this->triggerFailure('provisioning_audit_logs', 'INSERT');
        try { $probe->reconcile($op); self::fail('Audit failure ignored'); }
        catch (\PDOException) {
            self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
            self::assertSame('queued', $ops->findOperationByUuid($op->getOperationUuid())->getStatus());
            self::assertSame(0, $ops->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
            self::assertNull($services->getPlacementForService($op->getServiceId()));
        }
        $this->db->statement('DROP TRIGGER reconciliation_fail');
        self::assertTrue($probe->reconcile($op)->isExists());
    }
    public function testUnknownAuditFailureDoesNotChangePendingState(): void
    {
        [$probe, , $op, $services, $ops] = $this->probeFixture([]);
        $this->triggerFailure('provisioning_audit_logs', 'INSERT');
        try { $probe->reconcile($op); self::fail('Observation audit failure ignored'); }
        catch (\PDOException) { self::assertSame('queued', $ops->findOperationByUuid($op->getOperationUuid())->getStatus()); }
        self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
    }
    public function testStaleOperationCannotOverwriteNewerDecision(): void
    {
        [$probe, $transport, $op, $services, $ops] = $this->probeFixture();
        $this->stageMatching($transport);
        $this->db->update('provisioning_operations', ['status' => 'failed'], 'id = :id', ['id' => $op->getId()]);
        try { $probe->reconcile($op); self::fail('Stale operation accepted'); }
        catch (\LogicException) { self::assertSame('failed', $ops->findOperationByUuid($op->getOperationUuid())->getStatus()); }
        self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
        self::assertCount(0, $ops->listAuditLogsForOperation($op->getId()));
    }
    public function testCallerTransactionRefusedBeforeRemoteRead(): void
    {
        [$probe, $transport, $op] = $this->probeFixture();
        $this->stageMatching($transport);
        $this->db->beginTransaction();
        try {
            try { $probe->reconcile($op); self::fail('Remote read executed inside caller transaction'); }
            catch (\LogicException) { self::assertSame([], $transport->getRecordedRequests()); }
        } finally { $this->db->rollBack(); }
    }
    public function testRetryRunnerDoesNotExecuteOnUnknownResult(): void
    {
        [$probe, $transport, $op, $services, $ops] = $this->probeFixture(['domain' => 'fixture.test']);
        $servers = new ServerService($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $workflow = new \Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow($services, $servers,
            new \Coleza\Domain\Servers\Placement\PlacementEngine($servers),
            new \Coleza\Domain\Servers\Capacity\Services\CapacityReservationService($this->db, $servers),
            new ProviderRegistry([new CpanelProvider($transport)]), $ops);
        $runner = new \Coleza\Domain\Provisioning\Services\ProvisioningRetryRunner($ops, $probe, $workflow);
        self::assertSame('reconciliation_required', $runner->processSingleOperation($op)['outcome']);
        self::assertSame([], $transport->getRecordedRequests());
        self::assertSame(0, $ops->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
    }
    public function testMissingServerBindingIsNotAbsence(): void
    {
        [$probe, $transport, $op, , $ops] = $this->probeFixture();
        $op = $ops->queueOperation($op->getServiceId(), $op->getProviderSlug(), $op->getAction(), $op->getPayload(), null);
        self::assertTrue($probe->reconcile($ops->findOperationByUuid($op->getOperationUuid()))->isUnknown());
        self::assertSame([], $transport->getRecordedRequests());
    }
    public function testUnavailableProviderIsNotAbsence(): void
    {
        [$probe, $transport, $op, , $ops] = $this->probeFixture();
        $op = $ops->queueOperation($op->getServiceId(), 'missing', $op->getAction(), $op->getPayload(), $op->getServerId());
        $this->db->update('servers', ['provider_slug' => 'missing'], 'id = :id', ['id' => $op->getServerId()]);
        self::assertTrue($probe->reconcile($ops->findOperationByUuid($op->getOperationUuid()))->isUnknown());
        self::assertSame([], $transport->getRecordedRequests());
    }
    public function testServerProviderMismatchDoesNotReadAnotherProvider(): void
    {
        [$probe, $transport, $op] = $this->probeFixture();
        $this->db->update('servers', ['provider_slug' => 'other'], 'id = :id', ['id' => $op->getServerId()]);
        self::assertTrue($probe->reconcile($op)->isUnknown());
        self::assertSame([], $transport->getRecordedRequests());
    }
    public function testChangedLocalDomainDoesNotGetActivated(): void
    {
        [$probe, $transport, $op, $services] = $this->probeFixture();
        $this->stageMatching($transport);
        $this->db->update('services', ['domain' => 'other.test'], 'id = :id', ['id' => $op->getServiceId()]);
        self::assertTrue($probe->reconcile($op)->isUnknown());
        self::assertSame('pending', $services->findServiceById($op->getServiceId())->getStatus());
    }
    public function testErrorTextAndRawSecretsAreNotCopiedToObservation(): void
    {
        [$probe, $transport, $op] = $this->probeFixture();
        $transport->stageResponse('accountsummary', 200, ['metadata' => ['result' => 0, 'reason' => 'secret-provider-reason'], 'token' => 'secret-provider-token']);
        $probe->reconcile($op);
        $row = $this->db->selectOne('SELECT raw_response_json FROM provisioning_audit_logs WHERE operation_id = ?', [$op->getId()]);
        self::assertStringNotContainsString('secret-provider-', $row['raw_response_json']);
        self::assertStringNotContainsString('local-fixture-only', $row['raw_response_json']);
    }
    public function testAlreadyCompletedOperationDoesNotProbeOrAppendAnotherAttempt(): void
    {
        [$probe, $transport, $op, , $ops] = $this->probeFixture();
        $this->stageMatching($transport); $probe->reconcile($op);
        $beforeRequests = count($transport->getRecordedRequests());
        self::assertTrue($probe->reconcile($ops->findOperationByUuid($op->getOperationUuid()))->isUnknown());
        self::assertSame($beforeRequests, count($transport->getRecordedRequests()));
        self::assertCount(1, $ops->listAuditLogsForOperation($op->getId()));
        self::assertSame(1, $ops->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
    }
    public function testUnknownAutomaticRetryIsHeldInsteadOfPollingForever(): void
    {
        [$probe, , $op, , $ops] = $this->probeFixture(['domain' => 'fixture.test']);
        $this->db->update('provisioning_operations', ['status' => 'retrying', 'next_attempt_at' => '2026-10-01 00:00:00'], 'id = :id', ['id' => $op->getId()]);
        self::assertTrue($probe->reconcile($ops->findOperationByUuid($op->getOperationUuid()))->isUnknown());
        self::assertSame([], $ops->getDueRetryOperations());
        self::assertSame(0, $ops->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
    }
    public function testHeldOperationBlocksFreshWorkflowCreate(): void
    {
        [, $transport, $op, $services, $ops] = $this->probeFixture();
        $this->db->update('provisioning_operations', ['status' => 'reconciliation_required'], 'id = :id', ['id' => $op->getId()]);
        $servers = new ServerService($this->db, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $workflow = new \Coleza\Domain\Provisioning\Workflows\HostingProvisioningWorkflow($services, $servers,
            new \Coleza\Domain\Servers\Placement\PlacementEngine($servers),
            new \Coleza\Domain\Servers\Capacity\Services\CapacityReservationService($this->db, $servers),
            new ProviderRegistry([new CpanelProvider($transport)]), $ops);
        $result = $workflow->execute(new \Coleza\Domain\Provisioning\Workflows\HostingProvisioningRequest($op->getServiceId(),
            domain: 'fixture.test', username: 'fixture', packageIdentifier: 'starter'));
        self::assertSame('RECONCILIATION_REQUIRED', $result->getErrorCode());
        self::assertSame([], $transport->getRecordedRequests());
        self::assertCount(1, $ops->listOperationsForService($op->getServiceId()));
    }
}
