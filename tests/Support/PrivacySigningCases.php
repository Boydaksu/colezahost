<?php

declare(strict_types=1);
namespace Coleza\Tests\Support;

use Coleza\Domain\Privacy\Integrity\{PrivacySignature, PrivacySigningKey};
use Coleza\Domain\Privacy\Tombstone\{PrivacyTombstone, PrivacyTombstoneService, FileTombstoneStore, BackupRestoreReconciliationService};
use Coleza\Domain\Privacy\Redaction\{RedactionAuditRecord, RedactionAuditService};

trait PrivacySigningCases
{
    private function signedTombstone(int $userId = 7, array $metadata = []): PrivacyTombstone
    {
        return PrivacyTombstone::create($userId, 'subject@example.test', 'ANONYMIZE', 'Original', 'checksum', $metadata,
            tombstonedAt: '2026-10-15 12:34:56', secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email());
    }
    private function signedAudit(): RedactionAuditRecord
    {
        return RedactionAuditRecord::create(7, 'billing', 'ANONYMIZE', 2, ['email'], 'before', 'after', false, 9,
            auditedAt: '2026-10-15 12:34:56', secretKey: PrivacyFixtureKeys::redaction());
    }
    private function signingService(?FileTombstoneStore $store = null): PrivacyTombstoneService
    {
        return new PrivacyTombstoneService($this->db, $store, secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email());
    }
    private function refused(callable $action): void
    {
        try { $action(); } catch (\RuntimeException|\JsonException|\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage()); return;
        }
        self::fail('Unsafe privacy operation was accepted.');
    }
    public function testTombstoneSignatureCoversReasonTypeAndMetadata(): void
    {
        $record = $this->signedTombstone(7, ['source' => 'original']);
        self::assertTrue($record->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
        foreach (['user_id' => 8, 'user_email_hash' => str_repeat('a', 64), 'reason' => 'Forged', 'erasure_type' => 'DELETE',
            'metadata' => ['source' => 'forged'], 'erasure_checksum' => 'other', 'tombstoned_at' => '2020-01-01 00:00:00'] as $field => $value) {
            $data = $record->toArray(); $data[$field] = $value;
            self::assertFalse(PrivacyTombstone::fromArray($data)->verifyIntegrity(PrivacyFixtureKeys::tombstone()), $field);
        }
    }
    public function testRedactionSignatureCoversCleanFlagActorAndFields(): void
    {
        $record = $this->signedAudit();
        self::assertTrue($record->verifySignature(PrivacyFixtureKeys::redaction()));
        foreach (['user_id' => 8, 'domain_name' => 'identity', 'action_type' => 'DELETE', 'records_affected' => 3,
            'pre_checksum' => 'other', 'post_checksum' => 'other', 'audited_at' => '2020-01-01 00:00:00',
            'verified_clean' => true, 'audited_by' => 11, 'redacted_fields' => ['password']] as $field => $value) {
            $data = $record->toArray(); $data[$field] = $value;
            self::assertFalse(RedactionAuditRecord::fromArray($data)->verifySignature(PrivacyFixtureKeys::redaction()), $field);
        }
    }
    public function testPurposeSeparationWrongKeysAndSignatureSize(): void
    {
        $tombstone = $this->signedTombstone(); $audit = $this->signedAudit();
        self::assertLessThanOrEqual(64, strlen($tombstone->getIntegritySignature()));
        self::assertFalse($tombstone->verifyIntegrity(PrivacyFixtureKeys::redaction()));
        self::assertFalse($audit->verifySignature(PrivacyFixtureKeys::tombstone()));
        $data = ['same' => 'message']; $key = PrivacyFixtureKeys::tombstone();
        self::assertFalse(PrivacySignature::verify('redaction', $data, PrivacySignature::sign('tombstone', $data, $key), $key));
        self::assertSame(PrivacySignature::sign('tombstone', ['a' => ['x' => 1, 'y' => 2], 'b' => 3], $key),
            PrivacySignature::sign('tombstone', ['b' => 3, 'a' => ['y' => 2, 'x' => 1]], $key));
    }
    public function testKnownDefaultLegacySignatureNeverBecomesTrusted(): void
    {
        $data = $this->signedTombstone()->toArray();
        $data['integrity_signature'] = hash_hmac('sha256', sprintf('%d:%s:%s:%s', $data['user_id'], $data['user_email_hash'], $data['erasure_checksum'], $data['tombstoned_at']), 'coleza_tombstone_key');
        self::assertFalse(PrivacyTombstone::fromArray($data)->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
        $data['reason'] = 'Forged with the public legacy key';
        self::assertFalse(PrivacyTombstone::fromArray($data)->verifyIntegrity(PrivacyFixtureKeys::tombstone()));
        $data = $this->signedAudit()->toArray();
        $data['audit_signature'] = hash_hmac('sha256', implode(':', [$data['user_id'], $data['domain_name'], $data['action_type'], $data['records_affected'], $data['pre_checksum'], $data['post_checksum'], $data['audited_at']]), 'coleza_redaction_secret');
        self::assertFalse(RedactionAuditRecord::fromArray($data)->verifySignature(PrivacyFixtureKeys::redaction()));
    }
    public function testWeakAndSamePurposeKeysFailBeforeDatabaseEffects(): void
    {
        $before = $this->db->getPdo()->inTransaction();
        $this->refused(fn () => (new PrivacyTombstoneService($this->db, secretKey: 'coleza_tombstone_key', salt: PrivacyFixtureKeys::email()))->recordTombstone(7, 'subject@example.test'));
        $this->refused(fn () => (new RedactionAuditService($this->db, secretKey: str_repeat('x', 32)))->recordAudit(7, 'users', 'DELETE', 1, ['email'], 'before', 'after'));
        $this->refused(fn () => (new PrivacyTombstoneService($this->db, secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::tombstone()))->recordTombstone(7, 'subject@example.test'));
        self::assertSame($before, $this->db->getPdo()->inTransaction());
        $tables = $this->db->getDriverName() === 'sqlite' ? $this->db->select("SELECT name FROM sqlite_master WHERE type='table'") : $this->db->select('SHOW TABLES');
        self::assertSame([], $tables);
    }
    public function testDatabaseRoundTripPreservesAllSignedFieldsIncludingFloat(): void
    {
        $service = $this->signingService();
        $created = $service->recordTombstone(7, 'subject@example.test', metadata: ['nested' => ['z' => 1.0, 'a' => ['one', 'two']]]);
        $loaded = $service->getAllTombstones()[7];
        self::assertSame($created->integrityData(), $loaded->integrityData());
        self::assertSame(1.0, $loaded->getMetadata()['nested']['z']);
        self::assertTrue($service->isEmailTombstoned(' SUBJECT@example.test '));
        $audits = new RedactionAuditService($this->db, secretKey: PrivacyFixtureKeys::redaction());
        $record = $audits->recordAudit(7, 'users', 'DELETE', 1, ['email'], 'before', 'after', false, 42);
        self::assertSame($record->integrityData(), $audits->getAuditsForUser(7)[0]->integrityData());
        self::assertTrue($audits->verifyAllSignatures(7));
        $this->db->statement('UPDATE privacy_redaction_audits SET audited_by=99 WHERE user_id=7');
        self::assertFalse($audits->verifyAllSignatures(7));
    }
    public function testEmailKeyReplacementRefusesRestoreBeforeUserChanges(): void
    {
        $this->signingService()->recordTombstone(7, 'subject@example.test');
        $this->db->statement('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100), status VARCHAR(30))');
        $this->db->insert('users', ['id' => 7, 'name' => 'Original Name', 'email' => 'subject@example.test', 'status' => 'active']);
        $before = $this->db->select('SELECT * FROM users');
        $wrong = new PrivacyTombstoneService($this->db, secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::redaction());
        $this->refused(fn () => (new BackupRestoreReconciliationService($this->db, $wrong))->reconcileAfterBackupRestore());
        self::assertSame($before, $this->db->select('SELECT * FROM users'));
    }
    public function testTamperedDatabaseRecordCannotBeReusedAsExistingDeletion(): void
    {
        $service = $this->signingService(); $service->recordTombstone(7, 'subject@example.test');
        $this->db->statement("UPDATE privacy_tombstones SET reason='Forged' WHERE user_id=7");
        $before = $this->db->select('SELECT * FROM privacy_tombstones');
        $this->refused(fn () => $service->recordTombstone(7, 'subject@example.test'));
        self::assertSame($before, $this->db->select('SELECT * FROM privacy_tombstones'));
    }
    public function testInvalidFileAndConflictingManifestCannotOverwriteInventory(): void
    {
        $path = sys_get_temp_dir() . '/coleza-signatures-' . bin2hex(random_bytes(10)) . '.json';
        $store = new FileTombstoneStore($path, PrivacyFixtureKeys::tombstone());
        try {
            $record = $this->signedTombstone(); $store->persist($record);
            $before = file_get_contents($path);
            $this->refused(fn () => $store->persist(PrivacyTombstone::create(7, 'subject@example.test', 'DELETE', 'Conflict', 'other', secretKey: PrivacyFixtureKeys::tombstone(), salt: PrivacyFixtureKeys::email())));
            self::assertSame($before, file_get_contents($path));
            $manifest = json_decode($store->exportManifest(), true, 512, JSON_THROW_ON_ERROR);
            $manifest['tombstones'][0]['reason'] = 'Tampered'; unset($manifest['manifest_checksum']);
            $manifest['manifest_checksum'] = hash('sha256', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $this->refused(fn () => $store->importManifest(json_encode($manifest, JSON_THROW_ON_ERROR)));
            self::assertSame($before, file_get_contents($path));
            foreach (['', '{broken', '{}', json_encode([$record->toArray(), $record->toArray()], JSON_THROW_ON_ERROR)] as $invalid) {
                file_put_contents($path, $invalid);
                $this->refused(fn () => $store->loadAll());
                $this->refused(fn () => $store->persist($record));
                self::assertSame($invalid, file_get_contents($path));
            }
        } finally { if (is_file($path)) { unlink($path); } }
    }
    public function testWholeInventoryIsVerifiedBeforePersistentSync(): void
    {
        $path = sys_get_temp_dir() . '/coleza-signatures-' . bin2hex(random_bytes(10)) . '.json';
        $store = new FileTombstoneStore($path, PrivacyFixtureKeys::tombstone());
        try {
            $store->persist($this->signedTombstone(8));
            $service = $this->signingService(); $service->recordTombstone(7, 'subject@example.test');
            $this->db->statement("UPDATE privacy_tombstones SET reason='Forged' WHERE user_id=7");
            $before = $this->db->select('SELECT * FROM privacy_tombstones');
            $this->refused(fn () => $this->signingService($store)->syncPersistentStoreToDatabase());
            self::assertSame($before, $this->db->select('SELECT * FROM privacy_tombstones'));
        } finally { if (is_file($path)) { unlink($path); } }
    }
    public function testDatabaseAndFileDisagreementCannotBeSilentlyMerged(): void
    {
        $path = sys_get_temp_dir() . '/coleza-signatures-' . bin2hex(random_bytes(10)) . '.json';
        $store = new FileTombstoneStore($path, PrivacyFixtureKeys::tombstone());
        try {
            $store->persist($this->signedTombstone());
            $service = $this->signingService(); $service->recordTombstone(7, 'subject@example.test', reason: 'Different valid history');
            $this->refused(fn () => $this->signingService($store)->getAllTombstones());
        } finally { if (is_file($path)) { unlink($path); } }
    }
}
