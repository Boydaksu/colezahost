<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Vault;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Vault\Encryptor;
use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class VaultServiceTest extends TestCase
{
    private Connection $connection;
    private AuditLogger $auditLogger;
    private Encryptor $encryptor;
    private VaultService $vault;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->auditLogger = new AuditLogger($this->connection);
        // 32-byte key
        $this->encryptor = new Encryptor(str_repeat('a', 32));
        $this->vault = new VaultService($this->connection, $this->encryptor, $this->auditLogger);
    }

    public function testEncryptorRoundtrip(): void
    {
        $secret = 'whmcs_api_token_xyz_123456789';
        $encrypted = $this->encryptor->encrypt($secret);

        $this->assertNotSame($secret, $encrypted);
        $decrypted = $this->encryptor->decrypt($encrypted);
        $this->assertSame($secret, $decrypted);
    }

    public function testVaultSetGetAndAuditLog(): void
    {
        $adminUserId = 10;
        $this->vault->setSecret('cpanel', 'api_token', 'my-cpanel-token-123456', actorUserId: $adminUserId);

        $retrieved = $this->vault->getSecret('cpanel', 'api_token', actorUserId: $adminUserId);
        $this->assertSame('my-cpanel-token-123456', $retrieved);

        // Verify audit logs were recorded
        $logs = $this->auditLogger->getLogsForUser($adminUserId);
        $this->assertCount(2, $logs); // 1 create, 1 access
        $this->assertSame('VAULT_SECRET_ACCESSED', $logs[0]['event_type']);
        $this->assertSame('VAULT_SECRET_CREATED', $logs[1]['event_type']);
    }

    public function testSecretRotationIncrementsVersion(): void
    {
        $this->vault->setSecret('stripe', 'secret_key', 'sk_live_v1', actorUserId: 1);
        // Rotate
        $this->vault->setSecret('stripe', 'secret_key', 'sk_live_v2', actorUserId: 1);

        $row = $this->connection->selectOne(
            'SELECT version FROM vault_secrets WHERE namespace = "stripe" AND secret_key = "secret_key"'
        );

        $this->assertSame(2, (int) $row['version']);
        $this->assertSame('sk_live_v2', $this->vault->getSecret('stripe', 'secret_key'));
    }

    public function testMaskedSecretDisplay(): void
    {
        $this->vault->setSecret('smtp', 'password', 'SuperSecretPassPhrase2026');
        $masked = $this->vault->getMaskedSecret('smtp', 'password');

        $this->assertNotNull($masked);
        $this->assertStringStartsWith('Su', $masked);
        $this->assertStringEndsWith('26', $masked);
        $this->assertStringContainsString('••••', $masked);
        $this->assertStringNotContainsString('SecretPassPhrase', $masked);
    }

    public function testDeleteSecret(): void
    {
        $this->vault->setSecret('hetzner', 'token', 'hetzner_token_abc');
        $this->vault->deleteSecret('hetzner', 'token');

        $this->assertNull($this->vault->getSecret('hetzner', 'token'));
    }
}
