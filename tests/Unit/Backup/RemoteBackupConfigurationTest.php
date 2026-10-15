<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Backup;

use Coleza\Domain\Backup\Storage\{S3BackupStorageAdapter, SftpBackupStorageAdapter};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteBackupConfigurationTest extends TestCase
{
    public static function unsafeConfigurations(): array
    {
        return [
            'http' => ['s3', ['endpoint' => 'http://127.0.0.1']],
            'url-credentials' => ['s3', ['endpoint' => 'https://user:password@example.test']],
            'url-query' => ['s3', ['endpoint' => 'https://example.test?token=value']],
            'url-path' => ['s3', ['endpoint' => 'https://example.test/nested']],
            'bad-bucket' => ['s3', ['bucket' => '../bucket']],
            'region-injection' => ['s3', ['region' => 'region:s3']],
            'prefix-traversal' => ['s3', ['prefix' => 'backup/../other']],
            'header-injection' => ['s3', ['access_key' => "key\r\nAuthorization: value"]],
            'no-host-pin' => ['sftp', ['host_key_sha256' => '']],
            'md5-host-pin' => ['sftp', ['host_key_sha256' => str_repeat('f', 32)]],
            'ssh-relative-path' => ['sftp', ['remote_path' => 'relative']],
            'ssh-home-expansion' => ['sftp', ['remote_path' => '/~/backup']],
            'ssh-parent-path' => ['sftp', ['remote_path' => '/backup/../other']],
            'ssh-quote-injection' => ['sftp', ['remote_path' => '/backup" rm other']],
            'ssh-line-injection' => ['sftp', ['remote_path' => "/backup\nrm other"]],
            'ssh-host-url' => ['sftp', ['host' => 'host/another']],
            'ssh-port' => ['sftp', ['port' => 0]],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function testUnsafeConfigurationFailsBeforeNetworkIo(string $type, array $override): void
    {
        $config = array_replace(['bucket' => 'bucket', 'region' => 'us-east-1', 'access_key' => 'test', 'secret_key' => 'test',
            'endpoint' => 'https://example.test', 'host' => 'example.test', 'username' => 'test', 'password' => 'test',
            'host_key_sha256' => base64_encode(str_repeat('x', 32)), 'remote_path' => '/backups'], $override);
        $adapter = $type === 's3' ? new S3BackupStorageAdapter($config) : new SftpBackupStorageAdapter($config);
        $this->expectException(RuntimeException::class);
        $adapter->exists('safe');
    }
}
