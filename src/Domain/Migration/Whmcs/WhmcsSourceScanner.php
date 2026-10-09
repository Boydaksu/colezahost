<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs;

final class WhmcsSourceScanner
{
    private const CORE_TABLES = [
        'tblclients',
        'tblusers',
        'tblusers_clients',
        'tblproducts',
        'tblproductgroups',
        'tblhosting',
        'tblservers',
        'tbldomains',
        'tblinvoices',
        'tblinvoiceitems',
        'tblaccounts',
        'tbltickets',
        'tblticketreplies',
        'tblcustomfields',
        'tblcustomfieldsvalues',
        'tblcurrencies',
        'tblpaymentgateways',
    ];

    private const REQUIRED_MINIMUM_TABLES = [
        'tblclients',
        'tblproducts',
        'tblhosting',
        'tblinvoices',
    ];

    public function __construct(
        private WhmcsReadOnlyConnector $connector
    ) {
    }

    public function scanCapabilities(): WhmcsCapabilityProfile
    {
        $warnings = [];
        $unsupported = [];

        // 1. Version and Basic Settings from tblconfiguration
        $rawVersion = '8.0.0';
        $companyName = 'Unknown Host';

        if ($this->connector->tableExists('tblconfiguration')) {
            $rows = $this->connector->select("SELECT setting, value FROM tblconfiguration WHERE setting IN ('Version', 'CompanyName')");
            foreach ($rows as $r) {
                if ($r['setting'] === 'Version') {
                    $rawVersion = (string) $r['value'];
                }
                if ($r['setting'] === 'CompanyName') {
                    $companyName = (string) $r['value'];
                }
            }
        } else {
            $warnings[] = 'tblconfiguration table is missing; source may not be a valid WHMCS database.';
        }

        $versionInfo = WhmcsVersionInfo::fromRawVersion($rawVersion);

        // 2. Default Currency from tblcurrencies
        $defaultCurrency = 'USD';
        if ($this->connector->tableExists('tblcurrencies')) {
            $currRow = $this->connector->selectOne("SELECT code FROM tblcurrencies WHERE `default` = 1 LIMIT 1");
            if ($currRow !== null && !empty($currRow['code'])) {
                $defaultCurrency = strtoupper((string) $currRow['code']);
            }
        }

        // 3. Table Inventory and Record Counts
        $tableInventory = [];
        $recordCounts = [];
        $missingRequired = [];

        foreach (self::CORE_TABLES as $table) {
            $exists = $this->connector->tableExists($table);
            $tableInventory[$table] = $exists;

            if ($exists) {
                $recordCounts[$table] = $this->connector->countTableRecords($table);
            } else {
                $recordCounts[$table] = 0;
                if (in_array($table, self::REQUIRED_MINIMUM_TABLES, true)) {
                    $missingRequired[] = $table;
                }
            }
        }

        // 4. Detect Active Server Modules from tblproducts
        $detectedModules = [];
        if ($this->connector->tableExists('tblproducts')) {
            $modRows = $this->connector->select("SELECT DISTINCT servertype FROM tblproducts WHERE servertype != '' AND servertype IS NOT NULL");
            foreach ($modRows as $mr) {
                $detectedModules[] = strtolower((string) $mr['servertype']);
            }
        }

        // 5. Detect Active Registrars from tbldomains
        $detectedRegistrars = [];
        if ($this->connector->tableExists('tbldomains')) {
            $regRows = $this->connector->select("SELECT DISTINCT registrar FROM tbldomains WHERE registrar != '' AND registrar IS NOT NULL");
            foreach ($regRows as $rr) {
                $detectedRegistrars[] = strtolower((string) $rr['registrar']);
            }
        }

        // 6. Detect Gateways from tblpaymentgateways or tblaccounts
        $detectedGateways = [];
        if ($this->connector->tableExists('tblpaymentgateways')) {
            $gwRows = $this->connector->select("SELECT DISTINCT gateway FROM tblpaymentgateways WHERE gateway != '' AND gateway IS NOT NULL");
            foreach ($gwRows as $gr) {
                $detectedGateways[] = strtolower((string) $gr['gateway']);
            }
        }

        // 7. Compatibility Assessment
        $isCompatible = empty($missingRequired);
        if (!$isCompatible) {
            $warnings[] = sprintf('Missing critical WHMCS tables: %s.', implode(', ', $missingRequired));
        }

        if ($versionInfo->getMajor() < 7) {
            $warnings[] = sprintf('Legacy WHMCS v%d is below recommended minimum v7.x.', $versionInfo->getMajor());
        }

        if ($versionInfo->isV7()) {
            $unsupported[] = 'WHMCS v7 multi-user relations are unlinked (single login per client).';
        }

        return new WhmcsCapabilityProfile(
            version: $versionInfo,
            companyName: $companyName,
            defaultCurrency: $defaultCurrency,
            tableInventory: $tableInventory,
            recordCounts: $recordCounts,
            detectedModules: array_values(array_unique($detectedModules)),
            detectedRegistrars: array_values(array_unique($detectedRegistrars)),
            detectedGateways: array_values(array_unique($detectedGateways)),
            isCompatible: $isCompatible,
            warnings: $warnings,
            unsupportedFeatures: $unsupported
        );
    }
}
