<?php
declare(strict_types=1);
namespace Coleza\Domain\Vault;

use Coleza\Foundation\Queue\{DatabaseQueue, JobInterface, QueuePayloadStoreInterface};
use Coleza\Foundation\Database\Connection;

/** Authenticated job bytes live in Vault; queue metadata contains an owner-bound reference only. */
final class QueuePayloadStore implements QueuePayloadStoreInterface
{
    public const NAMESPACE = 'queue';
    public function __construct(private Connection $db, private ?Encryptor $encryptor = null) {}
    public function resolveKey(): Encryptor { return $this->encryptor ??= ApplicationKey::encryptor(ApplicationKey::configured()); }
    public function prepareKey(): void { $this->resolveKey(); }
    public static function requirements(): array
    {
        return DatabaseQueue::schemaRequirements();
    }
    public static function key(string $table, array $owner): string
    {
        if (!isset(self::requirements()[$table]) || (int) ($owner['id'] ?? 0) < 1 || !is_string($owner['queue'] ?? null) || $owner['queue'] === '') {
            throw new \InvalidArgumentException('Invalid queue payload owner.');
        }
        return hash('sha256', json_encode([$table, (int) $owner['id'], $owner['queue'], 'payload'], JSON_THROW_ON_ERROR));
    }
    public static function assertStorage(Connection $db): void
    {
        SecretStorageUpgrade::assertStorage($db, self::requirements() + VaultService::schemaRequirements()
            + ['security_audit_logs' => ['id', 'actor_user_id', 'event_type', 'target_resource', 'payload']]);
        if (!\Coleza\Domain\Identity\IdentityPersistence::installed($db, self::requirements(), onlyPrimary: ['jobs', 'failed_jobs'])) {
            throw new \LogicException('Install canonical queue storage before use.');
        }
    }
    public function prepare(): void
    {
        $this->resolveKey(); VaultReferenceStore::ensureSchema($this->db); self::assertStorage($this->db);
    }
    public function guard(): void { KeyBinding::guard($this->db, $this->resolveKey()); }
    /** Validate structure/type without activating serialized object hooks, including during maintenance/verification. */
    public static function validate(#[\SensitiveParameter] string $payload): void
    {
        $invalid = false;
        set_error_handler(static function () use (&$invalid): bool { $invalid = true; return true; });
        try { $value = unserialize($payload, ['allowed_classes' => false]); }
        catch (\Throwable) { throw new \RuntimeException('Invalid serialized queue payload.'); }
        finally { restore_error_handler(); }
        $class = is_object($value) ? ((array) $value)['__PHP_Incomplete_Class_Name'] ?? null : null;
        if ($invalid || !is_string($class) || !is_a($class, JobInterface::class, true)) {
            throw new \RuntimeException('Queue payload does not describe an installed job.');
        }
    }
    public function read(string $table, array $owner): string
    {
        $plain = (new VaultReferenceStore($this->db, $this->resolveKey()))->read(self::NAMESPACE, self::key($table, $owner), (string) $owner['payload']);
        self::validate($plain); return $plain;
    }
    public function writeNew(string $table, array $owner, #[\SensitiveParameter] string $plain): string
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Queue owner and payload require an aggregate transaction.'); }
        self::validate($plain); $key = self::key($table, $owner);
        if ($this->db->selectOne('SELECT id FROM vault_secrets WHERE namespace = ? AND secret_key = ?', [self::NAMESPACE, $key]) !== null) {
            throw new \LogicException('Queue payload has a conflicting Vault owner; reconcile explicitly.');
        }
        return (new VaultReferenceStore($this->db, $this->resolveKey()))->write(self::NAMESPACE, $key, $plain);
    }
    public function delete(string $table, array $owner): void
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Queue owner and payload require an aggregate transaction.'); }
        (new VaultReferenceStore($this->db, $this->resolveKey()))->delete(self::NAMESPACE, self::key($table, $owner));
    }
    /** No DDL and no runtime plaintext fallback. Operator stops workers and confirms trusted provenance. */
    public function upgrade(bool $trustedLegacyConfirmed = false): int
    {
        if (!$trustedLegacyConfirmed || $this->db->inTransaction()) { throw new \LogicException('Confirmed standalone maintenance with stopped writers is required.'); }
        $this->resolveKey(); self::assertStorage($this->db);
        return $this->db->transaction(function (): int {
            $this->guard(); $plans = [];
            foreach (self::requirements() as $table => $_) {
                foreach ($this->db->select("SELECT * FROM {$table} ORDER BY id" . $this->db->forUpdate()) as $row) {
                    if (str_starts_with($row['payload'], 'vault:')) { $this->read($table, $row); continue; }
                    self::validate($row['payload']);
                    $plans[] = [$table, $row];
                }
            }
            foreach ($plans as [$table, $row]) {
                $ref = $this->writeNew($table, $row, $row['payload']);
                $data = ['payload' => $ref];
                if ($table === 'failed_jobs') { $data['exception'] = 'Legacy queue failure details withheld.'; }
                $this->db->update($table, $data, 'id = :id', ['id' => $row['id']]);
            }
            return count($plans);
        });
    }
}
