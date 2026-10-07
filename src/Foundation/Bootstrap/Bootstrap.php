<?php

declare(strict_types=1);

namespace Coleza\Foundation\Bootstrap;

use Coleza\Foundation\Runtime\Environment;
use Coleza\Foundation\Runtime\SystemRequirements;
use RuntimeException;
use Throwable;

final class Bootstrap
{
    private static bool $booted = false;
    private static ?Environment $bootedEnvironment = null;

    public static function boot(?Environment $environment = null): Environment
    {
        if (self::$booted) {
            return self::$bootedEnvironment ?? ($environment ?? new Environment());
        }

        // 1. Force strict UTC timezone per architecture contract
        date_default_timezone_set('UTC');

        // 2. Force UTF-8 internal encoding
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        // 3. Verify system requirements
        $sysReq = new SystemRequirements();
        $errors = $sysReq->check();
        if (count($errors) > 0) {
            throw new RuntimeException(
                'System requirement check failed: ' . implode('; ', $errors)
            );
        }

        // 4. Register error and exception handlers
        self::registerErrorHandling();

        self::$booted = true;
        self::$bootedEnvironment = $environment ?? new Environment();

        return self::$bootedEnvironment;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    /**
     * Resets bootstrap state (used primarily in test suites).
     */
    public static function reset(): void
    {
        self::$booted = false;
        self::$bootedEnvironment = null;
        restore_error_handler();
        restore_exception_handler();
    }

    private static function registerErrorHandling(): void
    {
        // Convert PHP native warnings/notices to ErrorException
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $level)) {
                return false;
            }
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        // Set global exception handler
        set_exception_handler(static function (Throwable $e): void {
            // Fallback emergency logging / output
            error_log(sprintf('[Coleza Host Critical Error] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            if (!headers_sent()) {
                http_response_code(500);
            }
        });
    }
}
