<?php
declare(strict_types=1);
namespace Coleza\Tests\Integration\Backup;

use Coleza\Domain\Backup\{BackupManifest, BackupRetentionPolicy, BackupScope, EnterpriseBackupService};
use Coleza\Domain\Backup\Storage\{BackupStorageAdapterInterface, S3BackupStorageAdapter, SftpBackupStorageAdapter};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteBackupTransportTest extends TestCase
{
    private static string $root;
    private static array $ready;
    private static mixed $server;
    private static array $pipes = [];
    private string $directory;
    private string $prefix;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/coleza_protocol_' . bin2hex(random_bytes(8));
        mkdir(self::$root, 0700);
        self::$server = proc_open([getenv('COLEZA_BACKUP_TEST_PYTHON') ?: 'python', '-u', dirname(__DIR__, 2) . '/Support/backup_protocol_server.py', self::$root],
            [0 => ['pipe', 'r'], 1 => ['file', self::$root . '/stdout.txt', 'w'], 2 => ['file', self::$root . '/stderr.txt', 'w']], self::$pipes);
        if (!is_resource(self::$server)) { throw new RuntimeException('Cannot start protocol fixture.'); }
        $deadline = microtime(true) + 20;
        do {
            $data = is_file(self::$root . '/ready.json') ? json_decode((string) file_get_contents(self::$root . '/ready.json'), true) : null;
            if (is_array($data)) { self::$ready = $data; return; }
            if (!proc_get_status(self::$server)['running']) { break; }
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Protocol fixture did not start: ' . file_get_contents(self::$root . '/stderr.txt'));
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$pipes[0]) && is_resource(self::$pipes[0])) { fclose(self::$pipes[0]); }
        if (isset(self::$server) && is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        if (isset(self::$root)) { self::remove(self::$root); }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { self::remove($path . '/' . $name); } }
        rmdir($path);
    }

    protected function setUp(): void
    {
        $this->prefix = 'test_' . bin2hex(random_bytes(6));
        $this->directory = self::$root . '/' . $this->prefix;
        mkdir($this->directory, 0700);
        mkdir(self::$root . '/objects/' . $this->prefix, 0700);
        file_put_contents(self::$root . '/mode.json', '{}');
    }

    public static function protocols(): array { return ['s3' => ['s3'], 'sftp' => ['sftp']]; }

    private function adapter(string $type, array $overrides = []): BackupStorageAdapterInterface
    {
        $config = array_replace(self::$ready, ['prefix' => $this->prefix, 'remote_path' => '/' . $this->prefix, 'timeout_seconds' => 5], $overrides);
        return $type === 's3' ? new S3BackupStorageAdapter($config) : new SftpBackupStorageAdapter($config);
    }

    private function package(string $id, string $content = "backup\0İstanbul", int $age = 0): array
    {
        $archive = $this->directory . '/' . $id . '.source';
        $manifest = $this->directory . '/' . $id . '.json';
        file_put_contents($archive, $content);
        $record = new BackupManifest($id, 'files', '1.0', strlen($content), hash('sha256', $content), date('c', time() - $age * 86400));
        file_put_contents($manifest, json_encode($record->toArray(), JSON_THROW_ON_ERROR));
        return [$archive, $manifest];
    }

    private function remote(string $type, string $name): string
    {
        return self::$root . '/objects/' . ($type === 's3' ? 'bucket/' : '') . $this->prefix . '/' . $name;
    }

    private function fault(string $fault): void { file_put_contents(self::$root . '/mode.json', json_encode(['fault' => $fault], JSON_THROW_ON_ERROR)); }

    #[DataProvider('protocols')]
    public function testRealLifecycleUsesIndependentClientsAndPreservesExactBytes(string $type): void
    {
        [$archive, $manifest] = $this->package('stable');
        $error = null;
        try {
            self::assertTrue($this->adapter($type)->store('stable', $archive, $manifest));
            self::assertTrue($this->adapter($type)->exists('stable'));
            self::assertSame(['stable'], $this->adapter($type)->listBackups());
            self::assertTrue($this->adapter($type)->retrieve('stable', $this->directory . '/read'));
            self::assertSame(file_get_contents($archive), file_get_contents($this->directory . '/read/stable.zip'));
            self::assertSame(file_get_contents($manifest), file_get_contents($this->directory . '/read/stable.manifest.json'));
            self::assertTrue($this->adapter($type)->delete('stable'));
            self::assertFalse($this->adapter($type)->exists('stable'));
            self::assertFalse($this->adapter($type)->retrieve('stable', $this->directory . '/missing'));
            self::assertFalse($this->adapter($type)->delete('stable'));
            self::assertSame([], $this->adapter($type)->listBackups());
        } catch (RuntimeException $e) { $error = $e->getMessage(); }
        self::assertNull($error, 'Real protocol lifecycle failed: ' . $error);
    }

    #[DataProvider('protocols')]
    public function testRetriesAreIdempotentAndCollisionsCannotOverwrite(string $type): void
    {
        [$archive, $manifest] = $this->package('stable');
        self::assertTrue($this->adapter($type)->store('stable', $archive, $manifest));
        self::assertTrue($this->adapter($type)->store('stable', $archive, $manifest));
        [$changed, $changedManifest] = $this->package('stable', 'replacement');
        try { $this->adapter($type)->store('stable', $changed, $changedManifest); self::fail('Collision overwritten'); }
        catch (RuntimeException) { self::assertSame("backup\0İstanbul", file_get_contents($this->remote($type, 'stable.zip'))); }
    }

    public static function faults(): array
    {
        $cases = [];
        foreach (['s3', 'sftp'] as $type) { foreach (['reject-archive', 'reject-manifest', 'corrupt-read', 'truncate-read'] as $fault) { $cases[$type . '-' . $fault] = [$type, $fault]; } }
        return $cases;
    }

    #[DataProvider('faults')]
    public function testFailedDeliveryKeepsSourceAndDoesNotPrune(string $type, string $fault): void
    {
        [$archive, $manifest] = $this->package('older', 'known good', 5);
        $this->adapter($type)->store('older', $archive, $manifest);
        file_put_contents($this->directory . '/input.txt', 'new backup');
        $this->fault($fault);
        try {
            (new EnterpriseBackupService($this->directory . '/work'))->createBackup(BackupScope::FILES, $this->adapter($type), [$this->directory . '/input.txt'], retentionPolicy: new BackupRetentionPolicy(1, 0));
            self::fail('Failed transfer reported success');
        } catch (RuntimeException) {
            self::assertCount(1, glob($this->directory . '/work/*.zip'));
            self::assertCount(1, glob($this->directory . '/work/*.manifest.json'));
            self::assertSame('known good', file_get_contents($this->remote($type, 'older.zip')));
        }
    }

    #[DataProvider('protocols')]
    public function testAuthenticationFailureDoesNotExposeCredentials(string $type): void
    {
        [$archive, $manifest] = $this->package('secret');
        try { $this->adapter($type, ['password' => 'wrong-password', 'secret_key' => 'wrong-secret'])->store('secret', $archive, $manifest); self::fail('Invalid credentials accepted'); }
        catch (RuntimeException $e) { self::assertStringNotContainsString('wrong-password', $e->getMessage()); self::assertStringNotContainsString('wrong-secret', $e->getMessage()); }
        self::assertFileDoesNotExist($this->remote($type, 'secret.manifest.json'));
    }

    #[DataProvider('protocols')]
    public function testServerIdentityCannotBeBypassed(string $type): void
    {
        [$archive, $manifest] = $this->package('secret');
        $this->expectException(RuntimeException::class);
        $this->adapter($type, ['endpoint' => str_replace('127.0.0.1', 'localhost', self::$ready['endpoint']), 'host_key_sha256' => base64_encode(str_repeat('x', 32))])->store('secret', $archive, $manifest);
    }

    public function testSftpPrivateKeyAuthentication(): void
    {
        [$archive, $manifest] = $this->package('privatekey');
        self::assertTrue($this->adapter('sftp', ['password' => null, 'host_key_sha256' => 'SHA256:' . rtrim(self::$ready['host_key_sha256'], '=')])->store('privatekey', $archive, $manifest));
        self::assertTrue($this->adapter('sftp', ['password' => null])->exists('privatekey'));
    }

    #[DataProvider('protocols')]
    public function testUnicodeAndSpacesInConfiguredStoragePath(string $type): void
    {
        $path = $this->prefix . ' İstanbul archive';
        mkdir(self::$root . '/objects/' . $path, 0700);
        [$a, $m] = $this->package('unicode');
        $config = ['prefix' => $path, 'remote_path' => '/' . $path];
        self::assertTrue($this->adapter($type, $config)->store('unicode', $a, $m));
        self::assertSame(['unicode'], $this->adapter($type, $config)->listBackups());
        self::assertTrue($this->adapter($type, $config)->retrieve('unicode', $this->directory . '/read'));
        self::assertSame("backup\0İstanbul", file_get_contents($this->directory . '/read/unicode.zip'));
    }

    public function testS3DoesNotTrustAnUnconfiguredCertificateAuthority(): void
    {
        [$a, $m] = $this->package('untrusted');
        $config = array_replace(self::$ready, ['prefix' => $this->prefix]); unset($config['ca_bundle']);
        $this->expectException(RuntimeException::class);
        (new S3BackupStorageAdapter($config))->store('untrusted', $a, $m);
    }

    #[DataProvider('protocols')]
    public function testDownloadLimitCannotPublishAPartialFile(string $type): void
    {
        [$a, $m] = $this->package('limited'); $this->adapter($type)->store('limited', $a, $m);
        try { $this->adapter($type, ['max_archive_bytes' => 2])->retrieve('limited', $this->directory . '/read'); self::fail('Download exceeded limit'); }
        catch (RuntimeException) { self::assertFileDoesNotExist($this->directory . '/read/limited.zip'); }
    }

    #[DataProvider('protocols')]
    public function testTwoIndependentWritersCannotOverwriteOneAnother(string $type): void
    {
        [$a, $m] = $this->package('concurrent', 'writer-one');
        rename($a, $this->directory . '/one.zip'); rename($m, $this->directory . '/one.json');
        [$b, $n] = $this->package('concurrent', 'writer-two');
        $config = array_replace(self::$ready, ['prefix' => $this->prefix, 'remote_path' => '/' . $this->prefix, 'timeout_seconds' => 5]);
        $workers = [];
        try {
            foreach ([[$this->directory . '/one.zip', $this->directory . '/one.json'], [$b, $n]] as [$archive, $manifest]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/fixtures/remote-backup-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(compact('type', 'config', 'archive', 'manifest'), JSON_THROW_ON_ERROR)); fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $successes = 0;
            foreach ($workers as [$process, $pipes]) {
                $result = json_decode((string) stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('', stream_get_contents($pipes[2]));
                $successes += (int) $result['success'];
            }
            self::assertSame(1, $successes);
            self::assertTrue($this->adapter($type)->retrieve('concurrent', $this->directory . '/read'));
            self::assertContains(file_get_contents($this->directory . '/read/concurrent.zip'), ['writer-one', 'writer-two']);
        } finally {
            foreach ($workers as [$process, $pipes]) { fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
        }
    }

    #[DataProvider('protocols')]
    public function testIncompleteAndCorruptedPackagesAreNeverPublishedOrDeleted(string $type): void
    {
        [$archive, $manifest] = $this->package('partial');
        $path = $this->remote($type, 'partial.zip');
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        copy($archive, $path);
        self::assertFalse($this->adapter($type)->exists('partial'));
        self::assertSame([], $this->adapter($type)->listBackups());
        self::assertFalse($this->adapter($type)->delete('partial'));
        try { $this->adapter($type)->store('partial', $archive, $manifest); self::fail('Partial backup overwritten'); } catch (RuntimeException) { self::assertFileExists($path); }
        copy($manifest, $this->remote($type, 'partial.manifest.json'));
        file_put_contents($path, 'corruption');
        try { $this->adapter($type)->retrieve('partial', $this->directory . '/read'); self::fail('Corruption accepted'); } catch (RuntimeException) { self::assertFileDoesNotExist($this->directory . '/read/partial.zip'); }
        $this->expectException(RuntimeException::class);
        $this->adapter($type)->delete('partial');
    }

    #[DataProvider('protocols')]
    public function testExistingRemoteLockPreventsMutation(string $type): void
    {
        [$archive, $manifest] = $this->package('locked');
        $lock = $this->remote($type, 'locked.lock');
        if (!is_dir(dirname($lock))) { mkdir(dirname($lock), 0700, true); }
        if ($type === 's3') { file_put_contents($lock, 'another-writer'); } else { mkdir($lock, 0700); }
        try { $this->adapter($type)->store('locked', $archive, $manifest); self::fail('Lock ignored'); }
        catch (RuntimeException) { self::assertFileDoesNotExist($this->remote($type, 'locked.zip')); self::assertTrue(file_exists($lock)); }
    }

    #[DataProvider('protocols')]
    public function testVerifiedRemoteHistoryEnablesAutomaticRetention(string $type): void
    {
        foreach (['oldest' => 5, 'older' => 3] as $id => $age) { [$a, $m] = $this->package($id, 'good ' . $id, $age); $this->adapter($type)->store($id, $a, $m); }
        file_put_contents($this->directory . '/input.txt', 'current');
        $result = (new EnterpriseBackupService($this->directory . '/work'))->createBackup(BackupScope::FILES, $this->adapter($type), [$this->directory . '/input.txt'], retentionPolicy: new BackupRetentionPolicy(2, 0));
        self::assertTrue($result['verified']);
        self::assertFalse($this->adapter($type)->exists('oldest'));
        self::assertTrue($this->adapter($type)->exists('older'));
        self::assertTrue($this->adapter($type)->exists($result['manifest']->getBackupId()));
        self::assertSame([], glob($this->directory . '/work/*.zip'));
    }

    #[DataProvider('protocols')]
    public function testCorruptHistoryStopsRetentionBeforeAnyDeletion(string $type): void
    {
        foreach (['oldest' => 5, 'older' => 3] as $id => $age) { [$a, $m] = $this->package($id, 'good ' . $id, $age); $this->adapter($type)->store($id, $a, $m); }
        file_put_contents($this->remote($type, 'older.zip'), 'broken old backup');
        file_put_contents($this->directory . '/input.txt', 'current');
        try {
            (new EnterpriseBackupService($this->directory . '/work'))->createBackup(BackupScope::FILES, $this->adapter($type), [$this->directory . '/input.txt'], retentionPolicy: new BackupRetentionPolicy(1, 0));
            self::fail('Corrupt history admitted to retention');
        } catch (RuntimeException) {
            self::assertFileExists($this->remote($type, 'oldest.zip'));
            self::assertFileExists($this->remote($type, 'older.zip'));
            self::assertCount(1, glob($this->directory . '/work/*.zip'));
        }
    }

    public function testS3PaginationAndUnrelatedNames(): void
    {
        foreach (['a', 'b', 'c'] as $id) { [$a, $m] = $this->package($id); $this->adapter('s3')->store($id, $a, $m); }
        file_put_contents($this->remote('s3', '.orphan.upload'), 'unfinished');
        self::assertSame(['a', 'b', 'c'], $this->adapter('s3')->listBackups());
        $this->fault('bad-list');
        $this->expectException(RuntimeException::class);
        $this->adapter('s3')->listBackups();
    }

    #[DataProvider('protocols')]
    public function testDeleteFailureIsReportedAndKeepsPackage(string $type): void
    {
        [$a, $m] = $this->package('stable'); $this->adapter($type)->store('stable', $a, $m);
        $this->fault('reject-delete');
        try { $this->adapter($type)->delete('stable'); self::fail('Deletion failure ignored'); }
        catch (RuntimeException) { self::assertFileExists($this->remote($type, 'stable.zip')); self::assertFileExists($this->remote($type, 'stable.manifest.json')); }
    }
}
