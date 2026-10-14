<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait PrivacyLegacyCases
{
    private function legacyTombstoneData(): array
    {
        $data = ['id' => 17, 'user_id' => 7, 'user_email_hash' => hash_hmac('sha256', 'subject@example.test', 'coleza_privacy_salt'),
            'erasure_type' => 'ANONYMIZE', 'reason' => 'Legacy approved erasure', 'erasure_checksum' => 'old-checksum', 'metadata' => [], 'tombstoned_at' => '2020-01-02 03:04:05'];
        $data['integrity_signature'] = hash_hmac('sha256', implode(':', [$data['user_id'], $data['user_email_hash'], $data['erasure_checksum'], $data['tombstoned_at']]), 'coleza_tombstone_key');
        return $data;
    }
    private function legacyAuditData(): array
    {
        $data = ['id' => 18, 'user_id' => 7, 'domain_name' => 'billing', 'action_type' => 'ANONYMIZE', 'records_affected' => 2,
            'redacted_fields' => ['email'], 'pre_checksum' => 'before', 'post_checksum' => 'after', 'verified_clean' => false,
            'audited_at' => '2020-01-02 03:04:05', 'audited_by' => 9];
        $data['audit_signature'] = hash_hmac('sha256', implode(':', [$data['user_id'], $data['domain_name'], $data['action_type'], $data['records_affected'], $data['pre_checksum'], $data['post_checksum'], $data['audited_at']]), 'coleza_redaction_secret');
        return $data;
    }
    private function legacyProof(string $purpose, array $data): array
    {
        $record = $purpose === 'tombstone' ? \Coleza\Domain\Privacy\Tombstone\PrivacyTombstone::fromArray($data) : \Coleza\Domain\Privacy\Redaction\RedactionAuditRecord::fromArray($data);
        $semantic = $record->integrityData() + [$purpose === 'tombstone' ? 'integrity_signature' : 'audit_signature' => $purpose === 'tombstone' ? $record->getIntegritySignature() : $record->getAuditSignature()];
        $proof = ['purpose' => $purpose, 'subject' => (string) ($purpose === 'tombstone' ? $data['user_id'] : $data['id']),
            'digest' => hash('sha256', \Coleza\Domain\Privacy\Integrity\PrivacySignature::encode($semantic)),
            'source_sha256' => hash('sha256', 'independent reviewed synthetic evidence'), 'provenance' => 'Reviewed synthetic archive',
            'attested_at' => '2026-10-15 12:34:56', 'email_hash_scheme' => $purpose === 'tombstone' ? 'legacy-public-v1' : 'none',
            'key_id' => \Coleza\Domain\Privacy\Integrity\PrivacySigningKey::id($purpose === 'tombstone' ? PrivacyFixtureKeys::tombstone() : PrivacyFixtureKeys::redaction())];
        $proof['signature'] = \Coleza\Domain\Privacy\Integrity\PrivacySignature::sign('privacy-legacy-evidence', $proof, $purpose === 'tombstone' ? PrivacyFixtureKeys::tombstone() : PrivacyFixtureKeys::redaction());
        return $proof;
    }
    public function testIndependentlyAttestedTombstoneCanBeVerifiedWithoutChangingLegacyFields(): void
    {
        $data = $this->legacyTombstoneData(); $data['legacy_evidence'] = $this->legacyProof('tombstone', $data);
        self::assertTrue(\Coleza\Domain\Privacy\Tombstone\PrivacyTombstone::fromArray($data)->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
    }
    public function testIndependentlyAttestedAuditCanBeVerifiedWithoutChangingLegacyFields(): void
    {
        $data = $this->legacyAuditData(); $data['legacy_evidence'] = $this->legacyProof('redaction', $data);
        self::assertTrue(\Coleza\Domain\Privacy\Redaction\RedactionAuditRecord::fromArray($data)->verifySignature(PrivacyFixtureKeys::redaction()));
    }

    private function legacyStore(string $path, bool $wrongKey = false): \Coleza\Domain\Privacy\Integrity\PrivacyLegacyEvidenceStore
    {
        return new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyEvidenceStore($path, ['tombstone' => $wrongKey ? PrivacyFixtureKeys::email() : PrivacyFixtureKeys::tombstone(), 'redaction' => PrivacyFixtureKeys::redaction()]);
    }
    private function legacyService(?\Coleza\Domain\Privacy\Integrity\PrivacyLegacyEvidenceStore $store = null, ?\Coleza\Domain\Privacy\Tombstone\FileTombstoneStore $file = null): \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService
    {
        return new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, $file, secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email(), legacyEvidence: $store);
    }
    private function legacyFixture(): array
    {
        $this->legacyService()->ensureTables();
        $audit = new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction()); $audit->ensureTables();
        $t = $this->legacyTombstoneData(); $t['metadata'] = json_encode($t['metadata'], JSON_THROW_ON_ERROR); $this->db->insert('privacy_tombstones', $t);
        $a = $this->legacyAuditData(); $a['redacted_fields'] = json_encode($a['redacted_fields'], JSON_THROW_ON_ERROR); $a['verified_clean'] = 0; $this->db->insert('privacy_redaction_audits', $a);
        return [$this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'), $this->db->selectOne('SELECT * FROM privacy_redaction_audits WHERE id=18')];
    }
    private function reviewManifest(array $tombstone, array $audit): string
    {
        return json_encode(['version' => 1, 'records' => [
            ['source' => 'database', 'purpose' => 'tombstone', 'data' => $tombstone, 'email_hash_scheme' => 'legacy-public-v1'],
            ['source' => 'database', 'purpose' => 'redaction', 'data' => $audit, 'email_hash_scheme' => 'none']]], JSON_THROW_ON_ERROR);
    }
    private function legacyRefused(callable $action): void
    {
        try { $action(); } catch (\RuntimeException|\JsonException|\InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); return; }
        self::fail('Untrusted legacy operation accepted.');
    }
    private function legacyPath(): string { return sys_get_temp_dir() . '/coleza-legacy-evidence-' . bin2hex(random_bytes(10)) . '.json'; }
    private function removeLegacyFiles(array $paths): void { foreach ($paths as $path) { if (is_file($path)) { unlink($path); } } }
    public function testManifestAttestationIsIdempotentAndKeepsOriginalDatabaseRows(): void
    {
        [$t, $a] = $this->legacyFixture(); $path = $this->legacyPath(); $store = $this->legacyStore($path);
        $manifest = $this->reviewManifest($t, $a); $maintenance = new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $store);
        try {
            $this->legacyRefused(fn () => $this->legacyService()->getAllTombstones());
            self::assertSame(2, $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed archive 2020', true));
            $before = file_get_contents($path);
            self::assertSame(0, $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed archive 2020', true));
            self::assertSame($before, file_get_contents($path));
            self::assertSame($t, $this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'));
            self::assertSame($a, $this->db->selectOne('SELECT * FROM privacy_redaction_audits WHERE id=18'));
            $service = $this->legacyService($this->legacyStore($path));
            self::assertTrue($service->getAllTombstones()[7]->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
            self::assertTrue($service->isEmailTombstoned(' SUBJECT@example.test '));
            $audit = new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction(), legacyEvidence: $store);
            self::assertTrue($audit->verifyAllSignatures(7));
        } finally { $this->removeLegacyFiles([$path]); }
    }
    public function testDigestApprovalAndFullSourceMismatchFailBeforeEvidenceWrites(): void
    {
        [$t, $a] = $this->legacyFixture(); $path = $this->legacyPath(); $manifest = $this->reviewManifest($t, $a);
        $maintenance = new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $this->legacyStore($path));
        try {
            $this->legacyRefused(fn () => $maintenance->attest($manifest, str_repeat('0', 64), 'Reviewed', true));
            $this->legacyRefused(fn () => $maintenance->attest($manifest, hash('sha256', $manifest), '', true));
            $this->legacyRefused(fn () => $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed', false));
            $changed = $a; $changed['audited_by'] = 77; $bad = $this->reviewManifest($t, $changed);
            $this->legacyRefused(fn () => $maintenance->attest($bad, hash('sha256', $bad), 'Reviewed', true));
            self::assertFileDoesNotExist($path);
            self::assertSame($t, $this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'));
            self::assertSame($a, $this->db->selectOne('SELECT * FROM privacy_redaction_audits WHERE id=18'));
        } finally { $this->removeLegacyFiles([$path]); }
    }
    public function testLateTamperProofReplayAndWrongKeysAreRejected(): void
    {
        [$t, $a] = $this->legacyFixture(); $path = $this->legacyPath(); $manifest = $this->reviewManifest($t, $a);
        try {
            $store = $this->legacyStore($path); (new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $store))->attest($manifest, hash('sha256', $manifest), 'Reviewed', true);
            $this->legacyRefused(fn () => $this->legacyService($this->legacyStore($path, true))->getAllTombstones());
            $this->db->statement("UPDATE privacy_tombstones SET reason='Forged after attestation' WHERE user_id=7");
            $this->legacyRefused(fn () => $this->legacyService($store)->getAllTombstones());
            $this->db->statement('UPDATE privacy_redaction_audits SET verified_clean=1 WHERE id=18');
            self::assertFalse((new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction(), legacyEvidence: $store))->verifyAllSignatures(7));
            $proof = $store->find('tombstone', '7'); $data = $this->legacyTombstoneData(); $data['user_id'] = 8; $data['legacy_evidence'] = $proof;
            self::assertFalse(\Coleza\Domain\Privacy\Tombstone\PrivacyTombstone::fromArray($data)->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
        } finally { $this->removeLegacyFiles([$path]); }
    }
    public function testProofMetadataTamperingCannotTurnPublicLegacyKeyIntoAuthority(): void
    {
        $data = $this->legacyTombstoneData(); $proof = $this->legacyProof('tombstone', $data);
        foreach (['provenance' => 'Forged review', 'source_sha256' => str_repeat('a', 64), 'email_hash_scheme' => 'id-only', 'attested_at' => '2020-01-01 00:00:00', 'key_id' => str_repeat('0', 64)] as $field => $value) {
            $bad = $proof; $bad[$field] = $value; $data['legacy_evidence'] = $bad;
            self::assertFalse(\Coleza\Domain\Privacy\Tombstone\PrivacyTombstone::fromArray($data)->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
        }
    }
    public function testLegacyFileIsNeverRewrittenAndItsEvidenceSurvivesDatabaseLoss(): void
    {
        $path = $this->legacyPath(); $file = $this->legacyPath(); $import = $this->legacyPath(); $data = $this->legacyTombstoneData();
        $bytes = json_encode([$data], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); file_put_contents($file, $bytes);
        $manifest = json_encode(['version' => 1, 'records' => [['source' => 'file', 'purpose' => 'tombstone', 'data' => $data, 'email_hash_scheme' => 'legacy-public-v1']]], JSON_THROW_ON_ERROR);
        try {
            $store = $this->legacyStore($path);
            self::assertSame(1, (new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $store))->attest($manifest, hash('sha256', $manifest), 'Reviewed file archive', true, $file));
            self::assertSame($bytes, file_get_contents($file));
            $files = new \Coleza\Domain\Privacy\Tombstone\FileTombstoneStore($file, PrivacyFixtureKeys::tombstone(), $this->legacyStore($path));
            self::assertTrue($files->loadAll()[7]->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
            $service = $this->legacyService($store, $files); self::assertSame(1, $service->syncPersistentStoreToDatabase());
            self::assertTrue($service->getAllTombstones()[7]->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
            // Export carries signed evidence; import does not depend on the original proof file.
            $new = new \Coleza\Domain\Privacy\Tombstone\FileTombstoneStore($import, PrivacyFixtureKeys::tombstone());
            self::assertSame(1, $new->importManifest($files->exportManifest()));
            self::assertTrue($new->loadAll()[7]->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
            self::assertTrue($this->legacyService(null, $new)->getAllTombstones()[7]->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
            self::assertSame($bytes, file_get_contents($file));
        } finally { $this->removeLegacyFiles([$path, $file, $import]); }
    }
    public function testIncompleteAndConflictingFileEvidenceCannotApproveAnyRecords(): void
    {
        $path = $this->legacyPath(); $file = $this->legacyPath(); $data = $this->legacyTombstoneData(); $bytes = json_encode([$data], JSON_THROW_ON_ERROR); file_put_contents($file, $bytes);
        try {
            $maintenance = new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $this->legacyStore($path));
            $bad = $data; unset($bad['reason']);
            $manifest = json_encode(['version' => 1, 'records' => [['source' => 'file', 'purpose' => 'tombstone', 'data' => $bad, 'email_hash_scheme' => 'legacy-public-v1']]], JSON_THROW_ON_ERROR);
            $this->legacyRefused(fn () => $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed', true, $file));
            $bad = $data; $bad['reason'] = 'Different source';
            $manifest = json_encode(['version' => 1, 'records' => [['source' => 'file', 'purpose' => 'tombstone', 'data' => $bad, 'email_hash_scheme' => 'legacy-public-v1']]], JSON_THROW_ON_ERROR);
            $this->legacyRefused(fn () => $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed', true, $file));
            self::assertFileDoesNotExist($path); self::assertSame($bytes, file_get_contents($file));
        } finally { $this->removeLegacyFiles([$path, $file]); }
    }
    public function testUnsignedConsentMarkerRequiresExplicitIdOnlyEvidence(): void
    {
        $this->legacyService()->ensureTables(); (new \Coleza\Domain\Privacy\PrivacyConsentService($this->db))->recordTombstone(7);
        $row = $this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'); $path = $this->legacyPath();
        try {
            $store = $this->legacyStore($path); $manifest = json_encode(['version' => 1, 'records' => [['source' => 'database', 'purpose' => 'tombstone', 'data' => $row, 'email_hash_scheme' => 'id-only']]], JSON_THROW_ON_ERROR);
            self::assertSame(1, (new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $store))->attest($manifest, hash('sha256', $manifest), 'Reviewed consent deletion', true));
            $service = $this->legacyService($store); $record = $service->getAllTombstones()[7];
            self::assertTrue($record->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
            self::assertFalse($service->matchesTombstoneEmail($record, 'subject@example.test'));
            self::assertSame($row, $this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'));
        } finally { $this->removeLegacyFiles([$path]); }
    }
    public function testExistingEvidenceIsImmutableAndMalformedOrMissingEvidenceFailsClosed(): void
    {
        [$t, $a] = $this->legacyFixture(); $path = $this->legacyPath(); $manifest = $this->reviewManifest($t, $a);
        try {
            $store = $this->legacyStore($path); $maintenance = new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $store);
            $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed', true); $before = file_get_contents($path);
            $this->legacyRefused(fn () => $maintenance->attest($manifest, hash('sha256', $manifest), 'Different review', true));
            self::assertSame($before, file_get_contents($path));
            file_put_contents($path, '{broken'); $this->legacyRefused(fn () => $this->legacyService($store)->getAllTombstones());
            $this->legacyRefused(fn () => $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed', true));
            self::assertSame('{broken', file_get_contents($path));
            unlink($path); $this->legacyRefused(fn () => $this->legacyService($store)->getAllTombstones());
        } finally { $this->removeLegacyFiles([$path]); }
    }
    public function testMaintenanceRejectsCallerTransactionAndPreservesItsWrites(): void
    {
        [$t, $a] = $this->legacyFixture(); $path = $this->legacyPath(); $manifest = $this->reviewManifest($t, $a);
        foreach (['raw', 'managed'] as $mode) {
            if ($mode === 'raw') { $this->db->getPdo()->beginTransaction(); } else { $this->db->beginTransaction(); }
            try {
                $this->db->statement("UPDATE privacy_tombstones SET reason='Parent write' WHERE user_id=7");
                $this->legacyRefused(fn () => (new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $this->legacyStore($path)))->attest($manifest, hash('sha256', $manifest), 'Reviewed', true));
                self::assertTrue($this->db->inTransaction());
                self::assertSame('Parent write', $this->db->selectOne('SELECT reason FROM privacy_tombstones WHERE user_id=7')['reason']);
                self::assertFileDoesNotExist($path);
            } finally { if ($mode === 'raw') { $this->db->getPdo()->rollBack(); } else { $this->db->rollBack(); } }
        }
        self::assertSame($t, $this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'));
    }

    public function testApprovedLegacyEmailMappingScrubsRestoredUserUnderDifferentId(): void
    {
        [$t, $a] = $this->legacyFixture(); $path = $this->legacyPath(); $manifest = $this->reviewManifest($t, $a);
        $this->db->statement('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100), status VARCHAR(30))');
        $this->db->insert('users', ['id' => 99, 'name' => 'Synthetic restored subject', 'email' => 'subject@example.test', 'status' => 'active']);
        try {
            $store = $this->legacyStore($path); $maintenance = new \Coleza\Domain\Privacy\Integrity\PrivacyLegacyMaintenance($this->db, $store);
            $maintenance->attest($manifest, hash('sha256', $manifest), 'Reviewed archive', true);
            $report = (new \Coleza\Domain\Privacy\Tombstone\BackupRestoreReconciliationService($this->db, $this->legacyService($store)))->reconcileAfterBackupRestore();
            self::assertSame([99], $report->getScrubbedUserIds());
            self::assertSame('erased', $this->db->selectOne('SELECT status FROM users WHERE id=99')['status']);
            self::assertSame($t, $this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=7'));
        } finally { $this->removeLegacyFiles([$path]); }
    }
}
