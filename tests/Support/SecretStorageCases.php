<?php

declare(strict_types=1);
namespace Coleza\Tests\Support;

trait SecretStorageCases
{
    private function secretFixture(): array
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $encryptor = new \Coleza\Domain\Vault\Encryptor(random_bytes(32));
        return [new \Coleza\Domain\Installer\EmailSetupService($encryptor),
            new \Coleza\Domain\Servers\Services\ServerService($this->db, $encryptor),
            new \Coleza\Domain\Identity\TwoFactor\TwoFactorService($this->db, new \Coleza\Domain\Identity\TwoFactor\TotpEngine(), new \Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]), $encryptor), $encryptor];
    }
    private function smtpConfig(): \Coleza\Domain\Installer\EmailTransportConfig
    {
        return new \Coleza\Domain\Installer\EmailTransportConfig(host: 'smtp.example.test', password: 'synthetic-smtp-password');
    }
    private function serverData(string $hostname = 'server.example.test'): array
    {
        return ['name' => 'Fixture', 'hostname' => $hostname, 'ip_address' => '127.0.0.1', 'provider_slug' => 'fixture', 'auth_secret' => 'synthetic-server-token'];
    }
    public function testSmtpPasswordStoredEncryptedAndNotReturned(): void
    {
        [$email] = $this->secretFixture(); $response = $email->configureEmail($this->db, $this->smtpConfig());
        $stored = $this->db->selectOne("SELECT setting_value FROM system_settings WHERE setting_key = 'mail.password'")['setting_value'];
        self::assertNotSame('synthetic-smtp-password', $stored);
        self::assertStringNotContainsString('synthetic-smtp-password', json_encode($response));
    }
    public function testServerTokenStoredEncrypted(): void
    {
        [, $servers] = $this->secretFixture(); $server = $servers->createServer($this->serverData());
        self::assertNotSame('synthetic-server-token', $this->db->selectOne('SELECT auth_secret_encrypted FROM servers WHERE id = ?', [$server->getId()])['auth_secret_encrypted']);
        self::assertSame('synthetic-server-token', $server->getAuthSecret());
    }
    public function testTotpSecretStoredEncrypted(): void
    {
        [, , $two] = $this->secretFixture();
        $setup = $two->beginSetup(7, 'fixture@example.test');
        self::assertNotSame($setup['secret'], $this->db->selectOne('SELECT secret FROM user_two_factor WHERE user_id = 7')['secret']);
        $code = (new \Coleza\Domain\Identity\TwoFactor\TotpEngine())->generateCode($setup['secret']);
        self::assertCount(8, $two->confirmSetup(7, $code));
    }
    private function storageRows(): array
    {
        return [$this->db->select('SELECT * FROM system_settings ORDER BY id'), $this->db->select('SELECT * FROM servers ORDER BY id'), $this->db->select('SELECT * FROM user_two_factor ORDER BY id')];
    }
    private function legacyFixture(): array
    {
        [$email, $servers, $two, $key] = $this->secretFixture();
        $email->configureEmail($this->db, $this->smtpConfig()); $server = $servers->createServer($this->serverData());
        $setup = $two->beginSetup(7, 'fixture@example.test');
        $this->db->statement("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'mail.password'", ['synthetic-smtp-password']);
        $this->db->statement('UPDATE servers SET auth_secret_encrypted = ?', ['synthetic-server-token']);
        $this->db->statement('UPDATE user_two_factor SET secret = ?', [$setup['secret']]);
        return [$email, $servers, $two, $key, $server->getId(), $setup['secret']];
    }
    public function testLegacyUpgradeNeedsConfirmationAndPreservesRows(): void
    {
        [, , , $key] = $this->legacyFixture(); $before = $this->storageRows();
        try { (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key))->upgrade(); self::fail('Unconfirmed legacy secrets accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->storageRows()); }
    }
    public function testUpgradeRestartReplayAndNoPlaintextInQueryLog(): void
    {
        [, , , $key, $id, $secret] = $this->legacyFixture(); $this->db->enableQueryLog();
        $upgrade = new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key);
        self::assertSame(3, $upgrade->upgrade(true)); $after = $this->storageRows();
        self::assertSame(0, $upgrade->upgrade()); self::assertSame($after, $this->storageRows());
        self::assertSame('synthetic-smtp-password', (new \Coleza\Domain\Installer\EmailSetupService($key))->readConfiguration($this->db)->getPassword());
        self::assertSame('synthetic-server-token', (new \Coleza\Domain\Servers\Services\ServerService($this->db, $key))->findServerById($id)->getAuthSecret());
        $two = new \Coleza\Domain\Identity\TwoFactor\TwoFactorService($this->db, new \Coleza\Domain\Identity\TwoFactor\TotpEngine(), new \Coleza\Domain\Identity\Security\PasswordHasher(), $key);
        self::assertCount(8, $two->confirmSetup(7, (new \Coleza\Domain\Identity\TwoFactor\TotpEngine())->generateCode($secret)));
        $log = json_encode($this->db->getQueryLog());
        foreach (['synthetic-smtp-password', 'synthetic-server-token', $secret] as $plain) { self::assertStringNotContainsString($plain, $log); }
    }
    public static function storageCallers(): array { return [['raw'], ['managed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('storageCallers')]
    public function testUpgradeStaysInCallerRollback(string $caller): void
    {
        [, , , $key] = $this->legacyFixture(); $before = $this->storageRows();
        $caller === 'raw' ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
        try { self::assertSame(3, (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key))->upgrade(true)); self::assertTrue($this->db->getPdo()->inTransaction()); }
        finally { $caller === 'raw' ? $this->db->getPdo()->rollBack() : $this->db->rollBack(); }
        self::assertSame($before, $this->storageRows());
    }
    public function testLateUpgradeWriteFailureRollsBackEverySecret(): void
    {
        [, , , $key] = $this->legacyFixture(); $before = $this->storageRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER secret_upgrade_fail BEFORE UPDATE ON user_two_factor FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'upgrade failure'"
            : "CREATE TRIGGER secret_upgrade_fail BEFORE UPDATE ON user_two_factor BEGIN SELECT RAISE(ABORT, 'upgrade failure'); END");
        try { (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key))->upgrade(true); self::fail('Upgrade failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->storageRows()); }
    }
    public function testCipherCannotMoveBetweenServers(): void
    {
        [, $servers] = $this->secretFixture(); $one = $servers->createServer($this->serverData()); $two = $servers->createServer($this->serverData('other.example.test'));
        $stored = $this->db->selectOne('SELECT auth_secret_encrypted FROM servers WHERE id = ?', [$one->getId()])['auth_secret_encrypted'];
        $this->db->statement('UPDATE servers SET auth_secret_encrypted = ? WHERE id = ?', [$stored, $two->getId()]);
        $this->expectException(\RuntimeException::class); $servers->findServerById($two->getId());
    }
    public function testCipherCannotMoveBetweenTotpUsers(): void
    {
        [, , $two] = $this->secretFixture(); $setup = $two->beginSetup(7, 'one@example.test'); $two->beginSetup(8, 'two@example.test');
        $stored = $this->db->selectOne('SELECT secret FROM user_two_factor WHERE user_id = 7')['secret'];
        $this->db->statement('UPDATE user_two_factor SET secret = ? WHERE user_id = 8', [$stored]);
        $this->expectException(\RuntimeException::class); $two->confirmSetup(8, (new \Coleza\Domain\Identity\TwoFactor\TotpEngine())->generateCode($setup['secret']));
    }
    public function testUnknownEnvelopeCannotBeUpgradedAsPlaintext(): void
    {
        [, , , $key] = $this->legacyFixture();
        $this->db->statement('UPDATE servers SET auth_secret_encrypted = ?', ['enc:v9:unknown']); $before = $this->storageRows();
        try { (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key))->upgrade(true); self::fail('Unknown envelope accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->storageRows()); }
    }
    public function testSmtpLateFailureRollsBackAllSettings(): void
    {
        [$email] = $this->secretFixture(); $before = $this->storageRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER smtp_fail BEFORE INSERT ON system_settings FOR EACH ROW BEGIN IF NEW.setting_key = 'mail.password' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'smtp failure'; END IF; END"
            : "CREATE TRIGGER smtp_fail BEFORE INSERT ON system_settings WHEN NEW.setting_key = 'mail.password' BEGIN SELECT RAISE(ABORT, 'smtp failure'); END");
        try { $email->configureEmail($this->db, $this->smtpConfig()); self::fail('SMTP failure ignored'); }
        catch (\PDOException) { self::assertSame($before, $this->storageRows()); }
    }

    public function testWrongKeyCannotReadOrRewriteExistingEnvelopes(): void
    {
        [$email, $servers, $two] = $this->secretFixture();
        $email->configureEmail($this->db, $this->smtpConfig()); $servers->createServer($this->serverData()); $two->beginSetup(7, 'fixture@example.test');
        $before = $this->storageRows();
        try { (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, new \Coleza\Domain\Vault\Encryptor(random_bytes(32))))->upgrade(true); self::fail('Wrong key accepted'); }
        catch (\RuntimeException) { self::assertSame($before, $this->storageRows()); }
    }
    public static function legacyReaders(): array { return [['smtp'], ['server'], ['totp']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('legacyReaders')]
    public function testRuntimeNeverFallsBackToPlaintext(string $reader): void
    {
        [$email, $servers, $two, , $id, $secret] = $this->legacyFixture();
        $this->expectException(\LogicException::class);
        match ($reader) {
            'smtp' => $email->readConfiguration($this->db), 'server' => $servers->findServerById($id),
            'totp' => $two->confirmSetup(7, (new \Coleza\Domain\Identity\TwoFactor\TotpEngine())->generateCode($secret)),
        };
    }
    public function testMissingKeyStopsTotpBeforeSchemaOrWrites(): void
    {
        $previous = getenv('APP_KEY'); $this->db->enableQueryLog();
        try {
            putenv('APP_KEY');
            $two = new \Coleza\Domain\Identity\TwoFactor\TwoFactorService($this->db, new \Coleza\Domain\Identity\TwoFactor\TotpEngine(), new \Coleza\Domain\Identity\Security\PasswordHasher());
            try { $two->beginSetup(7, 'fixture@example.test'); self::fail('Missing key accepted'); }
            catch (\RuntimeException) { self::assertSame([], $this->db->getQueryLog()); }
        } finally { $previous === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $previous); }
    }

    public function testOversizedLegacyTotpRefusesBeforeChangingAnySecret(): void
    {
        [, , , $key] = $this->legacyFixture();
        $this->db->statement('UPDATE user_two_factor SET secret = ?', [str_repeat('JBSWY3DP', 8)]); $before = $this->storageRows();
        try { (new \Coleza\Domain\Vault\SecretStorageUpgrade($this->db, $key))->upgrade(true); self::fail('Oversized ciphertext accepted'); }
        catch (\LogicException) { self::assertSame($before, $this->storageRows()); }
    }

}
