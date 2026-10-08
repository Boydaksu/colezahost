<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Coleza\Domain\Providers\Settings\ProviderSettingDefinition;
use Coleza\Domain\Providers\Settings\ProviderSettingSchema;
use Coleza\Domain\Providers\Settings\ProviderSettingService;
use Coleza\Domain\Vault\Encryptor;
use Coleza\Domain\Vault\VaultService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProviderSettingsAndVaultTest extends TestCase
{
    private Connection $db;
    private Encryptor $encryptor;
    private VaultService $vaultService;
    private ProviderSettingService $settingService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        // Test encryption key (exactly 32 bytes)
        $masterKey = str_repeat('k', 32);
        $this->encryptor = new Encryptor($masterKey);

        $this->vaultService = new VaultService($this->db, $this->encryptor);
        $this->vaultService->ensureTable();

        $this->settingService = new ProviderSettingService($this->db, $this->vaultService);
        $this->settingService->ensureTables();
    }

    public function testProviderSettingDefinitionValidation(): void
    {
        $urlDef = new ProviderSettingDefinition(
            key: 'endpoint',
            label: 'API Endpoint',
            type: ProviderSettingDefinition::TYPE_URL,
            isRequired: true
        );

        // Valid URL
        $urlDef->validate('https://cpanel.example.com:2087');
        $this->assertTrue(true);

        // Invalid URL throws ValidationException
        $this->expectException(ValidationException::class);
        $urlDef->validate('not-a-valid-url');
    }

    public function testProviderSettingSchemaDefinitions(): void
    {
        $schema = ProviderSettingSchema::forCpanel();
        $this->assertSame('cpanel', $schema->getProviderSlug());
        $this->assertTrue($schema->has('api_token'));
        $this->assertTrue($schema->isSecret('api_token'));
        $this->assertTrue($schema->has('username'));
        $this->assertFalse($schema->isSecret('username'));

        $defaults = $schema->getDefaults();
        $this->assertSame('root', $defaults['username']);
        $this->assertSame(2087, $defaults['port']);
        $this->assertTrue($defaults['use_ssl']);
        $this->assertArrayNotHasKey('api_token', $defaults); // Secrets don't have public defaults
    }

    public function testSaveSettingsRoutesSecretsToVaultAndPlainToDatabase(): void
    {
        $this->settingService->saveSettings('cpanel', [
            'username' => 'admin_user',
            'port' => 2087,
            'api_token' => 'whm_super_secret_token_12345',
        ], actorUserId: 1);

        // 1. Check database row: api_token value is NULL, is_secret is 1
        $tokenRow = $this->db->selectOne(
            'SELECT setting_value, is_secret FROM provider_module_settings WHERE provider_slug = ? AND setting_key = ?',
            ['cpanel', 'api_token']
        );
        $this->assertNotNull($tokenRow);
        $this->assertNull($tokenRow['setting_value']);
        $this->assertSame(1, (int)$tokenRow['is_secret']);

        // 2. Check database row: username value is plaintext
        $userRow = $this->db->selectOne(
            'SELECT setting_value, is_secret FROM provider_module_settings WHERE provider_slug = ? AND setting_key = ?',
            ['cpanel', 'username']
        );
        $this->assertSame('admin_user', $userRow['setting_value']);
        $this->assertSame(0, (int)$userRow['is_secret']);

        // 3. Check Vault: secret is encrypted and decryptable
        $decryptedSecret = $this->vaultService->getSecret('provider:cpanel', 'api_token');
        $this->assertSame('whm_super_secret_token_12345', $decryptedSecret);
    }

    public function testGetPublicSettingsStrictlyMasksSecrets(): void
    {
        $this->settingService->saveSettings('cpanel', [
            'username' => 'cpanel_root',
            'api_token' => 'whm_secret_token_abcdef',
        ]);

        $public = $this->settingService->getPublicSettings('cpanel');
        $this->assertSame('cpanel_root', $public['username']);
        $this->assertSame(2087, $public['port']); // Default from schema

        // api_token must be masked and never reveal plaintext
        $this->assertArrayHasKey('api_token', $public);
        $this->assertNotSame('whm_secret_token_abcdef', $public['api_token']);
        $this->assertStringContainsString('•', $public['api_token']);
    }

    public function testResolveEffectiveConfigMergesDefaultsNonSecretsAndDecryptedVaultSecrets(): void
    {
        $this->settingService->saveSettings('cpanel', [
            'username' => 'cluster_root',
            'api_token' => 'real_plaintext_whm_secret_token_9999',
            'timeout_seconds' => 45,
        ]);

        $config = $this->settingService->resolveEffectiveConfig('cpanel');

        $this->assertSame('cpanel', $config->getProviderSlug());
        $this->assertSame('cluster_root', $config->getString('username'));
        $this->assertSame(2087, $config->getInt('port')); // From schema default
        $this->assertSame(45, $config->getInt('timeout_seconds'));
        $this->assertTrue($config->getBool('use_ssl')); // From schema default

        // Plaintext secret is resolved for internal runtime usage
        $this->assertSame('real_plaintext_whm_secret_token_9999', $config->getSecret('api_token'));

        // toSafeArray() masks secret
        $safeArray = $config->toSafeArray();
        $this->assertNotSame('real_plaintext_whm_secret_token_9999', $safeArray['api_token']);
        $this->assertSame('••••••••', $safeArray['api_token']);
    }

    public function testSecretRotationWithoutOverwritingWithMaskedValue(): void
    {
        $this->settingService->saveSettings('cpanel', [
            'username' => 'root',
            'api_token' => 'initial_secret_123',
        ]);

        // Submit form without touching secret field (sending back masked value)
        $this->settingService->saveSettings('cpanel', [
            'username' => 'new_username',
            'api_token' => '••••••••',
        ]);

        // Secret in Vault should NOT have been overwritten with '••••••••'
        $config = $this->settingService->resolveEffectiveConfig('cpanel');
        $this->assertSame('initial_secret_123', $config->getSecret('api_token'));
        $this->assertSame('new_username', $config->getString('username'));

        // Now actively rotate secret with a real new value
        $this->settingService->saveSettings('cpanel', [
            'username' => 'new_username',
            'api_token' => 'rotated_brand_new_secret_456',
        ]);

        $rotatedConfig = $this->settingService->resolveEffectiveConfig('cpanel');
        $this->assertSame('rotated_brand_new_secret_456', $rotatedConfig->getSecret('api_token'));
    }

    public function testDeleteSettingsPurgesDatabaseAndVault(): void
    {
        $this->settingService->saveSettings('cpanel', [
            'username' => 'root',
            'api_token' => 'token_to_purge',
        ]);

        $this->settingService->deleteSettings('cpanel');

        $public = $this->settingService->getPublicSettings('cpanel');
        $this->assertSame('root', $public['username']); // Only default from schema remains
        $this->assertNull($this->vaultService->getSecret('provider:cpanel', 'api_token'));
    }
}
