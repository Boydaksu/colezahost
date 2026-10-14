<?php

declare(strict_types=1);
namespace Coleza\Domain\Privacy\Integrity;

use Coleza\Domain\Privacy\Tombstone\PrivacyTombstone;
use Coleza\Domain\Privacy\Redaction\RedactionAuditRecord;

/** Strong evidence of independent review; an old public-key MAC is never its trust anchor. */
final class PrivacyLegacyAttestation
{
    public static function data(PrivacyTombstone|RedactionAuditRecord $record): array
    {
        return $record->integrityData() + ($record instanceof PrivacyTombstone
            ? ['integrity_signature' => $record->getIntegritySignature()] : ['audit_signature' => $record->getAuditSignature()]);
    }
    public static function subject(PrivacyTombstone|RedactionAuditRecord $record): string
    {
        return (string) ($record instanceof PrivacyTombstone ? $record->getUserId() : $record->getId());
    }
    public static function issue(string $purpose, PrivacyTombstone|RedactionAuditRecord $record, string $sourceSha256,
        string $provenance, string $emailScheme, #[\SensitiveParameter] string $key): array
    {
        $proof = ['purpose' => $purpose, 'subject' => self::subject($record), 'digest' => hash('sha256', PrivacySignature::encode(self::data($record))),
            'source_sha256' => $sourceSha256, 'provenance' => $provenance, 'attested_at' => date('Y-m-d H:i:s'),
            'email_hash_scheme' => $emailScheme, 'key_id' => PrivacySigningKey::id($key)];
        if (!self::shape($proof)) { throw new \RuntimeException('Invalid independent privacy evidence.'); }
        $proof['signature'] = PrivacySignature::sign('privacy-legacy-evidence', $proof, $key);
        return $proof;
    }
    private static function shape(array $proof): bool
    {
        $purpose = $proof['purpose'] ?? null;
        return in_array($purpose, ['tombstone', 'redaction'], true)
            && is_string($proof['subject'] ?? null) && preg_match('/^[1-9][0-9]*$/D', $proof['subject']) === 1
            && is_string($proof['digest'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $proof['digest']) === 1
            && is_string($proof['source_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $proof['source_sha256']) === 1
            && is_string($proof['provenance'] ?? null) && trim($proof['provenance']) !== ''
            && is_string($proof['attested_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $proof['attested_at']) === 1
            && is_string($proof['key_id'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $proof['key_id']) === 1
            && ($purpose === 'tombstone' ? in_array($proof['email_hash_scheme'] ?? null, ['legacy-public-v1', 'id-only'], true) : ($proof['email_hash_scheme'] ?? null) === 'none');
    }
    public static function verifyProof(array $proof, #[\SensitiveParameter] string $key): bool
    {
        if (!self::shape($proof) || !hash_equals(PrivacySigningKey::id($key), $proof['key_id'])) { return false; }
        $signature = $proof['signature'] ?? null; unset($proof['signature']);
        return is_string($signature) && PrivacySignature::verify('privacy-legacy-evidence', $proof, $signature, $key);
    }
    public static function verify(string $purpose, PrivacyTombstone|RedactionAuditRecord $record, ?array $proof, #[\SensitiveParameter] string $key): bool
    {
        return $proof !== null && ($proof['purpose'] ?? null) === $purpose && ($proof['subject'] ?? null) === self::subject($record)
            && ($proof['digest'] ?? null) === hash('sha256', PrivacySignature::encode(self::data($record))) && self::verifyProof($proof, $key);
    }
}
