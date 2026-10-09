<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Whmcs\WhmcsPreflightService;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Domain\Migration\Whmcs\WhmcsSourceScanner;
use Coleza\Domain\Migration\Whmcs\WhmcsVersionInfo;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WhmcsSourceScanAndConnectorTest extends TestCase
{
    private Connection $db;
    private WhmcsReadOnlyConnector $connector;
    private WhmcsSourceScanner $scanner;
    private WhmcsPreflightService $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->connector = new WhmcsReadOnlyConnector($this->db);
        $this->scanner = new WhmcsSourceScanner($this->connector);
        $this->preflight = new WhmcsPreflightService($this->connector, $this->scanner);
    }

    public function testWhmcsReadOnlyConnectorPermitsSelectQueries(): void
    {
        $this->db->statement('CREATE TABLE test_table (id INT, name VARCHAR(50))');
        $this->db->statement("INSERT INTO test_table VALUES (1, 'Sample')");

        $rows = $this->connector->select('SELECT * FROM test_table WHERE id = :id', ['id' => 1]);
        $this->assertCount(1, $rows);
        $this->assertSame('Sample', $rows[0]['name']);

        $single = $this->connector->selectOne('SELECT name FROM test_table WHERE id = 1');
        $this->assertSame('Sample', $single['name']);

        $this->assertTrue($this->connector->tableExists('test_table'));
        $this->assertSame(1, $this->connector->countTableRecords('test_table'));
    }

    public function testWhmcsReadOnlyConnectorRejectsMutationsAndDdl(): void
    {
        $this->db->statement('CREATE TABLE immutable_table (id INT)');

        // INSERT rejection
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Write operation [INSERT INTO] is strictly forbidden');
        $this->connector->select('INSERT INTO immutable_table VALUES (2)');
    }

    public function testWhmcsReadOnlyConnectorRejectsDropAndTruncate(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Write operation [DROP TABLE] is strictly forbidden');
        $this->connector->select('DROP TABLE non_existent');
    }

    public function testWhmcsVersionParserSemantics(): void
    {
        $v8 = WhmcsVersionInfo::fromRawVersion('8.8.2-release.1');
        $this->assertSame('8.8.2-release.1', $v8->getRawVersion());
        $this->assertSame(8, $v8->getMajor());
        $this->assertSame(8, $v8->getMinor());
        $this->assertSame(2, $v8->getPatch());
        $this->assertTrue($v8->isV8OrHigher());
        $this->assertTrue($v8->supportsUserAccountsArchitecture());

        $v7 = WhmcsVersionInfo::fromRawVersion('7.10.2');
        $this->assertSame(7, $v7->getMajor());
        $this->assertSame(10, $v7->getMinor());
        $this->assertFalse($v7->isV8OrHigher());
        $this->assertTrue($v7->isV7());
        $this->assertFalse($v7->supportsUserAccountsArchitecture());
    }

    public function testWhmcsSourceScannerDetectsCapabilitiesTablesAndModules(): void
    {
        $this->seedMockWhmcsV8Database();

        $profile = $this->scanner->scanCapabilities();

        $this->assertSame('8.8.2', $profile->getVersion()->getRawVersion());
        $this->assertSame('MegaHosting Corp', $profile->getCompanyName());
        $this->assertSame('EUR', $profile->getDefaultCurrency());
        $this->assertTrue($profile->isCompatible());

        // Table inventory & counts
        $this->assertSame(3, $profile->getClientCount());
        $this->assertSame(4, $profile->getServiceCount());
        $this->assertSame(2, $profile->getDomainCount());
        $this->assertSame(5, $profile->getInvoiceCount());

        // Detected modules
        $this->assertContains('cpanel', $profile->getDetectedModules());
        $this->assertContains('plesk', $profile->getDetectedModules());

        // Detected registrars
        $this->assertContains('enom', $profile->getDetectedRegistrars());

        // Detected gateways
        $this->assertContains('stripe', $profile->getDetectedGateways());
        $this->assertContains('paypal', $profile->getDetectedGateways());
    }

    public function testWhmcsPreflightServiceDetectsOrphansAndReadiness(): void
    {
        $this->seedMockWhmcsV8Database();

        // Ingest 1 orphan service (userid = 999 where client does not exist)
        $this->db->statement(
            "INSERT INTO tblhosting (id, userid, packageid, domain, domainstatus)
             VALUES (99, 999, 1, 'orphansite.com', 'Active')"
        );

        $report = $this->preflight->runPreflight();

        $this->assertTrue($report->isReady());
        $this->assertSame('READY_WITH_WARNINGS', $report->getStatus());
        $this->assertSame(1, $report->getIntegrityStats()['orphan_services']);
        $this->assertEmpty($report->getBlockers());

        $foundWarning = false;
        foreach ($report->getWarnings() as $w) {
            if (str_contains($w, 'Detected 1 orphan services')) {
                $foundWarning = true;
            }
        }
        $this->assertTrue($foundWarning);
        $this->assertGreaterThan(10, $report->getTotalEstimatedEntities());
    }

    public function testWhmcsPreflightServiceFlagsIncompatibleWhenCoreTablesMissing(): void
    {
        // Empty DB without core tables
        $report = $this->preflight->runPreflight();

        $this->assertFalse($report->isReady());
        $this->assertSame('INCOMPATIBLE', $report->getStatus());
        $this->assertNotEmpty($report->getBlockers());
        $this->assertStringContainsString('Missing essential WHMCS tables', $report->getBlockers()[0]);
    }

    private function seedMockWhmcsV8Database(): void
    {
        $this->db->statement(
            'CREATE TABLE tblconfiguration (setting VARCHAR(64) PRIMARY KEY, value TEXT)'
        );
        $this->db->statement(
            "INSERT INTO tblconfiguration VALUES ('Version', '8.8.2'), ('CompanyName', 'MegaHosting Corp')"
        );

        $this->db->statement(
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code VARCHAR(3), `default` INT)'
        );
        $this->db->statement("INSERT INTO tblcurrencies VALUES (1, 'EUR', 1)");

        $this->db->statement(
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname VARCHAR(50), lastname VARCHAR(50), email VARCHAR(100))'
        );
        $this->db->statement(
            "INSERT INTO tblclients VALUES (1, 'Alice', 'Smith', 'alice@test.com'), (2, 'Bob', 'Jones', 'bob@test.com'), (3, 'Charlie', 'Day', 'charlie@test.com')"
        );

        $this->db->statement(
            'CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name VARCHAR(100), servertype VARCHAR(50))'
        );
        $this->db->statement(
            "INSERT INTO tblproducts VALUES (1, 'cPanel Shared', 'cpanel'), (2, 'Plesk VPS', 'plesk')"
        );

        $this->db->statement(
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, packageid INT, domain VARCHAR(100), domainstatus VARCHAR(30))'
        );
        $this->db->statement(
            "INSERT INTO tblhosting VALUES (1, 1, 1, 'alice.com', 'Active'), (2, 2, 1, 'bob.com', 'Active'), (3, 3, 2, 'charlie.com', 'Suspended'), (4, 1, 2, 'alicedev.com', 'Active')"
        );

        $this->db->statement(
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, domain VARCHAR(100), registrar VARCHAR(50), status VARCHAR(30))'
        );
        $this->db->statement(
            "INSERT INTO tbldomains VALUES (1, 1, 'alice.com', 'enom', 'Active'), (2, 2, 'bob.com', 'enom', 'Active')"
        );

        $this->db->statement(
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum VARCHAR(50), total DECIMAL(10,2), status VARCHAR(30))'
        );
        $this->db->statement(
            "INSERT INTO tblinvoices VALUES (1, 1, 'INV-1', 10.0, 'Paid'), (2, 1, 'INV-2', 15.0, 'Paid'), (3, 2, 'INV-3', 20.0, 'Unpaid'), (4, 3, 'INV-4', 30.0, 'Paid'), (5, 3, 'INV-5', 30.0, 'Unpaid')"
        );

        $this->db->statement(
            'CREATE TABLE tblpaymentgateways (gateway VARCHAR(50), setting VARCHAR(50), value TEXT)'
        );
        $this->db->statement(
            "INSERT INTO tblpaymentgateways VALUES ('stripe', 'name', 'Stripe'), ('paypal', 'name', 'PayPal')"
        );

        $this->db->statement('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT)');
        $this->db->statement('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, userid INT)');
    }
}
