<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Foundation\Logging;

use Coleza\Foundation\Logging\{Logger, LogSanitizer};
use Coleza\Foundation\Config\ConfigRepository;
use Coleza\Domain\Privacy\Export\PrivacyExportPackage;

final class SecretRedactionTest extends \PHPUnit\Framework\TestCase
{
    public function testLogMessageAndContextCannotRevealKnownOrEncodedCredentials(): void
    {
        $file = sys_get_temp_dir() . '/coleza-egress-' . bin2hex(random_bytes(12)) . '.log';
        try {
            (new Logger($file))->error('Failed with synthetic-unlabelled-password; Authorization: Bearer synthetic-bearer', [
                'password' => 'synthetic-unlabelled-password', 'metadata' => '{"APP_KEY":"synthetic-app-key"}',
                'url' => 'https://user:synthetic-url-password@example.test/callback?t%6fken=synthetic-query-token&ok=1',
            ]);
            $output = file_get_contents($file);
            foreach (['synthetic-unlabelled-password', 'synthetic-bearer', 'synthetic-app-key', 'synthetic-url-password', 'synthetic-query-token'] as $secret) { self::assertStringNotContainsString($secret, $output); }
            self::assertIsArray(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        } finally { if (is_file($file)) { unlink($file); } }
    }

    public function testObjectSerializationHooksAreNeverExecutedByLogSanitization(): void
    {
        $object = new class implements \JsonSerializable {
            public bool $called = false;
            public function jsonSerialize(): mixed { $this->called = true; return ['private' => 'synthetic-object-secret']; }
        };
        $output = json_encode(LogSanitizer::sanitize(['details' => $object]));
        self::assertFalse($object->called); self::assertStringNotContainsString('synthetic-object-secret', $output);
    }

    public function testSettingRowsAndConfigDiagnosticsMaskKeysWithoutBreakingRuntimeAccess(): void
    {
        $config = new ConfigRepository(['app' => ['key' => 'synthetic-config-key', 'name' => 'Coleza'], 'database' => ['password' => 'synthetic-db-password']]);
        self::assertSame('synthetic-config-key', $config->get('app.key'));
        self::assertStringNotContainsString('synthetic-config-key', json_encode($config->all()));
        self::assertStringNotContainsString('synthetic-db-password', json_encode($config->all()));
        self::assertSame('Coleza', $config->all()['app']['name']);
        $row = LogSanitizer::sanitize(['setting_key' => 'smtp.password', 'setting_value' => 'synthetic-setting-secret']);
        self::assertStringNotContainsString('synthetic-setting-secret', json_encode($row));
    }

    public function testExportPackageScrubsCustomCollectorAndNestedJsonBeforeChecksum(): void
    {
        $package = PrivacyExportPackage::create(7, ['profile' => ['name' => 'Alice', 'company' => 'Coleza', 'api_key_hash' => 'synthetic-key-hash'],
            'metadata' => '{"epp_code":"synthetic-epp-secret","password":"synthetic-export-password"}', 'invoices' => [['id' => 42, 'total' => 500]]]);
        foreach (['synthetic-key-hash', 'synthetic-epp-secret', 'synthetic-export-password'] as $secret) { self::assertStringNotContainsString($secret, $package->toJson()); }
        self::assertTrue($package->verifyIntegrity()); self::assertSame('Coleza', $package->getData()['profile']['company']);
        self::assertSame(500, $package->getData()['invoices'][0]['total']);
    }

    public static function secretAliases(): array
    {
        return [['app.key'], ['APP_KEY'], ['accessHash'], ['private_key'], ['epp_code'], ['otp_code'], ['recovery_codes'], ['Set-Cookie'], ['card'], ['api_key_hash'], ['X-CSRF'], ['db.pwd']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('secretAliases')]
    public function testAliasesAndSafeMetadata(string $key): void
    {
        $safe = LogSanitizer::sanitize([$key => 'synthetic-alias-secret', 'company' => 'Coleza', 'span_id' => 'trace-42', 'key_id' => 42]);
        self::assertStringNotContainsString('synthetic-alias-secret', json_encode($safe));
        self::assertSame('Coleza', $safe['company']); self::assertSame('trace-42', $safe['span_id']); self::assertSame(42, $safe['key_id']);
    }

    public function testExceptionTraceAndMalformedJsonAreNotExposed(): void
    {
        $exception = new \RuntimeException('synthetic-unlabelled-exception-secret');
        $safe = \Coleza\Foundation\Security\SecretRedactor::message($exception);
        self::assertStringNotContainsString('synthetic-unlabelled-exception-secret', $safe);
        $safe = LogSanitizer::sanitize(['body' => '{"pass\\u0077ord":"synthetic-malformed-secret"']);
        self::assertStringNotContainsString('synthetic-malformed-secret', json_encode($safe));
    }

    public function testCyclesAndInvalidUtf8CannotProduceUnredactedFallback(): void
    {
        $cycle = []; $cycle['self'] = &$cycle; $cycle['password'] = 'synthetic-cycle-secret';
        self::assertStringNotContainsString('synthetic-cycle-secret', json_encode(LogSanitizer::sanitize($cycle), JSON_THROW_ON_ERROR));
        $file = sys_get_temp_dir() . '/coleza-invalid-egress-' . bin2hex(random_bytes(12)) . '.log';
        try {
            (new Logger($file))->info("Invalid byte \xFF password=synthetic-invalid-secret");
            $raw = file_get_contents($file); self::assertIsArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('synthetic-invalid-secret', $raw);
        } finally { if (is_file($file)) { unlink($file); } }
    }

    public function testLegacyExportConstructorDoesNotAuthenticateModifiedData(): void
    {
        $at = new \DateTimeImmutable('2026-10-14 00:00:00');
        $data = ['profile' => ['name' => 'Alice', 'password' => 'synthetic-legacy-export-password']];
        $checksum = hash('sha256', json_encode(['schema' => 'colezahost_gdpr_export_v1', 'user_id' => 7, 'generated_at' => $at->format('Y-m-d H:i:s'), 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $package = new PrivacyExportPackage(7, $at, $data, $checksum);
        self::assertStringNotContainsString('synthetic-legacy-export-password', $package->toJson()); self::assertFalse($package->verifyIntegrity());
    }

    public function testInlineOauthCredentialsAndPrivateKeyBlocksAreMasked(): void
    {
        $private = '-----BEGIN ' . 'PRIVATE KEY-----' . "\nsynthetic-private-material\n" . '-----END ' . 'PRIVATE KEY-----';
        $input = 'https://example.test/callback?code=synthetic-oauth-code&state=synthetic-oauth-state&ok=1 access_hash=synthetic-access-hash ' . $private;
        $output = \Coleza\Foundation\Security\SecretRedactor::text($input);
        foreach (['synthetic-oauth-code', 'synthetic-oauth-state', 'synthetic-access-hash', 'synthetic-private-material'] as $secret) { self::assertStringNotContainsString($secret, $output); }
        self::assertStringContainsString('ok=1', $output);
    }

    public function testEscapedJsonFieldsInsideMessagesCannotBypassRedaction(): void
    {
        $input = 'Remote response: {"pass\\u0077ord":"synthetic-escaped-prefix\\"suffix"} trailing';
        $output = \Coleza\Foundation\Security\SecretRedactor::text($input);
        self::assertStringNotContainsString('synthetic-escaped-prefix', $output); self::assertStringNotContainsString('suffix', $output);
        $output = \Coleza\Foundation\Security\SecretRedactor::text('password="synthetic-quoted-prefix\\"suffix"');
        self::assertStringNotContainsString('synthetic-quoted-prefix', $output); self::assertStringNotContainsString('suffix', $output);
        self::assertSame('[INFO] worker ready', \Coleza\Foundation\Security\SecretRedactor::text('[INFO] worker ready'));
    }

    public function testKnownValueReplacementCannotRenameASecretFieldBeforeJsonRedaction(): void
    {
        $output = \Coleza\Foundation\Security\SecretRedactor::message('{"password":"synthetic-remote-generated-secret"}', ['password' => 'password']);
        self::assertStringNotContainsString('synthetic-remote-generated-secret', $output);
    }

    public function testBalancedEmbeddedJsonPreservesBenignAnnotationsAndSuffix(): void
    {
        $message='Invoice references unmigrated client [99]. Review mapping.';
        self::assertSame($message, \Coleza\Foundation\Security\SecretRedactor::text($message));
        $safe=\Coleza\Foundation\Security\SecretRedactor::text('Remote: {"password":"synthetic-fragment-secret","note":"[literal]"} status=failed');
        self::assertStringNotContainsString('synthetic-fragment-secret',$safe);
        self::assertStringContainsString('status=failed',$safe);
        self::assertStringNotContainsString('synthetic-fragment-secret',\Coleza\Foundation\Security\SecretRedactor::text('Remote: {"password":"synthetic-fragment-secret"'));
    }

    public function testNumericJsonPanIsMaskedWithoutHidingOrdinaryRecordIds(): void
    {
        $redactor=\Coleza\Foundation\Security\SecretRedactor::class;
        self::assertSame($redactor::MASK,$redactor::sanitize(['value'=>4111111111111111])['value']);
        self::assertStringNotContainsString('4111111111111111',$redactor::text('Remote [4111111111111111] status=failed'));
        self::assertStringContainsString('status=failed',$redactor::text('Remote [4111111111111111] status=failed'));
        self::assertSame('Client [99] unmigrated',$redactor::text('Client [99] unmigrated'));
    }
}
