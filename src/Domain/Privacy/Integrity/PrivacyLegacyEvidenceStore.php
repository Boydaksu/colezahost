<?php

declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;

use Coleza\Domain\Privacy\Tombstone\PrivacyTombstone;
use Coleza\Domain\Privacy\Redaction\RedactionAuditRecord;

/** Nonsecret, signed out-of-band evidence survives a database rollback/restore. */
final class PrivacyLegacyEvidenceStore
{
    public function __construct(private readonly string $path, #[\SensitiveParameter] private readonly array $keys = []) {}
    public static function configured(): ?self
    {
        $path = PrivacySigningKey::environment('PRIVACY_LEGACY_EVIDENCE_FILE');
        return $path === '' ? null : new self($path);
    }
    public function key(string $purpose): string { return PrivacySigningKey::resolve($purpose, $this->keys[$purpose] ?? null); }
    private function path(): void
    {
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $this->path) || is_link($this->path) || is_link(dirname($this->path))) {
            throw new \RuntimeException('Use an absolute regular independent privacy evidence path.');
        }
        $parent = realpath(dirname($this->path)); $root = realpath(dirname(__DIR__, 4));
        if ($parent === false || $root === false) { throw new \RuntimeException('Evidence directory must already exist.'); }
        $parent = strtolower(str_replace('\\', '/', $parent)); $root = strtolower(str_replace('\\', '/', $root));
        if ($parent === $root || str_starts_with($parent, $root . '/')) { throw new \RuntimeException('Keep independent privacy evidence outside the application.'); }
    }
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['proofs'] ?? null) || !array_is_list($data['proofs'])) {
            throw new \RuntimeException('Invalid independent privacy evidence inventory.');
        }
        $proofs = [];
        foreach ($data['proofs'] as $proof) {
            if (!is_array($proof) || !in_array($proof['purpose'] ?? null, ['tombstone', 'redaction'], true) || !is_string($proof['subject'] ?? null)) {
                throw new \RuntimeException('Invalid independent privacy proof.');
            }
            $id = $proof['purpose'] . ':' . $proof['subject'];
            if (isset($proofs[$id])) { throw new \RuntimeException('Duplicate independent privacy proof.'); }
            $proofs[$id] = $proof;
        }
        return $proofs;
    }
    private function read(): array
    {
        $this->path();
        if (!file_exists($this->path)) { throw new \RuntimeException('Configured privacy evidence is missing.'); }
        $handle = fopen($this->path, 'rb');
        if ($handle === false) { throw new \RuntimeException('Cannot open privacy evidence.'); }
        try {
            if (!flock($handle, LOCK_SH)) { throw new \RuntimeException('Cannot lock privacy evidence.'); }
            $json = stream_get_contents($handle);
            if ($json === false) { throw new \RuntimeException('Cannot read privacy evidence.'); }
            return $this->decode($json);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    public function find(string $purpose, string $subject): ?array
    {
        $proof = $this->read()[$purpose . ':' . $subject] ?? null;
        if ($proof !== null && !PrivacyLegacyAttestation::verifyProof($proof, $this->key($purpose))) {
            throw new \RuntimeException('Independent privacy evidence signature failed.');
        }
        return $proof;
    }
    public function tombstone(PrivacyTombstone $record): PrivacyTombstone
    {
        if (str_starts_with($record->getIntegritySignature(), PrivacySignature::PREFIX) || $record->getLegacyEvidence() !== null) { return $record; }
        $proof = $this->find('tombstone', (string) $record->getUserId());
        return $proof === null ? $record : $record->withLegacyEvidence($proof);
    }
    public function redaction(RedactionAuditRecord $record): RedactionAuditRecord
    {
        if (str_starts_with($record->getAuditSignature(), PrivacySignature::PREFIX) || $record->getLegacyEvidence() !== null) { return $record; }
        $proof = $this->find('redaction', (string) $record->getId());
        return $proof === null ? $record : $record->withLegacyEvidence($proof);
    }
    /** Maintenance-only append: verifies the full inventory and never replaces an existing decision. */
    public function append(array $incoming, bool $confirmedIndependentEvidence): int
    {
        if (!$confirmedIndependentEvidence || $incoming === []) { throw new \RuntimeException('Independent evidence approval required.'); }
        $this->path();
        foreach ($incoming as $proof) {
            if (!is_array($proof) || !PrivacyLegacyAttestation::verifyProof($proof, $this->key((string) ($proof['purpose'] ?? '')))) {
                throw new \RuntimeException('Unverified independent privacy proof.');
            }
        }
        $staged = [];
        foreach ($incoming as $proof) {
            $id = $proof['purpose'] . ':' . $proof['subject'];
            if (isset($staged[$id])) {
                $old = $staged[$id]; $new = $proof; unset($old['attested_at'], $old['signature'], $new['attested_at'], $new['signature']);
                if (PrivacySignature::encode($old) !== PrivacySignature::encode($new)) { throw new \RuntimeException('Conflicting incoming privacy evidence.'); }
            }
            $staged[$id] = $proof;
        }
        $incoming = array_values($staged);
        $created = false; $handle = false;
        if (!file_exists($this->path)) { $handle = @fopen($this->path, 'x+b'); $created = $handle !== false; }
        if ($handle === false) { $handle = fopen($this->path, 'r+b'); }
        if ($handle === false) { throw new \RuntimeException('Cannot open privacy evidence.'); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('Cannot lock privacy evidence.'); }
            $content = stream_get_contents($handle);
            if ($content === false) { throw new \RuntimeException('Cannot read privacy evidence.'); }
            $existing = $created && $content === '' ? [] : $this->decode($content);
            foreach ($existing as $proof) {
                if (!PrivacyLegacyAttestation::verifyProof($proof, $this->key($proof['purpose']))) { throw new \RuntimeException('Existing privacy evidence failed verification.'); }
            }
            $count = 0;
            foreach ($incoming as $proof) {
                $id = $proof['purpose'] . ':' . $proof['subject'];
                if (isset($existing[$id])) {
                    // Idempotent same source/body/key; time may differ when repeating an approved import.
                    $old = $existing[$id]; $new = $proof; unset($old['attested_at'], $old['signature'], $new['attested_at'], $new['signature']);
                    if (PrivacySignature::encode($old) !== PrivacySignature::encode($new)) { throw new \RuntimeException('Conflicting independent privacy evidence.'); }
                } else { $existing[$id] = $proof; $count++; }
            }
            if ($count > 0) {
                $json = PrivacySignature::encode(['version' => 1, 'proofs' => array_values($existing)]);
                rewind($handle); $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($handle, substr($json, $offset));
                    if ($written === false || $written === 0) { throw new \RuntimeException('Cannot write privacy evidence.'); }
                    $offset += $written;
                }
                if (!ftruncate($handle, strlen($json)) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Cannot flush privacy evidence.'); }
            }
            return $count;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
