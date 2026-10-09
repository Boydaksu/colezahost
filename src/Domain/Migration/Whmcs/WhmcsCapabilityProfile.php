<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs;

use JsonSerializable;

final class WhmcsCapabilityProfile implements JsonSerializable
{
    /**
     * @param array<string, bool> $tableInventory
     * @param array<string, int> $recordCounts
     * @param list<string> $detectedModules
     * @param list<string> $detectedRegistrars
     * @param list<string> $detectedGateways
     * @param list<string> $warnings
     * @param list<string> $unsupportedFeatures
     */
    public function __construct(
        private WhmcsVersionInfo $version,
        private string $companyName,
        private string $defaultCurrency,
        private array $tableInventory,
        private array $recordCounts,
        private array $detectedModules,
        private array $detectedRegistrars,
        private array $detectedGateways,
        private bool $isCompatible,
        private array $warnings = [],
        private array $unsupportedFeatures = []
    ) {
    }

    public function getVersion(): WhmcsVersionInfo
    {
        return $this->version;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    /**
     * @return array<string, bool>
     */
    public function getTableInventory(): array
    {
        return $this->tableInventory;
    }

    /**
     * @return array<string, int>
     */
    public function getRecordCounts(): array
    {
        return $this->recordCounts;
    }

    /**
     * @return list<string>
     */
    public function getDetectedModules(): array
    {
        return $this->detectedModules;
    }

    /**
     * @return list<string>
     */
    public function getDetectedRegistrars(): array
    {
        return $this->detectedRegistrars;
    }

    /**
     * @return list<string>
     */
    public function getDetectedGateways(): array
    {
        return $this->detectedGateways;
    }

    public function isCompatible(): bool
    {
        return $this->isCompatible;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<string>
     */
    public function getUnsupportedFeatures(): array
    {
        return $this->unsupportedFeatures;
    }

    public function getClientCount(): int
    {
        return $this->recordCounts['tblclients'] ?? 0;
    }

    public function getServiceCount(): int
    {
        return $this->recordCounts['tblhosting'] ?? 0;
    }

    public function getDomainCount(): int
    {
        return $this->recordCounts['tbldomains'] ?? 0;
    }

    public function getInvoiceCount(): int
    {
        return $this->recordCounts['tblinvoices'] ?? 0;
    }

    public function getTransactionCount(): int
    {
        return $this->recordCounts['tblaccounts'] ?? 0;
    }

    public function getTicketCount(): int
    {
        return $this->recordCounts['tbltickets'] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version->toArray(),
            'company_name' => $this->companyName,
            'default_currency' => $this->defaultCurrency,
            'is_compatible' => $this->isCompatible,
            'table_inventory' => $this->tableInventory,
            'record_counts' => $this->recordCounts,
            'detected_modules' => $this->detectedModules,
            'detected_registrars' => $this->detectedRegistrars,
            'detected_gateways' => $this->detectedGateways,
            'warnings' => $this->warnings,
            'unsupported_features' => $this->unsupportedFeatures,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
