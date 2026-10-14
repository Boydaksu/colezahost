<?php

declare(strict_types=1);

namespace Coleza\Foundation\Logging;

use Coleza\Foundation\Telemetry\CorrelationContext;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

final class Logger implements LoggerInterface
{
    public function __construct(
        private string $filePath,
        private string $channel = 'app'
    ) {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function emergency(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(#[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, #[\SensitiveParameter] string|Stringable $message, #[\SensitiveParameter] array $context = []): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sanitizedContext = LogSanitizer::sanitize($context);
        $correlation = CorrelationContext::current()->toArray();

        $record = [
            'timestamp' => $now->format('Y-m-d\TH:i:s.v\Z'),
            'level' => \Coleza\Foundation\Security\SecretRedactor::text(strtoupper((string) $level)),
            'channel' => \Coleza\Foundation\Security\SecretRedactor::text($this->channel),
            'message' => \Coleza\Foundation\Security\SecretRedactor::message($message, $context),
            'context' => $sanitizedContext,
            'correlation' => LogSanitizer::sanitize($correlation),
        ];

        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
    }
}
