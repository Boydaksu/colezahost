<?php

declare(strict_types=1);
namespace Coleza\Domain\Vault;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Identity\Audit\AuditLogger;

final class VaultReferenceStore
{
    public function __construct(private Connection $db, private ?Encryptor $encryptor = null) {}
    public static function ensureSchema(Connection $db, bool $installation = false): void
    {
        if ($db->getPdo()->inTransaction() && !$installation) {
            SecretStorageUpgrade::assertStorage($db, VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        }
        VaultService::ensureSchema($db, $installation); (new AuditLogger($db))->ensureTable();
    }
    private function vault(): VaultService
    {
        $key = $this->encryptor ??= ApplicationKey::encryptor(getenv('APP_KEY') ?: null);
        SecretStorageUpgrade::assertStorage($this->db, VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        return new VaultService($this->db, $key, new AuditLogger($this->db));
    }
    public static function reference(string $key): string { return 'vault:v1:' . $key; }
    public function write(string $namespace, string $key, #[\SensitiveParameter] string $plain, ?int $actor = null): string
    {
        $this->vault()->setSecret($namespace, $key, $plain, $actor); return self::reference($key);
    }
    public function read(string $namespace, string $key, string $reference): string
    {
        if (!hash_equals(self::reference($key), $reference)) { throw new \RuntimeException('Vault reference does not match owning record.'); }
        return $this->vault()->getSecret($namespace, $key) ?? throw new \RuntimeException('Referenced Vault secret is missing.');
    }
    public function delete(string $namespace, string $key): void { $this->vault()->deleteSecret($namespace, $key); }
}
