<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait PrivacyBindingCases
{
    private function privacyWriter(?string $key = null): \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService
    {
        return new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, secretKey: $key ?? PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email());
    }
    public function testDifferentTombstoneKeyCannotWriteAnotherUser(): void
    {
        $this->privacyWriter()->recordTombstone(7, 'first@example.test');
        $caught = false;
        try { $this->privacyWriter(PrivacyFixtureKeys::redaction())->recordTombstone(8, 'second@example.test'); } catch (\RuntimeException) { $caught = true; }
        self::assertTrue($caught, 'Different key wrote a second user into the existing installation.');
        self::assertNull($this->db->selectOne('SELECT * FROM privacy_tombstones WHERE user_id=8'));
    }
    public function testDifferentRedactionKeyCannotWriteAnotherAudit(): void
    {
        (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction()))->recordAudit(7, 'users', 'DELETE', 1, ['email'], 'before', 'after');
        $caught = false;
        try { (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::tombstone()))->recordAudit(8, 'users', 'DELETE', 1, ['email'], 'before', 'after'); } catch (\RuntimeException) { $caught = true; }
        self::assertTrue($caught, 'Different key wrote another audit into the existing installation.');
        self::assertNull($this->db->selectOne('SELECT * FROM privacy_redaction_audits WHERE user_id=8'));
    }

    private function bindingKeys(): array { return ['tombstone' => PrivacyFixtureKeys::tombstone(), 'email' => PrivacyFixtureKeys::email(), 'redaction' => PrivacyFixtureKeys::redaction()]; }
    private function privacyVerifier(): \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier { return new \Coleza\Domain\Privacy\Integrity\PrivacyIntegrityVerifier($this->db, $this->bindingKeys()); }
    private function bindingSnapshot(): array
    {
        $rows = [];
        foreach (['privacy_key_bindings', 'privacy_tombstones', 'security_audit_logs'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY 1"); }
        return $rows;
    }
    private function bindingRefused(callable $action): void
    {
        try { $action(); } catch (\RuntimeException|\LogicException $error) { self::assertNotSame('', $error->getMessage()); return; }
        self::fail('Mismatched privacy binding accepted.');
    }
    public function testWrongEmailKeyAndCrossPurposeReuseAreRejectedWithoutChanges(): void
    {
        $this->privacyWriter()->recordTombstone(7, 'first@example.test'); $before = $this->bindingSnapshot();
        $wrong = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::redaction());
        $this->bindingRefused(fn () => $wrong->recordTombstone(8, 'other@example.test'));
        self::assertSame($before, $this->bindingSnapshot());
        $badAudit = new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::tombstone());
        $this->bindingRefused(fn () => $badAudit->recordAudit(7, 'users', 'DELETE', 1, ['email'], 'before', 'after'));
        self::assertSame($before, $this->bindingSnapshot());
        self::assertSame([], $this->db->select('SELECT * FROM privacy_redaction_audits'));
    }
    public function testVerificationIsReadOnlyAndReturnsNoSecretMaterial(): void
    {
        $this->privacyWriter()->recordTombstone(7, 'first@example.test');
        (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction()))->recordAudit(7, 'users', 'DELETE', 1, ['email'], 'before', 'after');
        $before = $this->bindingSnapshot(); $this->db->enableQueryLog();
        $result = $this->privacyVerifier()->verify();
        self::assertSame(['bindings' => 3, 'tombstones' => 1, 'redaction_audits' => 1, 'legacy_proofs' => 0], $result['counts']);
        foreach ($this->db->getQueryLog() as $entry) { self::assertMatchesRegularExpression('/^(SELECT|PRAGMA)\b/i', $entry['query']); }
        self::assertSame($before, $this->bindingSnapshot());
        $json = json_encode([$result, $this->db->getQueryLog()], JSON_THROW_ON_ERROR);
        foreach ($this->bindingKeys() as $key) { self::assertStringNotContainsString('base64:' . base64_encode($key), $json); }
    }
    public function testEmptyDatabaseVerificationDoesNotCreateSchema(): void
    {
        $this->db->enableQueryLog(); self::assertFalse($this->privacyVerifier()->required());
        self::assertSame(['bindings' => 0, 'tombstones' => 0, 'redaction_audits' => 0, 'legacy_proofs' => 0], $this->privacyVerifier()->verify()['counts']);
        foreach ($this->db->getQueryLog() as $entry) { self::assertMatchesRegularExpression('/^(SELECT|PRAGMA)\b/i', $entry['query']); }
    }
    public function testTamperedProbeRejectsWriterAndVerifier(): void
    {
        $this->privacyWriter()->recordTombstone(7, 'first@example.test');
        $this->db->statement("UPDATE privacy_key_bindings SET proof='hmac:v2:forged' WHERE purpose='tombstone'"); $before = $this->bindingSnapshot();
        $this->bindingRefused(fn () => $this->privacyWriter()->recordTombstone(8, 'second@example.test'));
        $this->bindingRefused(fn () => $this->privacyVerifier()->verify());
        self::assertSame($before, $this->bindingSnapshot());
    }
    public function testFirstBindingChecksPreexistingRecordsBeforeItCanCommit(): void
    {
        $this->privacyWriter()->ensureTables();
        $record = \Coleza\Domain\Privacy\Tombstone\PrivacyTombstone::create(7, 'first@example.test', 'ANONYMIZE', 'Original', 'checksum', secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email());
        $data = $record->toArray(); unset($data['id']); $data['metadata'] = json_encode($data['metadata'], JSON_THROW_ON_ERROR); $this->db->insert('privacy_tombstones', $data);
        $before = $this->bindingSnapshot();
        $this->bindingRefused(fn () => $this->privacyWriter(PrivacyFixtureKeys::redaction())->recordTombstone(8, 'second@example.test'));
        self::assertSame($before, $this->bindingSnapshot());
        self::assertSame([], $this->db->select('SELECT * FROM privacy_key_bindings'));
    }
    private function installPrivacyFailureTrigger(string $table, string $condition = ''): void
    {
        if ($this->db->getDriverName() === 'mysql') {
            $body = $condition === '' ? "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected privacy failure'" : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected privacy failure'; END IF; END";
            $this->db->statement("CREATE TRIGGER privacy_failure BEFORE INSERT ON {$table} FOR EACH ROW {$body}");
        } else {
            $when = $condition === '' ? '' : " WHEN {$condition}";
            $this->db->statement("CREATE TRIGGER privacy_failure BEFORE INSERT ON {$table}{$when} BEGIN SELECT RAISE(FAIL, 'injected privacy failure'); END");
        }
    }
    public function testOwnerFailureRollsBackNewBindingAndBindingAudit(): void
    {
        $this->privacyWriter()->ensureTables(); $before = $this->bindingSnapshot(); $this->installPrivacyFailureTrigger('privacy_tombstones');
        $this->bindingRefused(fn () => $this->privacyWriter()->recordTombstone(7, 'first@example.test'));
        self::assertSame($before, $this->bindingSnapshot());
    }
    public function testLateSecurityAuditFailureRollsBackOwnerWithoutChangingBinding(): void
    {
        $this->privacyWriter()->recordTombstone(7, 'first@example.test'); $before = $this->bindingSnapshot();
        $this->installPrivacyFailureTrigger('security_audit_logs', "NEW.event_type='PRIVACY_TOMBSTONE_CREATED'");
        $writer = new \Coleza\Domain\Privacy\Tombstone\PrivacyTombstoneService($this->db, auditLogger: new \Coleza\Domain\Identity\Audit\AuditLogger($this->db), secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email());
        $this->bindingRefused(fn () => $writer->recordTombstone(8, 'second@example.test'));
        self::assertSame($before, $this->bindingSnapshot());
    }
    public function testBindingOwnerAndAuditRespectRawAndManagedParentRollback(): void
    {
        $this->privacyWriter()->ensureTables(); $before = $this->bindingSnapshot();
        foreach (['raw', 'managed'] as $mode) {
            if ($mode === 'raw') { $this->db->getPdo()->beginTransaction(); } else { $this->db->beginTransaction(); }
            try {
                $this->privacyWriter()->recordTombstone(7, 'first@example.test'); self::assertTrue($this->db->inTransaction());
                self::assertCount(2, $this->db->select('SELECT * FROM privacy_key_bindings'));
                self::assertCount(1, $this->db->select("SELECT * FROM security_audit_logs WHERE event_type='PRIVACY_KEYS_BOUND'"));
            } finally { if ($mode === 'raw') { $this->db->getPdo()->rollBack(); } else { $this->db->rollBack(); } }
            self::assertSame($before, $this->bindingSnapshot());
        }
    }
    public function testMismatchedCachedErasureWriterFailsBeforeUserEffects(): void
    {
        $this->privacyWriter()->recordTombstone(7, 'first@example.test'); $before = $this->bindingSnapshot();
        $erasure = new \Coleza\Domain\Privacy\Erasure\PrivacyErasureService($this->db, tombstoneService: $this->privacyWriter(PrivacyFixtureKeys::redaction()));
        $this->bindingRefused(fn () => $erasure->executeErasure(8, 1, 'test'));
        self::assertSame($before, $this->bindingSnapshot());
    }
    public function testExplicitBindingIsIdempotentAndRequiresOwnMaintenanceTransaction(): void
    {
        $this->privacyWriter()->ensureTables();
        (new \Coleza\Domain\Privacy\Redaction\RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction()))->ensureTables();
        $before = $this->bindingSnapshot();
        $this->bindingRefused(fn () => $this->privacyVerifier()->bind()); self::assertSame($before, $this->bindingSnapshot());
        self::assertSame(3, $this->privacyVerifier()->bind(true)['counts']['bindings']);
        $bound = $this->bindingSnapshot(); self::assertSame(3, $this->privacyVerifier()->bind(true)['counts']['bindings']); self::assertSame($bound, $this->bindingSnapshot());
        $this->db->getPdo()->beginTransaction();
        try { $this->bindingRefused(fn () => $this->privacyVerifier()->bind(true)); self::assertTrue($this->db->inTransaction()); }
        finally { $this->db->getPdo()->rollBack(); }
        self::assertSame($bound, $this->bindingSnapshot());
    }
}
