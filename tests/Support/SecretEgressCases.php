<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait SecretEgressCases
{
    public function testSecurityAuditCannotPersistCredentialsFromNestedPayloads(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $audit = new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);
        $audit->log(7, 'EGRESS_TEST', 'resource:42', payload: ['status' => 'failed', 'details' => ['app.key' => 'synthetic-audit-key', 'metadata' => '{"password":"synthetic-audit-password"}']]);
        $row = $this->db->selectOne("SELECT * FROM security_audit_logs WHERE event_type = 'EGRESS_TEST'");
        self::assertStringNotContainsString('synthetic-audit-key', $row['payload']);
        self::assertStringNotContainsString('synthetic-audit-password', $row['payload']);
        self::assertSame('failed', json_decode($row['payload'], true)['status']);
        self::assertSame('resource:42', $row['target_resource']);
    }
    public function testApiLogsStoreRouteWithoutQueryFragmentOrAuthorityCredentials(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $logger = new \Coleza\Api\Logging\ApiRequestLogger($this->db);
        $logger->log('GET', 'https://user:synthetic-url-password@example.test/api/cron/run?token=synthetic-query-token&page=2#synthetic-fragment', 200, 5, 7);
        $row = $logger->getLogsForKey(7)[0];
        self::assertSame('/api/cron/run', $row['path']); self::assertSame(200, (int) $row['status_code']);
        self::assertStringNotContainsString('synthetic-query-token', json_encode($row));
        self::assertStringNotContainsString('synthetic-url-password', json_encode($row));
    }

    public function testLegacyAuditAndApiReadViewsRedactWithoutRewritingStoredEvidence(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $audit = new \Coleza\Domain\Identity\Audit\AuditLogger($this->db);
        $id = $audit->log(7, 'LEGACY_EGRESS', 'resource:42');
        $raw = '{"password":"synthetic-legacy-password","status":"failed"}';
        $this->db->update('security_audit_logs', ['payload' => $raw], 'id = :id', ['id' => $id]);
        self::assertStringNotContainsString('synthetic-legacy-password', json_encode($audit->getLogsForUser(7)));
        self::assertSame($raw, $this->db->selectOne('SELECT payload FROM security_audit_logs WHERE id = ?', [$id])['payload']);
        $api = new \Coleza\Api\Logging\ApiRequestLogger($this->db); $id = $api->log('GET', '/legacy', 200, 5, 7);
        $this->db->update('api_request_logs', ['path' => '/legacy?token=synthetic-legacy-token'], 'id = :id', ['id' => $id]);
        self::assertSame('/legacy', $api->getLogsForKey(7)[0]['path']);
        self::assertStringContainsString('synthetic-legacy-token', $this->db->selectOne('SELECT path FROM api_request_logs WHERE id = ?', [$id])['path']);
    }

    public function testProviderAuditResponseIsRedactedAndOperationalInputIsNotModified(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $ops = new \Coleza\Domain\Provisioning\Services\ProvisioningOperationService($this->db); $ops->ensureTables();
        $op = $ops->queueOperation(42, 'fixture', 'create', ['username' => 'fixture', 'password' => 'synthetic-operational-password']);
        $result = \Coleza\Domain\Providers\DTO\ProviderOperationResult::success('create', 'Created', 'remote-42', ['username' => 'fixture'], ['access_hash' => 'synthetic-provider-secret', 'status' => 'ok']);
        $ops->recordAttempt($op->getOperationUuid(), $result);
        $row = $this->db->selectOne('SELECT raw_response_json FROM provisioning_audit_logs WHERE operation_id = ?', [$op->getId()]);
        self::assertStringNotContainsString('synthetic-provider-secret', $row['raw_response_json']);
        self::assertSame('ok', json_decode($row['raw_response_json'], true)['status']);
        self::assertSame('synthetic-operational-password', $ops->findOperationByUuid($op->getOperationUuid())->getPayload()['password']);
    }
}
