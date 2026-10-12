<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Automation\Actions\ActionDefinition;
use Coleza\Domain\Automation\Adapters\Service\{ServiceSuspendActionHandler, ServiceUnsuspendActionHandler, ServiceTerminateActionHandler};
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Commerce\Services\ServiceService;
trait AutomationLifecycleBoundaryCases
{
    private function lifecycleFixture(string $type, bool $placed = true): array
    {
        $service = new ServiceService($this->db); $service->ensureTables();
        $record = $service->createService(['user_id' => 1, 'product_id' => 1, 'status' => $type === 'unsuspend' ? 'suspended' : 'active',
            'billing_cycle' => 'monthly', 'recurring_amount_minor' => 1000, 'currency_code' => 'TRY', 'next_due_date' => '2026-12-01']);
        if ($placed) { $service->assignPlacement($record->getId(), ['server_id' => 7, 'status' => 'placed']); }
        $handler = match ($type) {
            'suspend' => new ServiceSuspendActionHandler($service),
            'unsuspend' => new ServiceUnsuspendActionHandler($service),
            'terminate' => new ServiceTerminateActionHandler($service),
        };
        return [$service, $record, $handler];
    }
    private function assertPlacedTargetIsNotLocallyCompleted(string $type): void
    {
        [$service, $record, $handler] = $this->lifecycleFixture($type);
        $result = $handler->execute(new ActionDefinition('a', $handler->getType(), ['service_id' => $record->getId()]), new TriggerContext('event'));
        self::assertTrue($result->isFailed(), 'Provider-backed lifecycle needs a real provider command, not a local success');
        self::assertSame($record->getStatus(), $service->findServiceById($record->getId())->getStatus());
    }
    public function testPlacedSuspendCannotClaimProviderSuccess(): void { $this->assertPlacedTargetIsNotLocallyCompleted('suspend'); }
    public function testPlacedUnsuspendCannotClaimProviderSuccess(): void { $this->assertPlacedTargetIsNotLocallyCompleted('unsuspend'); }
    public function testPlacedTerminateCannotClaimProviderSuccess(): void { $this->assertPlacedTargetIsNotLocallyCompleted('terminate'); }
    public function testLocalCommandsRemainExplicitlyLocalAndKeepOptimisticLocking(): void
    {
        foreach (['suspend', 'unsuspend', 'terminate'] as $type) {
            [$service, $record, $handler] = $this->lifecycleFixture($type, false);
            $result = $handler->execute(new ActionDefinition('a', $handler->getType(), ['service_id' => (string) $record->getId(),
                'expected_lock_version' => (string) $record->getLockVersion()]), new TriggerContext('event'));
            self::assertTrue($result->isSuccess(), (string) $result->getErrorMessage());
            self::assertSame('LOCAL_SERVICE_STATE', $result->getOutput()['effect_scope']);
            self::assertFalse($result->getOutput()['provider_outcome_proven']);
            self::assertSame($record->getLockVersion() + 1, $service->findServiceById($record->getId())->getLockVersion());
        }
    }
    public function testMalformedServiceIdsCannotSelectAnExistingService(): void
    {
        foreach (['suspend', 'unsuspend', 'terminate'] as $type) {
            [$service, $record, $handler] = $this->lifecycleFixture($type, false);
            foreach ([$record->getId() . 'invalid', $record->getId() . '.0', $record->getId() . 'e0', '0' . $record->getId(),
                ' ' . $record->getId(), '+' . $record->getId(), (float) $record->getId(), true, false, [], [1], '99999999999999999999999999999', 0, -1] as $bad) {
                $result = $handler->execute(new ActionDefinition('a', $handler->getType(), ['service_id' => $bad]), new TriggerContext('event'));
                self::assertTrue($result->isFailed());
                self::assertSame($record->getStatus(), $service->findServiceById($record->getId())->getStatus());
                self::assertSame($record->getLockVersion(), $service->findServiceById($record->getId())->getLockVersion());
            }
        }
    }
    public function testMalformedLockVersionCannotDisableOrCoerceConcurrencyCheck(): void
    {
        foreach (['suspend', 'unsuspend', 'terminate'] as $type) {
            [$service, $record, $handler] = $this->lifecycleFixture($type, false);
            foreach (['1invalid', true, 1.0, [1], '01', '1e0', -1, '99999999999999999999999999999'] as $bad) {
                $result = $handler->execute(new ActionDefinition('a', $handler->getType(), ['service_id' => $record->getId(),
                    'expected_lock_version' => $bad]), new TriggerContext('event'));
                self::assertTrue($result->isFailed());
                self::assertSame($record->getLockVersion(), $service->findServiceById($record->getId())->getLockVersion());
            }
        }
    }
    public function testReleasedReservedAndMissingServerPlacementsAreNotAbsenceProof(): void
    {
        foreach (['evicted', 'reserved', 'migrating', 'unassigned'] as $status) {
            [$service, $record, $handler] = $this->lifecycleFixture('suspend');
            $this->db->update('service_placements', ['status' => $status, 'server_id' => null], 'service_id = :id', ['id' => $record->getId()]);
            $result = $handler->execute(new ActionDefinition('a', 'service.suspend', ['service_id' => $record->getId()]), new TriggerContext('event'));
            self::assertSame('PROVIDER_LIFECYCLE_REQUIRED', $result->getOutput()['error_code']);
            self::assertSame('active', $service->findServiceById($record->getId())->getStatus());
        }
    }
    public function testMissingPlacementTableDoesNotSilentlyLookLikeNoRemoteAccount(): void
    {
        [$service, $record, $handler] = $this->lifecycleFixture('suspend', false);
        $this->db->statement('DROP TABLE service_placements');
        $result = $handler->execute(new ActionDefinition('a', 'service.suspend', ['service_id' => $record->getId()]), new TriggerContext('event'));
        self::assertTrue($result->isFailed());
        self::assertSame('active', $service->findServiceById($record->getId())->getStatus());
    }
    public function testStaleValidLockVersionCannotChangeState(): void
    {
        [$service, $record, $handler] = $this->lifecycleFixture('suspend', false);
        $result = $handler->execute(new ActionDefinition('a', 'service.suspend', ['service_id' => $record->getId(), 'expected_lock_version' => 0]), new TriggerContext('event'));
        self::assertTrue($result->isFailed());
        self::assertSame($record->getLockVersion(), $service->findServiceById($record->getId())->getLockVersion());
    }
    public function testNestedContextIdUsesSameStrictResolutionAndCallerRollbackRemainsLocal(): void
    {
        [$service, $record, $handler] = $this->lifecycleFixture('suspend', false);
        $this->db->beginTransaction();
        try {
            $result = $handler->execute(new ActionDefinition('a', 'service.suspend'), new TriggerContext('event', ['service' => ['id' => (string) $record->getId()]]));
            self::assertTrue($result->isSuccess());
        } finally { $this->db->rollBack(); }
        self::assertSame('active', $service->findServiceById($record->getId())->getStatus());
        self::assertSame($record->getLockVersion(), $service->findServiceById($record->getId())->getLockVersion());
    }
    public function testProviderRequiredFailureIsDurableAndNoAutomaticRetryIsGranted(): void
    {
        [$service, $record, $handler] = $this->lifecycleFixture('suspend');
        \Coleza\Domain\Automation\Persistence\AutomationSchema::installSchema($this->db);
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($this->db);
        \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter::installSchema($this->db);
        $registry = new \Coleza\Domain\Automation\Actions\ActionRegistry(); $registry->register($handler);
        $engine = \Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($this->db, $registry);
        $pending = $engine->getApprovalManager()->createRequest('r', 'Suspend', new TriggerContext('event'),
            [new ActionDefinition('a', 'service.suspend', ['service_id' => $record->getId()])], new \Coleza\Domain\Automation\Approval\ApprovalRequirement(), ['delay_seconds' => 0]);
        self::assertFalse($engine->executeApprovedRequest($pending->getId(), 'actor')->isSuccessful());
        $items = (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved();
        self::assertCount(1, $items); self::assertFalse($items[0]['automatic_retry_allowed']);
        self::assertSame('OUTCOME_UNKNOWN', $this->db->selectOne('SELECT status FROM automation_action_attempts')['status']);
        self::assertSame('active', $service->findServiceById($record->getId())->getStatus());
    }
    public function testLegacyRemoteIdentityWithoutPlacementCannotClaimLocalCompletion(): void
    {
        foreach (['username', 'server_name', 'ip_address'] as $field) {
            [$service, $record, $handler] = $this->lifecycleFixture('suspend', false);
            $this->db->update('services', [$field => 'legacy-remote'], 'id = :id', ['id' => $record->getId()]);
            $result = $handler->execute(new ActionDefinition('a', 'service.suspend', ['service_id' => $record->getId()]), new TriggerContext('event'));
            self::assertTrue($result->isFailed());
            self::assertSame('PROVIDER_LIFECYCLE_REQUIRED', $result->getOutput()['error_code']);
            self::assertSame('active', $service->findServiceById($record->getId())->getStatus());
        }
    }
}
