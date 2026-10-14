<?php

declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;

use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Privacy\Tombstone\PrivacyTombstone;
use Coleza\Domain\Privacy\Redaction\RedactionAuditRecord;

/** Explicit independent review admission, not an automatic reseal of the current database. */
final class PrivacyLegacyMaintenance
{
    public function __construct(private readonly Connection $db, private readonly PrivacyLegacyEvidenceStore $evidence) {}
    public function attest(string $manifest, string $expectedSha256, string $provenance, bool $confirmedIndependentEvidence,
        ?string $legacyTombstoneFile = null): int
    {
        if (!$confirmedIndependentEvidence || $this->db->inTransaction() || trim($provenance) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1 || !hash_equals(hash('sha256', $manifest), $expectedSha256)) {
            throw new \RuntimeException('Confirmed independent source digest, provenance and standalone maintenance required.');
        }
        $decoded = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !is_array($decoded['records'] ?? null)
            || !array_is_list($decoded['records']) || $decoded['records'] === []) { throw new \RuntimeException('Invalid independent review manifest.'); }
        $entries = $decoded['records']; $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !in_array($entry['purpose'] ?? null, ['tombstone', 'redaction'], true)
                || !in_array($entry['source'] ?? null, ['database', 'file'], true) || !is_array($entry['data'] ?? null)
                || ($entry['source'] === 'file' && ($entry['purpose'] !== 'tombstone' || $legacyTombstoneFile === null))) {
                throw new \RuntimeException('Invalid independent review entry.');
            }
            $record = $this->record($entry['purpose'], $entry['data']);
            $id = $entry['source'] . ':' . $entry['purpose'] . ':' . PrivacyLegacyAttestation::subject($record);
            if (isset($seen[$id])) { throw new \RuntimeException('Duplicate independent review entry.'); }
            $seen[$id] = true;
            $this->evidence->key($entry['purpose']); // Validate all required keys before transaction/file effects.
        }
        return $this->db->transaction(function () use ($entries, $expectedSha256, $provenance, $legacyTombstoneFile): int {
            $proofs = [];
            foreach ($entries as $entry) {
                if ($entry['source'] !== 'database') { continue; }
                $table = $entry['purpose'] === 'tombstone' ? 'privacy_tombstones' : 'privacy_redaction_audits';
                $column = $entry['purpose'] === 'tombstone' ? 'user_id' : 'id';
                $row = $this->db->selectOne("SELECT * FROM {$table} WHERE {$column} = ?" . $this->db->forUpdate(), [$entry['data'][$column]]);
                if ($row === null || PrivacySignature::encode($row) !== PrivacySignature::encode($entry['data'])) {
                    throw new \RuntimeException('Current privacy row differs from independently reviewed source.');
                }
                $proofs[] = $this->proof($entry, $expectedSha256, $provenance);
            }
            $handle = null;
            try {
                $fileEntries = array_filter($entries, static fn (array $entry): bool => $entry['source'] === 'file');
                if ($fileEntries !== []) {
                    if (is_link($legacyTombstoneFile) || !is_file($legacyTombstoneFile)) { throw new \RuntimeException('Use an existing regular legacy tombstone file.'); }
                    $handle = fopen($legacyTombstoneFile, 'rb');
                    if ($handle === false || !flock($handle, LOCK_SH)) { throw new \RuntimeException('Cannot lock legacy tombstone source.'); }
                    $json = stream_get_contents($handle);
                    if ($json === false || !str_starts_with(ltrim($json), '[')) { throw new \RuntimeException('Invalid legacy tombstone source.'); }
                    $items = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($items) || !array_is_list($items)) { throw new \RuntimeException('Invalid legacy tombstone source.'); }
                    $inventory = [];
                    foreach ($items as $item) {
                        if (!is_array($item) || !isset($item['user_id']) || isset($inventory[$item['user_id']])) { throw new \RuntimeException('Duplicate or malformed legacy source.'); }
                        $inventory[$item['user_id']] = $item;
                    }
                    foreach ($fileEntries as $entry) {
                        $item = $inventory[$entry['data']['user_id']] ?? null;
                        if ($item === null || PrivacySignature::encode($item) !== PrivacySignature::encode($entry['data'])) {
                            throw new \RuntimeException('Legacy file differs from independently reviewed source.');
                        }
                        $proofs[] = $this->proof($entry, $expectedSha256, $provenance);
                    }
                }
                return $this->evidence->append($proofs, true);
            } finally { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
        });
    }
    private function record(string $purpose, array $data): PrivacyTombstone|RedactionAuditRecord
    {
        $required = $purpose === 'tombstone'
            ? ['user_id', 'user_email_hash', 'erasure_type', 'reason', 'erasure_checksum', 'metadata', 'tombstoned_at', 'integrity_signature']
            : ['id', 'user_id', 'domain_name', 'action_type', 'records_affected', 'redacted_fields', 'pre_checksum', 'post_checksum', 'verified_clean', 'audited_at', 'audited_by', 'audit_signature'];
        foreach ($required as $field) { if (!array_key_exists($field, $data)) { throw new \RuntimeException('Incomplete independently reviewed record.'); } }
        if (isset($data['legacy_evidence']) || (int) $data['user_id'] <= 0 || ($purpose === 'redaction' && (int) $data['id'] <= 0)) {
            throw new \RuntimeException('Invalid legacy record identity.');
        }
        $signature = (string) $data[$purpose === 'tombstone' ? 'integrity_signature' : 'audit_signature'];
        if ($signature !== '' && preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) { throw new \RuntimeException('Only explicit legacy records require attestation.'); }
        return $purpose === 'tombstone' ? PrivacyTombstone::fromArray($data) : RedactionAuditRecord::fromArray($data);
    }
    private function proof(array $entry, string $sha256, string $provenance): array
    {
        $record = $this->record($entry['purpose'], $entry['data']);
        $scheme = $entry['email_hash_scheme'] ?? null;
        if (!is_string($scheme) || ($entry['purpose'] === 'tombstone' && $scheme === 'legacy-public-v1'
            && preg_match('/^[a-f0-9]{64}$/D', $record->getUserEmailHash()) !== 1)) { throw new \RuntimeException('Explicit legacy email mapping is required.'); }
        return PrivacyLegacyAttestation::issue($entry['purpose'], $record, $sha256, $provenance, $scheme, $this->evidence->key($entry['purpose']));
    }
}
