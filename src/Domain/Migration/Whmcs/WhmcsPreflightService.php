<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs;

final class WhmcsPreflightService
{
    public function __construct(
        private WhmcsReadOnlyConnector $connector,
        private ?WhmcsSourceScanner $scanner = null
    ) {
        $this->scanner = $scanner ?? new WhmcsSourceScanner($this->connector);
    }

    public function runPreflight(): WhmcsPreflightReport
    {
        $checksPassed = [];
        $warnings = [];
        $blockers = [];

        // 1. Connection check
        try {
            $this->connector->selectOne('SELECT 1 as test');
            $checksPassed[] = 'Read-only database connection verified successfully.';
        } catch (\Throwable $e) {
            $blockers[] = 'Database connection failed: ' . $e->getMessage();
            return new WhmcsPreflightReport(
                status: 'INCOMPATIBLE',
                capabilityProfile: new WhmcsCapabilityProfile(
                    version: WhmcsVersionInfo::fromRawVersion('0.0.0'),
                    companyName: '',
                    defaultCurrency: 'USD',
                    tableInventory: [],
                    recordCounts: [],
                    detectedModules: [],
                    detectedRegistrars: [],
                    detectedGateways: [],
                    isCompatible: false
                ),
                integrityStats: ['orphan_services' => 0, 'orphan_domains' => 0, 'orphan_invoices' => 0],
                totalEstimatedEntities: 0,
                blockers: $blockers
            );
        }

        // 2. Scan capabilities
        $profile = $this->scanner->scanCapabilities();
        $warnings = array_merge($warnings, $profile->getWarnings());

        if (!$profile->isCompatible()) {
            $blockers[] = 'Missing essential WHMCS tables required for migration.';
        } else {
            $checksPassed[] = sprintf('WHMCS v%s schema compatibility validated.', $profile->getVersion()->getRawVersion());
        }

        // 3. Data Integrity & Orphan detection
        $orphanServices = 0;
        $orphanDomains = 0;
        $orphanInvoices = 0;

        if ($this->connector->tableExists('tblhosting') && $this->connector->tableExists('tblclients')) {
            $row = $this->connector->selectOne(
                'SELECT COUNT(*) as cnt FROM tblhosting WHERE userid NOT IN (SELECT id FROM tblclients)'
            );
            $orphanServices = (int) ($row['cnt'] ?? 0);
            if ($orphanServices > 0) {
                $warnings[] = sprintf('Detected %d orphan services not tied to any existing client.', $orphanServices);
            }
        }

        if ($this->connector->tableExists('tbldomains') && $this->connector->tableExists('tblclients')) {
            $row = $this->connector->selectOne(
                'SELECT COUNT(*) as cnt FROM tbldomains WHERE userid NOT IN (SELECT id FROM tblclients)'
            );
            $orphanDomains = (int) ($row['cnt'] ?? 0);
            if ($orphanDomains > 0) {
                $warnings[] = sprintf('Detected %d orphan domains not tied to any existing client.', $orphanDomains);
            }
        }

        if ($this->connector->tableExists('tblinvoices') && $this->connector->tableExists('tblclients')) {
            $row = $this->connector->selectOne(
                'SELECT COUNT(*) as cnt FROM tblinvoices WHERE userid NOT IN (SELECT id FROM tblclients)'
            );
            $orphanInvoices = (int) ($row['cnt'] ?? 0);
            if ($orphanInvoices > 0) {
                $warnings[] = sprintf('Detected %d orphan invoices not tied to any existing client.', $orphanInvoices);
            }
        }

        if ($orphanServices === 0 && $orphanDomains === 0 && $orphanInvoices === 0) {
            $checksPassed[] = 'Foreign-key relational integrity verified (0 orphans).';
        }

        // 4. Calculate total estimated entity volume
        $totalEntities = $profile->getClientCount()
            + $profile->getServiceCount()
            + $profile->getDomainCount()
            + $profile->getInvoiceCount()
            + $profile->getTransactionCount()
            + $profile->getTicketCount();

        $checksPassed[] = sprintf('Estimated %d total entities across 6 core domains ready for staging.', $totalEntities);

        // 5. Overall status determination
        $status = 'READY';
        if (!empty($blockers)) {
            $status = 'INCOMPATIBLE';
        } elseif (!empty($warnings)) {
            $status = 'READY_WITH_WARNINGS';
        }

        return new WhmcsPreflightReport(
            status: $status,
            capabilityProfile: $profile,
            integrityStats: [
                'orphan_services' => $orphanServices,
                'orphan_domains' => $orphanDomains,
                'orphan_invoices' => $orphanInvoices,
            ],
            totalEstimatedEntities: $totalEntities,
            checksPassed: $checksPassed,
            warnings: $warnings,
            blockers: $blockers
        );
    }
}
