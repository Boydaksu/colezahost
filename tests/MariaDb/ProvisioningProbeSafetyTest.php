<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ProvisioningProbeSafetyTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ProvisioningProbeSafetyCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testFourWorkersCanCommitOnlyOneCompletionAndAudit(): void
    {
        [, , $op, $services, $ops] = $this->probeFixture();
        $results = $this->runParallel('provisionReconcile', 0, $op->getOperationUuid());
        self::assertCount(1, array_filter($results, static fn(array $r): bool => $r['status'] === 'completed'));
        self::assertSame('active', $services->findServiceById($op->getServiceId())->getStatus());
        self::assertSame(1, $ops->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
        self::assertCount(1, $ops->listAuditLogsForOperation($op->getId()));
    }
    public function testOldSnapshotCannotReplaceCommittedReconciliation(): void
    {
        [, , $op, , $ops] = $this->probeFixture();
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $other = new \Coleza\Foundation\Database\Connection($pdo);
        $otherOps = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($other, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM provisioning_operations');
            $otherOps->recordAttempt($op->getOperationUuid(), \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create_account', 'Other worker completed'));
            try { $ops->commitReconciliation($op, static fn() => throw new \RuntimeException('Stale callback ran')); self::fail('Stale snapshot accepted'); }
            catch (\LogicException) { self::assertSame('completed', $ops->findOperationByUuid($op->getOperationUuid())->getStatus()); }
        } finally { $this->db->rollBack(); }
        self::assertSame(1, $otherOps->findOperationByUuid($op->getOperationUuid())->getAttemptCount());
    }
}
