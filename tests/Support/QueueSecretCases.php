<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
trait QueueSecretCases
{
    private function secretQueue(): \Coleza\Foundation\Queue\DatabaseQueue
    {
        return new \Coleza\Foundation\Queue\DatabaseQueue($this->db, new \Coleza\Domain\Vault\QueuePayloadStore($this->db, SecretFixtureKey::encryptor()));
    }
    public function testQueuedPayloadIsEncryptedAndRestartsExactly(): void
    {
        $queue = $this->secretQueue(); $job = new QueueSecretJob(['password' => 'synthetic-queue-password', 'unlabelled' => 'synthetic-opaque-secret', 'amount' => 1.0]);
        $queue->push($job);
        self::assertStringNotContainsString('synthetic-opaque-secret', json_encode($this->db->select('SELECT * FROM jobs'), JSON_THROW_ON_ERROR));
        $popped = $this->secretQueue()->pop();
        self::assertSame($job->data, $popped->getJob()->data);
    }
    public function testDeadLetterPayloadIsEncryptedAndPreservesFailedJob(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob(['password' => 'synthetic-failed-job-secret']));
        $job = $queue->pop(); $queue->releaseOrFail($job, new \RuntimeException('synthetic-error-secret'));
        self::assertSame(0, $queue->size()); self::assertSame(1, $queue->failedSize());
        self::assertStringNotContainsString('synthetic-failed-job-secret', json_encode($this->db->select('SELECT * FROM failed_jobs'), JSON_THROW_ON_ERROR));
    }
    private function queueRows(): array
    {
        $rows = []; foreach (['jobs', 'failed_jobs', 'vault_secrets', 'security_audit_logs'] as $table) { $rows[$table] = $this->db->select("SELECT * FROM {$table} ORDER BY id"); } return $rows;
    }
    public function testStaleLeaseCannotDeleteOrFailTheReassignedPayload(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob(['token' => 'synthetic-lease-secret']));
        $old = $queue->pop(); $this->db->statement('UPDATE jobs SET reserved_at = 0'); $current = $queue->pop();
        $before = $this->queueRows();
        $queue->delete($old); $queue->releaseOrFail($old, new \RuntimeException('stale'));
        self::assertSame($before, $this->queueRows());
        $queue->delete($current); self::assertSame(0, $queue->size());
        self::assertSame([], $this->db->select("SELECT * FROM vault_secrets WHERE namespace = 'queue'"));
    }
    public function testQueueOwnerReferenceCannotBeReplayedOrRerouted(): void
    {
        $queue = $this->secretQueue(); $a = $queue->push(new QueueSecretJob(['token' => 'a'])); $b = $queue->push(new QueueSecretJob(['token' => 'b']));
        $ref = $this->db->selectOne('SELECT payload FROM jobs WHERE id = ?', [$b])['payload'];
        $this->db->statement('UPDATE jobs SET payload = ? WHERE id = ?', [$ref, $a]);
        $before = $this->queueRows();
        try { $queue->pop(); self::fail('Spliced reference accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->queueRows()); }
        $this->db->statement('UPDATE jobs SET queue = ? WHERE id = ?', ['rerouted', $b]); $before = $this->queueRows();
        try { $queue->pop('rerouted'); self::fail('Rerouted reference accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->queueRows()); }
    }
    public function testWrongKeyCannotReadDeleteOrFailAnExistingPayload(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob(['password' => 'synthetic-wrong-key-secret'])); $job = $queue->pop();
        $wrong = new \Coleza\Foundation\Queue\DatabaseQueue($this->db, new \Coleza\Domain\Vault\QueuePayloadStore($this->db, \Coleza\Domain\Vault\ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::generate())));
        $this->db->statement('UPDATE jobs SET reserved_at = 0'); $before = $this->queueRows();
        foreach ([fn() => $wrong->pop(), fn() => $wrong->delete($job), fn() => $wrong->releaseOrFail($job, new \RuntimeException('error'))] as $action) {
            try { $action(); self::fail('Wrong key accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->queueRows()); }
        }
    }
    public function testMissingKeyRejectsBeforeCreatingQueueOrVaultTables(): void
    {
        $saved = []; foreach (['APP_KEY', 'APP_KEY_FILE'] as $name) { $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null]; putenv($name); unset($_ENV[$name], $_SERVER[$name]); }
        try {
            $queue = new \Coleza\Foundation\Queue\DatabaseQueue($this->db, new \Coleza\Domain\Vault\QueuePayloadStore($this->db));
            try { $queue->push(new QueueSecretJob()); self::fail('Missing key accepted'); } catch (\RuntimeException|\InvalidArgumentException) {
                foreach (['jobs', 'failed_jobs', 'vault_secrets', 'security_audit_logs'] as $table) { self::assertFalse(\Coleza\Domain\Vault\KeyBinding::tableExists($this->db, $table)); }
            }
        } finally { foreach ($saved as $name => [$env, $arrayEnv, $serverEnv]) { $env === false ? putenv($name) : putenv($name.'='.$env); if ($arrayEnv !== null) { $_ENV[$name] = $arrayEnv; } if ($serverEnv !== null) { $_SERVER[$name] = $serverEnv; } } }
    }
    public static function queueCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('queueCallers')]
    public function testParentRollbackIncludesReservationDeadLetterVaultAndAudits(string $caller): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage(); $before = $this->queueRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try {
            $fresh = $this->secretQueue(); $fresh->push(new QueueSecretJob(['password' => 'synthetic-parent-secret']));
            $job = $fresh->pop(); $fresh->releaseOrFail($job, new \RuntimeException('failure'));
            self::assertTrue($this->db->inTransaction()); self::assertSame(1, $fresh->failedSize());
        } finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
        self::assertSame($before, $this->queueRows());
    }
    private function queueAuditFailure(string $event): void
    {
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER queue_audit_fail BEFORE INSERT ON security_audit_logs FOR EACH ROW BEGIN IF NEW.event_type = '$event' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'; END IF; END"
            : "CREATE TRIGGER queue_audit_fail BEFORE INSERT ON security_audit_logs WHEN NEW.event_type = '$event' BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
    }
    public function testLateDeadLetterAuditFailureRollsBackNewAndOldOwners(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob(['password' => 'synthetic-dlq-secret'])); $job = $queue->pop();
        $this->queueAuditFailure('VAULT_SECRET_DELETED'); $before = $this->queueRows();
        try { $queue->releaseOrFail($job, new \RuntimeException('failure')); self::fail('Late audit failure accepted'); }
        catch (\PDOException) { self::assertSame($before, $this->queueRows()); }
        self::assertSame(1, $queue->size()); self::assertSame(0, $queue->failedSize());
    }
    public function testPushAuditFailureCannotLeaveOwnerOrCipherPlaceholder(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage(); $this->queueAuditFailure('VAULT_SECRET_CREATED'); $before = $this->queueRows();
        try { $queue->push(new QueueSecretJob(['password' => 'synthetic-push-secret'])); self::fail('Audit failure accepted'); }
        catch (\PDOException) { self::assertSame($before, $this->queueRows()); }
    }
    public function testReservationReadAuditFailureCannotIncrementAttempts(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob(['password' => 'synthetic-read-secret']));
        $this->queueAuditFailure('VAULT_SECRET_ACCESSED'); $before = $this->queueRows();
        try { $queue->pop(); self::fail('Read audit failure accepted'); }
        catch (\PDOException) { self::assertSame($before, $this->queueRows()); }
    }
    public function testLegacyMaintenancePreservesExactBytesAndNeverActivatesObjectHooks(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage(); QueueSecretHookJob::$wakes = 0;
        $plain = serialize(new QueueSecretHookJob(['password' => 'synthetic-legacy-secret', 'bytes' => "a\0b", 'amount' => 1.0]));
        $id = $this->db->insert('jobs', ['queue' => 'default', 'payload' => $plain, 'available_at' => 0]);
        $failed = $this->db->insert('failed_jobs', ['queue' => 'default', 'payload' => $plain, 'exception' => 'Echoed synthetic-legacy-secret']);
        $store = new \Coleza\Domain\Vault\QueuePayloadStore($this->db, SecretFixtureKey::encryptor());
        self::assertSame(2, $store->upgrade(true)); self::assertSame(0, QueueSecretHookJob::$wakes);
        foreach (['jobs' => $id, 'failed_jobs' => $failed] as $table => $ownerId) {
            $row = $this->db->selectOne("SELECT * FROM {$table} WHERE id = ?", [$ownerId]);
            self::assertSame($plain, $store->read($table, $row));
        }
        self::assertSame(0, $store->upgrade(true)); self::assertSame(0, QueueSecretHookJob::$wakes);
        self::assertStringNotContainsString('synthetic-legacy-secret', json_encode($this->queueRows(), JSON_THROW_ON_ERROR));
        $job = $queue->pop(); self::assertSame(1, QueueSecretHookJob::$wakes);
        self::assertSame("a\0b", $job->getJob()->data['bytes']); self::assertSame(1.0, $job->getJob()->data['amount']);
    }
    public function testMalformedLegacyBatchAndMissingConfirmationCannotPartiallyUpgrade(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage();
        $this->db->insert('jobs', ['queue' => 'default', 'payload' => serialize(new QueueSecretJob(['password' => 'synthetic-legacy-job'])), 'available_at' => 0]);
        $bad = $this->db->insert('failed_jobs', ['queue' => 'default', 'payload' => 'broken-serialized-data', 'exception' => 'old']);
        $store = new \Coleza\Domain\Vault\QueuePayloadStore($this->db, SecretFixtureKey::encryptor()); $before = $this->queueRows();
        try { $store->upgrade(); self::fail('Unconfirmed upgrade accepted'); } catch (\LogicException) { self::assertSame($before, $this->queueRows()); }
        try { $store->upgrade(true); self::fail('Malformed batch accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->queueRows()); }
        try { $queue->pop(); self::fail('Runtime plaintext fallback accepted'); } catch (\RuntimeException) { self::assertSame($before, $this->queueRows()); }
        $this->db->statement('DELETE FROM failed_jobs WHERE id = ?', [$bad]);
        self::assertSame(1, $store->upgrade(true));
    }
    public function testConflictingVaultOwnerCannotBeOverwrittenByLegacyUpgrade(): void
    {
        $queue = $this->secretQueue(); $queue->prepareStorage(); $id = $this->db->insert('jobs', ['queue' => 'default', 'payload' => serialize(new QueueSecretJob()), 'available_at' => 0]);
        $owner = ['id' => $id, 'queue' => 'default'];
        (new \Coleza\Domain\Vault\VaultReferenceStore($this->db, SecretFixtureKey::encryptor()))->write(\Coleza\Domain\Vault\QueuePayloadStore::NAMESPACE, \Coleza\Domain\Vault\QueuePayloadStore::key('jobs', $owner), serialize(new QueueSecretJob(['token' => 'conflict'])));
        $before = $this->queueRows();
        try { (new \Coleza\Domain\Vault\QueuePayloadStore($this->db, SecretFixtureKey::encryptor()))->upgrade(true); self::fail('Conflicting owner accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->queueRows()); }
    }
    public function testRetryKeepsPayloadAndAttemptStateButDeadLetterUsesTheMutatedJob(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob(['password' => 'synthetic-retry-secret'], 2));
        $ref = $this->db->selectOne('SELECT payload FROM jobs')['payload']; $first = $queue->pop();
        $queue->releaseOrFail($first, new \RuntimeException('transient')); self::assertSame($ref, $this->db->selectOne('SELECT payload FROM jobs')['payload']);
        $second = $queue->pop(); self::assertSame(2, $second->getAttempts()); self::assertSame('synthetic-retry-secret', $second->getJob()->data['password']);
        $second->getJob()->data['stage'] = 'after-handle'; $queue->releaseOrFail($second, new \RuntimeException('final'));
        $row = $this->db->selectOne('SELECT * FROM failed_jobs');
        $plain = (new \Coleza\Domain\Vault\QueuePayloadStore($this->db, SecretFixtureKey::encryptor()))->read('failed_jobs', $row);
        self::assertSame('after-handle', unserialize($plain)->data['stage']);
        self::assertCount(1, $this->db->select("SELECT * FROM vault_secrets WHERE namespace = 'queue'"));
    }
    public function testMaintenanceRejectsParentAndCorruptCipherDoesNotConsumeJob(): void
    {
        $queue = $this->secretQueue(); $queue->push(new QueueSecretJob()); $store = new \Coleza\Domain\Vault\QueuePayloadStore($this->db, SecretFixtureKey::encryptor());
        $this->db->beginTransaction();
        try { try { $store->upgrade(true); self::fail('Parent maintenance accepted'); } catch (\LogicException) { self::assertTrue($this->db->inTransaction()); } }
        finally { $this->db->rollBack(); }
        $this->db->statement("UPDATE vault_secrets SET encrypted_value = 'enc:v1:corrupt' WHERE namespace = 'queue'"); $before = $this->queueRows();
        try { $queue->pop(); self::fail('Corrupt cipher accepted'); } catch (\RuntimeException|\InvalidArgumentException) { self::assertSame($before, $this->queueRows()); }
    }

}
