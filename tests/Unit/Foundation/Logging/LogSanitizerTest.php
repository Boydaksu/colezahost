<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Logging;

use Coleza\Foundation\Logging\LogSanitizer;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    public function testRedactsSensitiveKeys(): void
    {
        $context = [
            'username' => 'alice',
            'password' => 'secret123',
            'api_token' => 'xyz-token',
            'nested' => [
                'credit_card' => '1234-5678-9012-3456',
                'cvv' => '999',
                'safe_field' => 'visible',
            ],
        ];

        $sanitized = LogSanitizer::sanitize($context);

        $this->assertSame('alice', $sanitized['username']);
        $this->assertSame('********', $sanitized['password']);
        $this->assertSame('********', $sanitized['api_token']);
        $this->assertSame('********', $sanitized['nested']['credit_card']);
        $this->assertSame('********', $sanitized['nested']['cvv']);
        $this->assertSame('visible', $sanitized['nested']['safe_field']);
    }
}
