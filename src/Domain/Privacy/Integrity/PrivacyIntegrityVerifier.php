<?php

declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;

use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Vault\{KeyBinding, SecretStorageUpgrade};
use Coleza\Domain\Privacy\Tombstone\{PrivacyTombstoneService, FileTombstoneStore, TombstoneStoreInterface, BackupRestoreReconciliationService};
use Coleza\Domain\Privacy\Redaction\RedactionAuditRecord;

/** Read-only verification; no DDL, binding creation, record mutation or access audit. Keep restore writers stopped. */
final class PrivacyIntegrityVerifier
{
    private array $resolvedKeys = [];
    private ?TombstoneStoreInterface $restoreStore=null;
    public function __construct(private readonly Connection $db, #[\SensitiveParameter] private readonly array $keys = [],
        private readonly ?TombstoneStoreInterface $store = null, private readonly ?PrivacyLegacyEvidenceStore $legacyEvidence = null) {}
    public function forDatabase(Connection $db): self { return new self($db, $this->keys, $this->store, $this->legacyEvidence); }
    private function key(string $purpose): string { return $this->resolvedKeys[$purpose] ??= PrivacySigningKey::resolve($purpose, $this->keys[$purpose] ?? null); }
    private function fileStore(): ?TombstoneStoreInterface
    {
        if($this->restoreStore!==null){return $this->restoreStore;}
        if ($this->store !== null) { return $this->store; }
        $path = PrivacySigningKey::environment('PRIVACY_TOMBSTONE_STORE_FILE');
        if ($path === '') { return null; }
        if (!is_file($path)) { throw new \RuntimeException('Configured out-of-band privacy tombstone inventory is missing.'); }
        return new FileTombstoneStore($path, $this->key('tombstone'), $this->legacyEvidence);
    }
    public function required(): bool
    {
        if ($this->store !== null || PrivacySigningKey::environment('PRIVACY_TOMBSTONE_STORE_FILE') !== ''
            || $this->legacyEvidence !== null || PrivacySigningKey::environment('PRIVACY_LEGACY_EVIDENCE_FILE') !== '') { return true; }
        foreach (['privacy_tombstones', 'privacy_redaction_audits', PrivacyKeyBinding::TABLE] as $table) {
            if (KeyBinding::tableExists($this->db, $table) && $this->db->selectOne("SELECT 1 FROM {$table} LIMIT 1") !== null) { return true; }
        }
        return false;
    }
    private function tombstones(): PrivacyTombstoneService
    {
        return new PrivacyTombstoneService($this->db, $this->fileStore(), secretKey: $this->key('tombstone'), salt: $this->key('email'), legacyEvidence: $this->legacyEvidence);
    }
    public function reconciliation(): BackupRestoreReconciliationService { return new BackupRestoreReconciliationService($this->db, $this->tombstones()); }
    public function assertRestoreInventoryAvailable(): void
    {
        $store=$this->fileStore();
        if($store===null){throw new \RuntimeException('Authoritative out-of-band tombstone inventory is required before restoring users.');}
        $store->assertAvailableForRestore();
        $this->restoreStore=$store;
    }
    public function verify(): array
    {
        $this->resolvedKeys = []; // Recheck protected files once per verification, then reuse within this inventory.

        $bindings = PrivacyKeyBinding::rows($this->db); $resolved = []; $bound = [];
        foreach ($bindings as $row) {
            $purpose = $row['purpose']; $resolved[$purpose] = $this->key($purpose);
            PrivacyKeyBinding::validate($row, $purpose, $resolved[$purpose]); $bound[] = $purpose;
        }
        $counts = ['bindings' => count($bindings), 'tombstones' => 0, 'redaction_audits' => 0, 'legacy_proofs' => 0];
        $hasTombstones = KeyBinding::tableExists($this->db, 'privacy_tombstones');
        if ($hasTombstones) { SecretStorageUpgrade::assertStorage($this->db, ['privacy_tombstones' => ['id', 'user_id', 'user_email_hash', 'erasure_type', 'reason', 'erasure_checksum', 'metadata', 'tombstoned_at', 'integrity_signature']]); }
        $hasRows = $hasTombstones && $this->db->selectOne('SELECT 1 FROM privacy_tombstones LIMIT 1') !== null;
        $file = $this->fileStore(); $records = [];
        if ($hasRows || $file !== null) {
            $resolved['tombstone'] = $this->key('tombstone'); $resolved['email'] = $this->key('email');
            $records = $this->tombstones()->inspectTombstones(); $counts['tombstones'] = count($records);
        }
        $auditIds = [];
        if (KeyBinding::tableExists($this->db, 'privacy_redaction_audits')) {
            SecretStorageUpgrade::assertStorage($this->db, ['privacy_redaction_audits' => ['id', 'user_id', 'domain_name', 'action_type', 'records_affected', 'redacted_fields', 'pre_checksum', 'post_checksum', 'verified_clean', 'audited_at', 'audited_by', 'audit_signature']]);
            foreach ($this->db->select('SELECT * FROM privacy_redaction_audits ORDER BY id') as $row) {
                $resolved['redaction'] = $this->key('redaction'); $record = RedactionAuditRecord::fromArray($row);
                $evidence = $this->legacyEvidence ?? PrivacyLegacyEvidenceStore::configured(); $record = $evidence?->redaction($record) ?? $record;
                if (!$record->verifySignature($resolved['redaction'])) { throw new \RuntimeException('Privacy audit verification failed.'); }
                $auditIds[(string) $record->getId()] = true; $counts['redaction_audits']++;
            }
        }
        $evidence = $this->legacyEvidence ?? PrivacyLegacyEvidenceStore::configured();
        foreach ($evidence?->proofs() ?? [] as $proof) {
            $resolved[$proof['purpose']] = $this->key($proof['purpose']);
            if (!PrivacyLegacyAttestation::verifyProof($proof, $resolved[$proof['purpose']])
                || ($proof['purpose'] === 'tombstone' ? !isset($records[(int) $proof['subject']]) : !isset($auditIds[$proof['subject']]))) {
                throw new \RuntimeException('Privacy evidence is invalid or its original record is missing; recover the original inventory.');
            }
            $counts['legacy_proofs']++;
        }
        $ids = array_map(PrivacySigningKey::id(...), $resolved);
        if (count($ids) !== count(array_unique($ids))) { throw new \RuntimeException('Privacy purpose keys must remain independent.'); }
        return ['counts' => $counts, 'bound_purposes' => $bound, 'key_ids' => $ids];
    }
    /** Explicit bootstrap for existing verified installations; does not rotate or overwrite a key. */
    public function bind(bool $maintenanceConfirmed = false): array
    {
        if (!$maintenanceConfirmed || $this->db->inTransaction()) { throw new \LogicException('Privacy binding requires confirmed standalone maintenance.'); }
        $keys = [];
        foreach (array_keys(PrivacySigningKey::FILES) as $purpose) { $keys[$purpose] = $this->key($purpose); }
        if (count(array_unique(array_map(PrivacySigningKey::id(...), $keys))) !== 3) { throw new \RuntimeException('Privacy keys must be independent.'); }
        PrivacyKeyBinding::ensure($this->db);
        return $this->db->transaction(function () use ($keys): array {
            PrivacyKeyBinding::guard($this->db, $keys, fn () => $this->verify());
            return $this->verify();
        });
    }
}
