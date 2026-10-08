<?php

declare(strict_types=1);

namespace Coleza\Domain\Providers\Cpanel;

use Coleza\Domain\Providers\Exceptions\ProviderException;

final class CpanelPackageService
{
    /**
     * Retrieve all WHM packages mapped to CpanelPackageDto list.
     *
     * @return array<string, CpanelPackageDto> Indexed by package name
     * @throws ProviderException
     */
    public function listPackages(CpanelApiClient $client): array
    {
        $rawList = $client->listPackagesRaw();
        $packages = [];

        foreach ($rawList as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dto = CpanelPackageDto::fromWhmArray($item);
            if ($dto->getName() !== '') {
                $packages[$dto->getName()] = $dto;
            }
        }

        return $packages;
    }

    /**
     * Get a specific WHM package by exact name.
     *
     * @throws ProviderException
     */
    public function getPackage(CpanelApiClient $client, string $packageName): ?CpanelPackageDto
    {
        $packages = $this->listPackages($client);
        return $packages[$packageName] ?? null;
    }

    /**
     * Resolve requested package with tolerance for case-insensitivity and reseller prefixes.
     *
     * @throws ProviderException
     */
    public function resolvePackage(CpanelApiClient $client, string $requestedPackageName): CpanelPackageDto
    {
        $cleanTarget = trim($requestedPackageName);
        if ($cleanTarget === '') {
            throw new ProviderException(
                message: 'Requested package identifier cannot be empty.',
                errorCode: 'EMPTY_PACKAGE_IDENTIFIER'
            );
        }

        $packages = $this->listPackages($client);

        // 1. Exact match
        if (isset($packages[$cleanTarget])) {
            return $packages[$cleanTarget];
        }

        // 2. Case-insensitive match
        $lowerTarget = strtolower($cleanTarget);
        foreach ($packages as $name => $pkg) {
            if (strtolower($name) === $lowerTarget) {
                return $pkg;
            }
        }

        // 3. Reseller prefix match (e.g. "reseller_silver" matching "silver" or vice-versa)
        foreach ($packages as $name => $pkg) {
            if (str_ends_with(strtolower($name), '_' . $lowerTarget)) {
                return $pkg;
            }
        }

        $availableNames = array_keys($packages);
        throw new ProviderException(
            message: "cPanel/WHM package '{$cleanTarget}' was not found on server '{$client->getConfiguration()->getHostname()}'.",
            errorCode: 'PACKAGE_NOT_FOUND',
            context: [
                'requested_package' => $cleanTarget,
                'available_packages' => $availableNames,
                'hostname' => $client->getConfiguration()->getHostname(),
            ]
        );
    }

    /**
     * Validate whether a remote WHM package satisfies local resource requirements.
     *
     * @param array<string, mixed> $requestedLimits
     * @return array{compatible: bool, violations: array<int, string>}
     */
    public function validatePackageCompatibility(CpanelPackageDto $package, array $requestedLimits = []): array
    {
        $violations = [];

        // Check disk limit requirement
        $requiredDiskMb = isset($requestedLimits['disk_limit_mb']) ? (int)$requestedLimits['disk_limit_mb'] : 0;
        if ($requiredDiskMb > 0 && !$package->isUnlimitedQuota() && $package->getQuotaMb() < $requiredDiskMb) {
            $violations[] = sprintf(
                "Package quota (%d MB) is less than required quota (%d MB).",
                $package->getQuotaMb(),
                $requiredDiskMb
            );
        }

        // Check bandwidth limit requirement
        $requiredBwMb = isset($requestedLimits['bandwidth_limit_mb']) ? (int)$requestedLimits['bandwidth_limit_mb'] : 0;
        if ($requiredBwMb > 0 && !$package->isUnlimitedBandwidth() && $package->getBandwidthMb() < $requiredBwMb) {
            $violations[] = sprintf(
                "Package bandwidth limit (%d MB) is less than required bandwidth (%d MB).",
                $package->getBandwidthMb(),
                $requiredBwMb
            );
        }

        // Check dedicated IP requirement
        $requiresDedicatedIp = (bool)($requestedLimits['dedicated_ip'] ?? false);
        if ($requiresDedicatedIp && !$package->hasDedicatedIp()) {
            $violations[] = "Package does not provide dedicated IP which was requested.";
        }

        return [
            'compatible' => count($violations) === 0,
            'violations' => $violations,
        ];
    }
}
