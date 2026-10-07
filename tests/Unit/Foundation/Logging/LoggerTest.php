<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Logging;

use Coleza\Foundation\Logging\Logger;
use Coleza\Foundation\Logging\LogManager;
use Coleza\Foundation\Telemetry\CorrelationContext;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $tempLogFile;
    private string $tempLogsDir;

    protected function setUp(): void
    {
        $this->tempLogsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_logs_' . uniqid();
        mkdir($this->tempLogsDir, 0777, true);
        $this->tempLogFile = $this->tempLogsDir . DIRECTORY_SEPARATOR . 'test.log';
    }

    protected function tearDown(): void
    {
        CorrelationContext::setCurrent(null);
        $files = glob($this->tempLogsDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempLogsDir);
        parent::tearDown();
    }

    public function testLogsStructuredJsonEntryWithSanitization(): void
    {
        $context = CorrelationContext::generate('corr-test-fixed');
        CorrelationContext::setCurrent($context);

        $logger = new Logger($this->tempLogFile, 'app');
        $logger->info('User logged in successfully', [
            'user_id' => 123,
            'password' => 'secret',
        ]);

        $this->assertFileExists($this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertNotEmpty($content);

        $entry = json_decode(trim($content), true);
        $this->assertIsArray($entry);
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('app', $entry['channel']);
        $this->assertSame('User logged in successfully', $entry['message']);
        $this->assertSame('corr-test-fixed', $entry['correlation']['correlation_id']);
        $this->assertSame(123, $entry['context']['user_id']);
        $this->assertSame('********', $entry['context']['password']);
    }

    public function testLogManagerChannelSeparation(): void
    {
        $manager = new LogManager($this->tempLogsDir);

        $manager->app()->info('App event');
        $manager->security()->warning('Security event');
        $manager->audit()->notice('Audit event');

        $this->assertFileExists($this->tempLogsDir . DIRECTORY_SEPARATOR . 'app.log');
        $this->assertFileExists($this->tempLogsDir . DIRECTORY_SEPARATOR . 'security.log');
        $this->assertFileExists($this->tempLogsDir . DIRECTORY_SEPARATOR . 'audit.log');
    }
}
