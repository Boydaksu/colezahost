<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Runtime;

use Coleza\Foundation\Runtime\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testGetReturnsSetValue(): void
    {
        $env = new Environment(['APP_ENV' => 'testing', 'PORT' => '8080']);

        $this->assertSame('testing', $env->get('APP_ENV'));
        $this->assertSame('8080', $env->get('PORT'));
        $this->assertSame(8080, $env->getInt('PORT'));
        $this->assertNull($env->get('NON_EXISTENT'));
        $this->assertSame('default_val', $env->get('NON_EXISTENT', 'default_val'));
    }

    public function testGetBoolParsesTruthValues(): void
    {
        $env = new Environment([
            'DEBUG_TRUE' => 'true',
            'DEBUG_ONE' => '1',
            'DEBUG_YES' => 'yes',
            'DEBUG_FALSE' => 'false',
            'DEBUG_ZERO' => '0',
        ]);

        $this->assertTrue($env->getBool('DEBUG_TRUE'));
        $this->assertTrue($env->getBool('DEBUG_ONE'));
        $this->assertTrue($env->getBool('DEBUG_YES'));
        $this->assertFalse($env->getBool('DEBUG_FALSE'));
        $this->assertFalse($env->getBool('DEBUG_ZERO'));
        $this->assertFalse($env->getBool('UNSET_FLAG', false));
        $this->assertTrue($env->getBool('UNSET_FLAG', true));
    }

    public function testToMaskedArrayHidesSensitiveInformation(): void
    {
        $env = new Environment([
            'APP_NAME' => 'Coleza Host',
            'DB_PASSWORD' => 'super_secret_pw',
            'API_KEY' => 'key_xyz_123',
            'SESSION_TOKEN' => 'secret_token_val',
        ]);

        $masked = $env->toMaskedArray();

        $this->assertSame('Coleza Host', $masked['APP_NAME']);
        $this->assertSame('********', $masked['DB_PASSWORD']);
        $this->assertSame('********', $masked['API_KEY']);
        $this->assertSame('********', $masked['SESSION_TOKEN']);
    }

    public function testFromFileLoadsValidEnvVariables(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'env_test_');
        $content = "APP_NAME=\"Coleza Host\"\nAPP_DEBUG=true\nPORT=9000\n# Comment\n\nSECRET='quoted_secret'";
        file_put_contents($tmpFile, $content);

        try {
            $env = Environment::fromFile($tmpFile);
            $this->assertSame('Coleza Host', $env->get('APP_NAME'));
            $this->assertTrue($env->getBool('APP_DEBUG'));
            $this->assertSame(9000, $env->getInt('PORT'));
            $this->assertSame('quoted_secret', $env->get('SECRET'));
        } finally {
            @unlink($tmpFile);
        }
    }
}
