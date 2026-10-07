<?php

declare(strict_types=1);

namespace Coleza\Foundation\Logging;

use Psr\Log\LoggerInterface;

final class LogManager
{
    /** @var array<string, LoggerInterface> */
    private array $loggers = [];

    public function __construct(private string $logsDirectory)
    {
    }

    public function app(): LoggerInterface
    {
        return $this->channel('app');
    }

    public function security(): LoggerInterface
    {
        return $this->channel('security');
    }

    public function audit(): LoggerInterface
    {
        return $this->channel('audit');
    }

    public function channel(string $name): LoggerInterface
    {
        if (isset($this->loggers[$name])) {
            return $this->loggers[$name];
        }

        $filePath = rtrim($this->logsDirectory, '/\\') . DIRECTORY_SEPARATOR . $name . '.log';
        $logger = new Logger($filePath, $name);

        $this->loggers[$name] = $logger;
        return $logger;
    }
}
