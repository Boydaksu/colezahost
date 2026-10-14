<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class VaultUnifiedTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\VaultUnifiedCases;
    public function testFourWritersPreserveEveryVersionAndAudit(): void
    {
        [$vault] = $this->unifiedFixture(); $results = $this->runParallel('vaultRotate', 0);
        self::assertSame(4, count(array_filter($results, static fn(array $r): bool => $r['accepted'])));
        $row = $this->db->selectOne("SELECT * FROM vault_secrets WHERE namespace = 'provider:parallel'");
        self::assertSame(4, (int) $row['version']); self::assertCount(1, $this->db->select('SELECT * FROM vault_secrets'));
        self::assertCount(4, $this->db->select("SELECT * FROM security_audit_logs WHERE event_type IN ('VAULT_SECRET_CREATED', 'VAULT_SECRET_ROTATED')"));
        self::assertStringStartsWith('worker-synthetic-', $vault->getSecret('provider:parallel', 'api_token'));
    }

}
