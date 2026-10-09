<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Migration;

use Coleza\Domain\Migration\Csv\CsvImportService;
use Coleza\Domain\Migration\Csv\CsvLocaleConfig;
use Coleza\Domain\Migration\Csv\CsvMappingProfile;
use Coleza\Domain\Migration\Csv\LocaleAwareCsvParser;
use Coleza\Domain\Migration\Csv\LocaleDateFormat;
use Coleza\Domain\Migration\Mapping\GenericMappingEngine;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class LocaleAwareCsvImportTest extends TestCase
{
    private Connection $db;
    private DatabaseStagingRepository $stagingRepo;
    private LocaleAwareCsvParser $parser;
    private CsvImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->stagingRepo = new DatabaseStagingRepository($this->db);
        $mappingEngine = new GenericMappingEngine();
        $valEngine = new CanonicalValidationEngine();
        $pipeline = new StagingPipelineService($this->stagingRepo, $mappingEngine, $valEngine);

        $this->parser = new LocaleAwareCsvParser();
        $this->importService = new CsvImportService($pipeline, $this->parser);
    }

    public function testDelimiterAutoDetection(): void
    {
        // Comma
        $commaCsv = "id,name,email\n1,Alice,alice@test.com\n2,Bob,bob@test.com";
        $this->assertSame(',', $this->parser->detectDelimiter($commaCsv));

        // Semicolon (European)
        $semicolonCsv = "id;name;email\n1;Alice;alice@test.com\n2;Bob;bob@test.com";
        $this->assertSame(';', $this->parser->detectDelimiter($semicolonCsv));

        // Tab (TSV)
        $tsvCsv = "id\tname\temail\n1\tAlice\talice@test.com";
        $this->assertSame("\t", $this->parser->detectDelimiter($tsvCsv));

        // Pipe
        $pipeCsv = "id|name|email\n1|Alice|alice@test.com";
        $this->assertSame('|', $this->parser->detectDelimiter($pipeCsv));
    }

    public function testUtf8BomStripping(): void
    {
        $bomCsv = "\xEF\xBB\xBFid,email\n100,test@example.com";
        $result = $this->parser->parseString($bomCsv);

        $this->assertSame('id', $result['headers'][0]); // First header has no corrupted BOM bytes
        $this->assertSame('email', $result['headers'][1]);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('100', $result['rows'][0]['id']);
        $this->assertSame('test@example.com', $result['rows'][0]['email']);
    }

    public function testLocaleAwareNumberParsing(): void
    {
        // 1. European format: 1.250,75 € (dot thousands, comma decimal)
        $numEuro = $this->parser->parseLocalizedNumber(
            value: '1.250,75 €',
            decimalSeparator: ',',
            thousandsSeparator: '.'
        );
        $this->assertSame(1250.75, $numEuro);

        // 2. US format: $1,250.75 (comma thousands, dot decimal)
        $numUs = $this->parser->parseLocalizedNumber(
            value: '$1,250.75',
            decimalSeparator: '.',
            thousandsSeparator: ','
        );
        $this->assertSame(1250.75, $numUs);

        // 3. Negative accounting notation: (350.50)
        $numNegative = $this->parser->parseLocalizedNumber(
            value: '(350.50)',
            decimalSeparator: '.',
            thousandsSeparator: ','
        );
        $this->assertSame(-350.50, $numNegative);

        // 4. Standard negative prefix: -45,00
        $numNegEuro = $this->parser->parseLocalizedNumber(
            value: '-45,00',
            decimalSeparator: ',',
            thousandsSeparator: '.'
        );
        $this->assertSame(-45.0, $numNegEuro);
    }

    public function testLocaleAwareDateParsing(): void
    {
        // ISO
        $this->assertSame('2026-10-15', $this->parser->parseLocalizedDate('2026-10-15'));
        $this->assertSame('2026-10-15', $this->parser->parseLocalizedDate('2026-10-15 14:30:00'));

        // DMY European / TR
        $this->assertSame(
            '2026-10-15',
            $this->parser->parseLocalizedDate('15/10/2026', LocaleDateFormat::DMY)
        );
        $this->assertSame(
            '2026-10-15',
            $this->parser->parseLocalizedDate('15.10.2026', LocaleDateFormat::DMY)
        );

        // MDY US
        $this->assertSame(
            '2026-10-15',
            $this->parser->parseLocalizedDate('10/15/2026', LocaleDateFormat::MDY)
        );

        // Disambiguation: 25/04/2026 has day 25 (> 12), must auto-detect as DMY
        $this->assertSame(
            '2026-04-25',
            $this->parser->parseLocalizedDate('25/04/2026', LocaleDateFormat::AUTO_DETECT)
        );
    }

    public function testCsvClientImportEndToEnd(): void
    {
        $batchId = 'BATCH-CSV-CLIENTS';
        $csv = <<<CSV
id;firstname;lastname;email;company;city;country
101;Ahmet;Yılmaz;ahmet@bulut.com;Bulut A.Ş.;İstanbul;TR
102;Mehmet;Demir;mehmet@demir.com;Demir Yazılım;Ankara;TR
CSV;

        $profile = CsvMappingProfile::forClients();
        $config = CsvLocaleConfig::europeanContinental();

        $result = $this->importService->importCsvString($csv, $profile, $batchId, $config);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->getTotalRowsParsed());
        $this->assertSame(2, $result->getStagedCount());
        $this->assertSame(0, $result->getQuarantinedCount());

        $report = $result->getAccountingReport();
        $this->assertNotNull($report);
        $this->assertSame(2, $report->getTotalStagedRecords());
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());

        // Verify stored in DB staging table
        $staged = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::VALIDATED);
        $this->assertCount(2, $staged);
        $this->assertSame('ahmet@bulut.com', $staged[0]->getCanonicalPayload()['email']);
        $this->assertSame('TR', $staged[0]->getCanonicalPayload()['country_code']);
    }

    public function testCsvInvoiceImportWithLocalizedCurrencyAndMath(): void
    {
        $batchId = 'BATCH-CSV-INVOICES';
        $csv = <<<CSV
id;userid;invoicenum;subtotal;tax;total;date;duedate
1;101;INV-2026-001;1.000,50 €;200,10 €;1.200,60 €;15.10.2026;25.10.2026
2;102;INV-2026-002;500,00 €;100,00 €;600,00 €;16.10.2026;26.10.2026
CSV;

        $profile = CsvMappingProfile::forInvoices();
        $config = CsvLocaleConfig::europeanContinental();

        $result = $this->importService->importCsvString($csv, $profile, $batchId, $config);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->getTotalRowsParsed());
        $this->assertSame(0, $result->getQuarantinedCount());

        $staged = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::VALIDATED);
        $this->assertCount(2, $staged);

        // Verify accurate numeric parsing without string corruptions
        $inv1 = $staged[0]->getCanonicalPayload();
        $this->assertSame(1000.50, $inv1['subtotal']);
        $this->assertSame(200.10, $inv1['tax']);
        $this->assertSame(1200.60, $inv1['total']);
        $this->assertSame('2026-10-15', $inv1['date']);
        $this->assertSame('2026-10-25', $inv1['due_date']);
    }

    public function testMalformedCsvLinesAndValidationFailuresQuarantinedWithoutSilentLoss(): void
    {
        $batchId = 'BATCH-CSV-CORRUPT';
        $csv = <<<CSV
id,first_name,last_name,email
1,Alice,Smith,alice@valid.com
2,CorruptLineWithoutEnoughColumns
3,Dave,InvalidEmail,not-a-valid-email-address
CSV;

        $profile = CsvMappingProfile::forClients();
        $config = CsvLocaleConfig::standardUs();

        $result = $this->importService->importCsvString($csv, $profile, $batchId, $config);

        // Row 1 is valid, Row 2 has column count mismatch (malformed syntax), Row 3 has invalid email
        $this->assertSame(2, $result->getTotalRowsParsed()); // Rows 1 & 3 parsed as rows
        $this->assertCount(1, $result->getParseErrors());    // Row 2 recorded as parse error

        $report = $result->getAccountingReport();
        $this->assertNotNull($report);
        // Total staged = 2 parsed + 1 syntax error = 3 records
        $this->assertSame(3, $report->getTotalStagedRecords());
        $this->assertSame(2, $report->getQuarantinedCount()); // Row 2 (syntax) + Row 3 (email error)

        // Core Invariant: Zero Silent Loss
        $this->assertSame(0, $report->getUnaccountedDiff());
        $this->assertTrue($report->isZeroSilentLossAchieved());

        // Verify quarantined records have exact audit trails
        $quarantined = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);
        $this->assertCount(2, $quarantined);

        $reasons = array_map(fn ($q) => $q->getQuarantineReason(), $quarantined);
        $hasSyntaxError = false;
        $hasValidationFailure = false;
        foreach ($reasons as $r) {
            if (str_contains($r, 'CSV Syntax Error on line 3')) {
                $hasSyntaxError = true;
            }
            if (str_contains($r, 'Canonical validation failure')) {
                $hasValidationFailure = true;
            }
        }
        $this->assertTrue($hasSyntaxError);
        $this->assertTrue($hasValidationFailure);
    }
}
