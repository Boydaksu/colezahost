<?php

declare(strict_types=1);

namespace Coleza\Foundation\Runtime;

final class SystemRequirements
{
    public const string MIN_PHP_VERSION = '8.4.0';

    public const array REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'mbstring',
        'openssl',
        'json',
        'ctype',
        'curl',
    ];

    /**
     * @return array<int, string> List of missing requirements or errors (empty if all pass)
     */
    public function check(): array
    {
        $errors = [];

        // 1. PHP Version check
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $errors[] = sprintf(
                'PHP version %s does not meet the minimum requirement of %s.',
                PHP_VERSION,
                self::MIN_PHP_VERSION
            );
        }

        // 2. Required PHP extensions check
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = sprintf('Required PHP extension is missing: %s', $ext);
            }
        }

        return $errors;
    }

    public function isSatisfied(): bool
    {
        return count($this->check()) === 0;
    }
}
