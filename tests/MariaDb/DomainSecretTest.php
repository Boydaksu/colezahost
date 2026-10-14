<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class DomainSecretTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\DomainSecretCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testFourConcurrentCreatorsHaveOneOwnerAndNoOrphanSecret(): void
    {
        [$domains] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $results = $this->runParallel('domainSecretDuplicate', $d->getId());
        self::assertCount(1, array_filter($results, static fn($r) => $r['status'] === 'ok')); self::assertCount(3, array_filter($results, static fn($r) => $r['status'] === 'rejected'));
        self::assertCount(1, $this->db->select('SELECT * FROM domain_registrar_operations'));
        self::assertCount(1, $this->db->select("SELECT * FROM vault_secrets WHERE namespace = 'domain'"));
    }
    public function testFourWritersAndReadersKeepOwnerAndCipherConsistent(): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']);
        $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture']);
        $results = $this->runParallel('domainSecretWrite', $d->getId(), (string) $op->getId());
        self::assertSame([], array_values(array_filter($results, static fn($r) => $r['status'] === 'error')));
        self::assertCount(4, array_filter($results, static fn($r) => $r['status'] === 'ok'));
        self::assertStringStartsWith('synthetic-worker-epp-', $domains->findDomainById($d->getId())->getEppCode());
        self::assertSame('synthetic-worker-result', $ops->findById($op->getId())->getResult()['token']);
        self::assertSame(12, (int) $this->db->selectOne("SELECT version FROM vault_secrets WHERE namespace = 'domain' AND secret_key = ?", [\Coleza\Domain\Domains\DomainSecretStore::key('domains', ['id' => $d->getId()], 'epp_code')])['version']);
    }
    public function testOldSnapshotReadsCommittedEppAndOperationResultTogether(): void
    {
        [$domains, $ops] = $this->domainSecrets(); $d = $domains->createDomain(['user_id' => 7, 'domain' => 'secret.example']); $domains->setEppCode($d->getId(), 'old-synthetic-epp');
        $op = $ops->create(['domain_id' => $d->getId(), 'operation_type' => 'transfer', 'idempotency_key' => 'fixture', 'payload' => ['token' => 'synthetic-input']]);
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`'); $peer = new \Coleza\Foundation\Database\Connection($pdo);
        $peerDomains = new \Coleza\Domain\Domains\DomainService($peer, null, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $peerOps = new \Coleza\Domain\Domains\Registrar\Reconciliation\DomainOperationRepository($peer, \Coleza\Tests\Support\SecretFixtureKey::encryptor());
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM domains'); $this->db->select('SELECT * FROM domain_registrar_operations');
            $peerDomains->setEppCode($d->getId(), 'new-synthetic-epp'); $peerOps->markSucceeded($op->getId(), 'remote', ['token' => 'new-synthetic-result']);
            self::assertSame('new-synthetic-epp', $domains->findDomainById($d->getId())->getEppCode());
            self::assertSame(['token' => 'new-synthetic-result'], $ops->findById($op->getId())->getResult()); self::assertTrue($this->db->inTransaction());
        } finally { $this->db->rollBack(); }
        self::assertSame('new-synthetic-epp', $peerDomains->findDomainById($d->getId())->getEppCode());
    }
}
