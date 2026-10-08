<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use Coleza\Domain\Providers\Contracts\AbstractProvider;
use Coleza\Domain\Providers\Contracts\ProviderCapability;
use Coleza\Domain\Providers\Contracts\ProviderCapabilitySet;
use Coleza\Domain\Providers\DTO\ServerConnectionDto;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Placement\ServerHealthResult;

final class CpanelProvider extends AbstractProvider
{
    public const SLUG = 'cpanel';
    public const NAME = 'cPanel & WHM';
    public const VERSION = '1.0.0';

    private CpanelPackageService $packageService;
    private CpanelServerHealthChecker $healthChecker;

    public function __construct(
        private ?CpanelHttpTransportInterface $transport = null,
        ?CpanelPackageService $packageService = null,
        ?CpanelServerHealthChecker $healthChecker = null
    ) {
        $capabilities = new ProviderCapabilitySet([
            ProviderCapability::CREATE_ACCOUNT,
            ProviderCapability::SUSPEND_ACCOUNT,
            ProviderCapability::UNSUSPEND_ACCOUNT,
            ProviderCapability::TERMINATE_ACCOUNT,
            ProviderCapability::CHANGE_PACKAGE,
            ProviderCapability::CHANGE_PASSWORD,
            ProviderCapability::USAGE_METRICS,
            ProviderCapability::SINGLE_SIGN_ON,
            ProviderCapability::CUSTOM_ACTION,
        ]);

        parent::__construct(
            name: self::NAME,
            slug: self::SLUG,
            version: self::VERSION,
            capabilities: $capabilities
        );

        $this->packageService = $packageService ?? new CpanelPackageService();
        $this->healthChecker = $healthChecker ?? new CpanelServerHealthChecker($this->transport);
    }

    public function createClient(ServerConnectionDto $connection): CpanelApiClient
    {
        $config = CpanelConfiguration::fromServerConnection($connection);
        return new CpanelApiClient($config, $this->transport);
    }

    /**
     * @return array{authenticated: bool, version: string, latency_ms: int}
     */
    public function testAuthentication(ServerConnectionDto $connection): array
    {
        $client = $this->createClient($connection);
        return $client->testAuthentication();
    }

    /**
     * @return array<string, CpanelPackageDto>
     */
    public function listPackages(ServerConnectionDto $connection): array
    {
        $client = $this->createClient($connection);
        return $this->packageService->listPackages($client);
    }

    public function checkHealth(Server $server): ServerHealthResult
    {
        return $this->healthChecker->checkHealth($server);
    }

    public function getPackageService(): CpanelPackageService
    {
        return $this->packageService;
    }

    public function getHealthChecker(): CpanelServerHealthChecker
    {
        return $this->healthChecker;
    }

    public function getTransport(): ?CpanelHttpTransportInterface
    {
        return $this->transport;
    }
}
