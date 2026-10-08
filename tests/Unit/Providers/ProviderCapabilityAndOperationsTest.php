<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Contracts\ProviderCapabilitySet;
use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use Coleza\Domain\Providers\Exceptions\ProviderException;
use Coleza\Domain\Providers\Exceptions\UnsupportedCapabilityException;
use Coleza\Domain\Providers\Operations\ChangePackageOperation;
use Coleza\Domain\Providers\Operations\ChangePasswordOperation;
use Coleza\Domain\Providers\Operations\CreateAccountOperation;
use Coleza\Domain\Providers\Operations\CustomActionOperation;
use Coleza\Domain\Providers\Operations\SingleSignOnOperation;
use Coleza\Domain\Providers\Operations\SuspendAccountOperation;
use Coleza\Domain\Providers\Operations\TerminateAccountOperation;
use Coleza\Domain\Providers\Operations\UnsuspendAccountOperation;
use Coleza\Domain\Providers\Operations\UsageMetricsOperation;
use Coleza\Domain\Providers\Registry\ProviderRegistry;
use Coleza\Domain\Providers\Testing\MockHostingProvider;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilityAndOperationsTest extends TestCase
{
    public function testProviderCapabilityValidationAndListing(): void
    {
        $all = ProviderCapability::all();
        $this->assertContains(ProviderCapability::CREATE_ACCOUNT, $all);
        $this->assertContains(ProviderCapability::SUSPEND_ACCOUNT, $all);
        $this->assertContains(ProviderCapability::UNSUSPEND_ACCOUNT, $all);
        $this->assertContains(ProviderCapability::TERMINATE_ACCOUNT, $all);
        $this->assertContains(ProviderCapability::CHANGE_PACKAGE, $all);
        $this->assertContains(ProviderCapability::CHANGE_PASSWORD, $all);
        $this->assertContains(ProviderCapability::USAGE_METRICS, $all);
        $this->assertContains(ProviderCapability::SINGLE_SIGN_ON, $all);
        $this->assertContains(ProviderCapability::CUSTOM_ACTION, $all);

        $this->assertTrue(ProviderCapability::isValid(ProviderCapability::CREATE_ACCOUNT));
        $this->assertFalse(ProviderCapability::isValid('invalid_capability'));
    }

    public function testProviderCapabilitySetOperations(): void
    {
        $set = new ProviderCapabilitySet([
            ProviderCapability::CREATE_ACCOUNT,
            ProviderCapability::SUSPEND_ACCOUNT,
        ]);

        $this->assertTrue($set->has(ProviderCapability::CREATE_ACCOUNT));
        $this->assertTrue($set->supports(ProviderCapability::SUSPEND_ACCOUNT));
        $this->assertFalse($set->has(ProviderCapability::TERMINATE_ACCOUNT));

        $extended = $set->with(ProviderCapability::TERMINATE_ACCOUNT);
        $this->assertTrue($extended->has(ProviderCapability::TERMINATE_ACCOUNT));
        $this->assertFalse($set->has(ProviderCapability::TERMINATE_ACCOUNT));

        $reduced = $extended->without(ProviderCapability::SUSPEND_ACCOUNT);
        $this->assertFalse($reduced->has(ProviderCapability::SUSPEND_ACCOUNT));
        $this->assertTrue($reduced->has(ProviderCapability::CREATE_ACCOUNT));

        // assertSupported succeeds on supported
        $extended->assertSupported('test-provider', ProviderCapability::CREATE_ACCOUNT);

        // assertSupported throws on unsupported
        $this->expectException(UnsupportedCapabilityException::class);
        $reduced->assertSupported('test-provider', ProviderCapability::SUSPEND_ACCOUNT);
    }

    public function testServerConnectionDtoMasking(): void
    {
        $dto = new ServerConnectionDto(
            serverId: 1,
            hostname: 'cpanel01.colezahost.com',
            ipAddress: '192.168.1.10',
            port: 2087,
            secure: true,
            authType: 'api_token',
            authSecret: 'whm_token_secret_12345'
        );

        $this->assertSame(1, $dto->getServerId());
        $this->assertSame('cpanel01.colezahost.com', $dto->getHostname());
        $this->assertSame('192.168.1.10', $dto->getIpAddress());
        $this->assertSame(2087, $dto->getPort());
        $this->assertTrue($dto->isSecure());
        $this->assertSame('api_token', $dto->getAuthType());
        $this->assertSame('whm_token_secret_12345', $dto->getAuthSecret());

        $this->assertStringStartsWith('wh', $dto->getMaskedSecret());
        $this->assertStringEndsWith('45', $dto->getMaskedSecret());
        $this->assertStringContainsString('****', $dto->getMaskedSecret());

        $safe = $dto->toSafeArray();
        $this->assertNotSame('whm_token_secret_12345', $safe['auth_secret']);
        $this->assertSame($dto->getMaskedSecret(), $safe['auth_secret']);
    }

    public function testProviderOperationResultFactories(): void
    {
        $success = ProviderOperationResult::success(
            operationType: ProviderCapability::CREATE_ACCOUNT,
            message: 'Account created',
            externalIdentifier: 'ext-999',
            data: ['ip' => '1.2.3.4']
        );

        $this->assertTrue($success->isSuccess());
        $this->assertFalse($success->isFailure());
        $this->assertSame(ProviderCapability::CREATE_ACCOUNT, $success->getOperationType());
        $this->assertSame('Account created', $success->getMessage());
        $this->assertSame('ext-999', $success->getExternalIdentifier());
        $this->assertSame(['ip' => '1.2.3.4'], $success->getData());
        $this->assertNull($success->getErrorCode());

        $failure = ProviderOperationResult::failure(
            operationType: ProviderCapability::CREATE_ACCOUNT,
            message: 'User already exists',
            errorCode: 'USER_EXISTS',
            data: ['conflict' => 'user123']
        );

        $this->assertFalse($failure->isSuccess());
        $this->assertTrue($failure->isFailure());
        $this->assertSame('USER_EXISTS', $failure->getErrorCode());
        $this->assertSame(['conflict' => 'user123'], $failure->getData());
    }

    public function testOperationDtosAndSecretMaskingInSerialization(): void
    {
        $server = new ServerConnectionDto(1, 'node.domain.com', authSecret: 'secret_root');

        // CreateAccountOperation
        $create = new CreateAccountOperation(
            serviceId: 10,
            serviceNumber: 'SRV-001',
            username: 'alice',
            password: 'plaintext_password_never_logged',
            domain: 'alice.com',
            packageIdentifier: 'starter_plan',
            resourceQuotas: ['disk' => 5000],
            server: $server
        );
        $this->assertSame(10, $create->getServiceId());
        $this->assertSame('alice', $create->getUsername());
        $this->assertSame('plaintext_password_never_logged', $create->getPassword());
        $createArray = $create->toArray();
        $this->assertSame('****', $createArray['password']);
        $this->assertNotSame('plaintext_password_never_logged', $createArray['password']);

        // ChangePasswordOperation
        $changePass = new ChangePasswordOperation(
            serviceId: 10,
            username: 'alice',
            newPassword: 'new_plaintext_pass',
            server: $server
        );
        $this->assertSame('new_plaintext_pass', $changePass->getNewPassword());
        $changePassArray = $changePass->toArray();
        $this->assertSame('****', $changePassArray['new_password']);
    }

    public function testMockProviderExecutesAndRecordsAllOperations(): void
    {
        $provider = new MockHostingProvider();
        $this->assertSame('Mock Hosting Provider', $provider->getName());
        $this->assertSame('mock-hosting', $provider->getSlug());
        $this->assertSame('1.0.0', $provider->getVersion());

        $server = new ServerConnectionDto(1, 'mock.node.local');

        // 1. Create Account
        $resCreate = $provider->createAccount(new CreateAccountOperation(
            serviceId: 1,
            serviceNumber: 'SRV-01',
            username: 'testuser',
            password: 'secretpassword',
            domain: 'testuser.net',
            packageIdentifier: 'pkg-standard',
            server: $server
        ));
        $this->assertTrue($resCreate->isSuccess());
        $this->assertSame('ext-user-testuser', $resCreate->getExternalIdentifier());

        // 2. Suspend Account
        $resSuspend = $provider->suspendAccount(new SuspendAccountOperation(
            serviceId: 1,
            username: 'testuser',
            reason: 'Billing overdue',
            server: $server
        ));
        $this->assertTrue($resSuspend->isSuccess());

        // 3. Unsuspend Account
        $resUnsuspend = $provider->unsuspendAccount(new UnsuspendAccountOperation(
            serviceId: 1,
            username: 'testuser',
            server: $server
        ));
        $this->assertTrue($resUnsuspend->isSuccess());

        // 4. Change Package
        $resPackage = $provider->changePackage(new ChangePackageOperation(
            serviceId: 1,
            username: 'testuser',
            newPackageIdentifier: 'pkg-premium',
            server: $server
        ));
        $this->assertTrue($resPackage->isSuccess());

        // 5. Change Password
        $resPass = $provider->changePassword(new ChangePasswordOperation(
            serviceId: 1,
            username: 'testuser',
            newPassword: 'newpassword123',
            server: $server
        ));
        $this->assertTrue($resPass->isSuccess());

        // 6. Usage Metrics
        $resMetrics = $provider->getUsageMetrics(new UsageMetricsOperation(
            serviceId: 1,
            username: 'testuser',
            server: $server
        ));
        $this->assertTrue($resMetrics->isSuccess());
        $this->assertSame(2048, $resMetrics->getData()['disk_used_mb']);

        // 7. Single Sign On
        $resSso = $provider->getSingleSignOnUrl(new SingleSignOnOperation(
            serviceId: 1,
            username: 'testuser',
            server: $server
        ));
        $this->assertTrue($resSso->isSuccess());
        $this->assertArrayHasKey('sso_url', $resSso->getData());

        // 8. Custom Action
        $resCustom = $provider->executeCustomAction(new CustomActionOperation(
            serviceId: 1,
            action: 'rebuild_dns',
            parameters: ['zone' => 'testuser.net'],
            server: $server
        ));
        $this->assertTrue($resCustom->isSuccess());

        // 9. Terminate Account
        $resTerminate = $provider->terminateAccount(new TerminateAccountOperation(
            serviceId: 1,
            username: 'testuser',
            keepBackup: true,
            server: $server
        ));
        $this->assertTrue($resTerminate->isSuccess());

        // Verify recorded history
        $recorded = $provider->getRecordedOperations();
        $this->assertCount(9, $recorded);
        $this->assertSame(ProviderCapability::TERMINATE_ACCOUNT, $provider->getLastOperation()?->getOperationType());
    }

    public function testProviderUnsupportedCapabilityThrowsException(): void
    {
        $limitedCapabilities = new ProviderCapabilitySet([
            ProviderCapability::CREATE_ACCOUNT,
            ProviderCapability::SUSPEND_ACCOUNT,
        ]);

        $limitedProvider = new MockHostingProvider(
            name: 'Limited Provider',
            slug: 'limited-provider',
            capabilities: $limitedCapabilities
        );

        $this->assertTrue($limitedProvider->supports(ProviderCapability::CREATE_ACCOUNT));
        $this->assertFalse($limitedProvider->supports(ProviderCapability::TERMINATE_ACCOUNT));

        // Terminate operation should throw UnsupportedCapabilityException
        $this->expectException(UnsupportedCapabilityException::class);
        $limitedProvider->terminateAccount(new TerminateAccountOperation(1, 'anyuser'));
    }

    public function testProviderRegistry(): void
    {
        $provider1 = new MockHostingProvider(name: 'cPanel Host', slug: 'cpanel');
        $provider2 = new MockHostingProvider(
            name: 'DNS Provider',
            slug: 'powerdns',
            capabilities: new ProviderCapabilitySet([ProviderCapability::CUSTOM_ACTION])
        );

        $registry = new ProviderRegistry([$provider1]);
        $this->assertTrue($registry->has('cpanel'));
        $this->assertFalse($registry->has('powerdns'));

        $registry->register($provider2);
        $this->assertTrue($registry->has('powerdns'));
        $this->assertSame($provider2, $registry->get('powerdns'));

        // Query by capability
        $cpanelProviders = $registry->getByCapability(ProviderCapability::CREATE_ACCOUNT);
        $this->assertCount(1, $cpanelProviders);
        $this->assertArrayHasKey('cpanel', $cpanelProviders);

        $customActionProviders = $registry->getByCapability(ProviderCapability::CUSTOM_ACTION);
        $this->assertCount(2, $customActionProviders);

        // assertSupports succeeds on supported capability
        $registry->assertSupports('cpanel', ProviderCapability::CREATE_ACCOUNT);

        // assertSupports throws on unsupported capability
        $this->expectException(UnsupportedCapabilityException::class);
        $registry->assertSupports('powerdns', ProviderCapability::CREATE_ACCOUNT);
    }

    public function testProviderRegistryThrowsOnNotFound(): void
    {
        $registry = new ProviderRegistry();
        $this->expectException(ProviderException::class);
        $registry->get('unknown-provider');
    }

    public function testMockProviderCannedResponses(): void
    {
        $provider = new MockHostingProvider();
        $cannedError = ProviderOperationResult::failure(
            operationType: ProviderCapability::CREATE_ACCOUNT,
            message: 'Server disk full',
            errorCode: 'DISK_FULL'
        );

        $provider->setCannedResponse(ProviderCapability::CREATE_ACCOUNT, $cannedError);

        $res = $provider->createAccount(new CreateAccountOperation(
            serviceId: 2,
            serviceNumber: 'SRV-02',
            username: 'bob',
            password: 'pass',
            domain: 'bob.com',
            packageIdentifier: 'pkg-basic'
        ));

        $this->assertFalse($res->isSuccess());
        $this->assertSame('DISK_FULL', $res->getErrorCode());
        $this->assertSame('Server disk full', $res->getMessage());
    }
}
