<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domains;

use Coleza\Domain\Domains\Catalog\DomainCatalogService;
use Coleza\Domain\Domains\Catalog\Tld;
use Coleza\Domain\Domains\Catalog\TldPricing;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainCatalogTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DomainCatalogService $catalog;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->catalog = new DomainCatalogService($this->db);
        $this->catalog->ensureTables();
    }

    public function testTldNormalizationAndProperties(): void
    {
        $this->assertSame('.com', Tld::normalizeExtension('com'));
        $this->assertSame('.com', Tld::normalizeExtension('.com'));
        $this->assertSame('.com.tr', Tld::normalizeExtension('COM.TR'));
        $this->assertSame('.net', Tld::normalizeExtension('  .net  '));

        $tld = new Tld(
            id: 1,
            extension: '.com',
            isActive: true,
            registrarId: 'mock_registrar',
            minYears: 1,
            maxYears: 10
        );

        $this->assertSame('.com', $tld->getExtension());
        $this->assertTrue($tld->isActive());
        $this->assertSame('mock_registrar', $tld->getRegistrarId());
        $this->assertTrue($tld->supportsYears(1));
        $this->assertTrue($tld->supportsYears(5));
        $this->assertTrue($tld->supportsYears(10));
        $this->assertFalse($tld->supportsYears(0));
        $this->assertFalse($tld->supportsYears(11));

        $arr = $tld->toArray();
        $this->assertSame(1, $arr['id']);
        $this->assertSame('.com', $arr['extension']);
    }

    public function testRegisterTldAndDuplicatePrevention(): void
    {
        $tldCom = $this->catalog->registerTld([
            'extension' => 'com',
            'registrar_id' => 'resellerclub',
            'min_years' => 1,
            'max_years' => 10,
        ]);

        $this->assertSame('.com', $tldCom->getExtension());
        $this->assertSame('resellerclub', $tldCom->getRegistrarId());
        $this->assertTrue($tldCom->isActive());

        // Duplicate registration must fail
        $this->expectException(ValidationException::class);
        $this->catalog->registerTld(['extension' => '.COM']);
    }

    public function testRegisterTldInvalidExtensionThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->catalog->registerTld(['extension' => '']);
    }

    public function testUpdateAndDeleteTld(): void
    {
        $tld = $this->catalog->registerTld([
            'extension' => '.org',
            'registrar_id' => 'namesilo',
            'max_years' => 5,
        ]);

        $updated = $this->catalog->updateTld($tld->getId(), [
            'is_active' => false,
            'max_years' => 10,
            'registrar_id' => 'enom',
        ]);

        $this->assertFalse($updated->isActive());
        $this->assertSame(10, $updated->getMaxYears());
        $this->assertSame('enom', $updated->getRegistrarId());

        // Delete
        $this->assertTrue($this->catalog->deleteTld($tld->getId()));
        $this->assertNull($this->catalog->findTldById($tld->getId()));
    }

    public function testTldPricingSetupAndRetrieval(): void
    {
        $tld = $this->catalog->registerTld([
            'extension' => '.net',
            'registrar_id' => 'namesilo',
        ]);

        $pricing1 = $this->catalog->setTldPricing(
            tldId: $tld->getId(),
            operation: TldPricing::OPERATION_REGISTER,
            years: 1,
            priceMinor: 1499, // $14.99
            currencyCode: 'USD',
            costMinor: 1050
        );

        $this->assertSame(1499, $pricing1->getPriceMinor());
        $this->assertSame('USD', $pricing1->getCurrencyCode());
        $this->assertSame(1050, $pricing1->getCostMinor());

        // Upsert / update price
        $pricingUpdated = $this->catalog->setTldPricing(
            tldId: $tld->getId(),
            operation: TldPricing::OPERATION_REGISTER,
            years: 1,
            priceMinor: 1399, // discounted to $13.99
            currencyCode: 'USD'
        );

        $this->assertSame(1399, $pricingUpdated->getPriceMinor());

        // List pricing
        $this->catalog->setTldPricing($tld->getId(), TldPricing::OPERATION_RENEW, 1, 1599, 'USD');
        $this->catalog->setTldPricing($tld->getId(), TldPricing::OPERATION_REGISTER, 1, 1299, 'EUR');

        $usdPricing = $this->catalog->listTldPricing($tld->getId(), 'USD');
        $this->assertCount(2, $usdPricing);

        $allPricing = $this->catalog->listTldPricing($tld->getId());
        $this->assertCount(3, $allPricing);
    }

    public function testMultiYearPriceCalculation(): void
    {
        $tld = $this->catalog->registerTld([
            'extension' => '.com',
            'min_years' => 1,
            'max_years' => 10,
        ]);

        // Base 1-year registration: $12.00
        $this->catalog->setTldPricing($tld->getId(), TldPricing::OPERATION_REGISTER, 1, 1200, 'USD');

        // Multi-year without explicit tier: linear multiplication (3 * 1200 = 3600)
        $this->assertSame(3600, $this->catalog->calculateRegistrationPrice('.com', 3, 'USD'));

        // Explicit 2-year promotional discount: $22.00 instead of $24.00
        $this->catalog->setTldPricing($tld->getId(), TldPricing::OPERATION_REGISTER, 2, 2200, 'USD');
        $this->assertSame(2200, $this->catalog->calculateRegistrationPrice('.com', 2, 'USD'));

        // Renewal price fallback: renew not configured -> falls back to registration price
        $this->assertSame(2200, $this->catalog->calculateRenewalPrice('.com', 2, 'USD'));

        // Explicit 1-year renewal rate configured -> linear multi-year calculation (2 * 1300 = 2600)
        $this->catalog->setTldPricing($tld->getId(), TldPricing::OPERATION_RENEW, 1, 1300, 'USD');
        $this->assertSame(2600, $this->catalog->calculateRenewalPrice('.com', 2, 'USD'));

        // Transfer price fallback: transfer not configured -> falls back to 1-year registration price
        $this->assertSame(1200, $this->catalog->calculateTransferPrice('.com', 'USD'));

        // Unsupported duration throws
        $this->expectException(ValidationException::class);
        $this->catalog->calculateRegistrationPrice('.com', 12, 'USD');
    }

    public function testDomainSplitWithMultiPartTlds(): void
    {
        $this->catalog->registerTld(['extension' => '.com']);
        $this->catalog->registerTld(['extension' => '.com.tr']);
        $this->catalog->registerTld(['extension' => '.tr']);
        $this->catalog->registerTld(['extension' => '.co.uk']);

        // Single level
        $split1 = $this->catalog->splitDomain('mybrand.com');
        $this->assertSame('mybrand', $split1['sld']);
        $this->assertSame('.com', $split1['tld']);

        // Multi-part .com.tr (must not split as sld: mybrand.com, tld: .tr)
        $split2 = $this->catalog->splitDomain('mybrand.com.tr');
        $this->assertSame('mybrand', $split2['sld']);
        $this->assertSame('.com.tr', $split2['tld']);

        // .co.uk
        $split3 = $this->catalog->splitDomain('developer.co.uk');
        $this->assertSame('developer', $split3['sld']);
        $this->assertSame('.co.uk', $split3['tld']);
    }

    public function testDomainValidationRules(): void
    {
        $this->catalog->registerTld([
            'extension' => '.com',
            'is_idn_supported' => false,
            'min_years' => 1,
            'max_years' => 10,
        ]);

        $this->catalog->registerTld([
            'extension' => '.com.tr',
            'is_idn_supported' => true,
            'additional_fields' => [
                'required' => ['tckn_or_vkn'],
            ],
        ]);

        // 1. Valid standard domain
        $resValid = $this->catalog->validateDomainName('awesome-host.com', 2);
        $this->assertTrue($resValid->isValid());
        $this->assertSame('awesome-host.com', $resValid->getDomainName());
        $this->assertSame('awesome-host', $resValid->getSld());
        $this->assertSame('.com', $resValid->getTld());

        // 2. Starts with hyphen
        $resHyphenStart = $this->catalog->validateDomainName('-badstart.com');
        $this->assertFalse($resHyphenStart->isValid());
        $this->assertStringContainsString('hyphen', (string) $resHyphenStart->getFirstError());

        // 3. Ends with hyphen
        $resHyphenEnd = $this->catalog->validateDomainName('badend-.com');
        $this->assertFalse($resHyphenEnd->isValid());
        $this->assertStringContainsString('hyphen', (string) $resHyphenEnd->getFirstError());

        // 4. Consecutive hyphens at pos 3-4
        $resDoubleHyphen = $this->catalog->validateDomainName('ab--cd.com');
        $this->assertFalse($resDoubleHyphen->isValid());
        $this->assertStringContainsString('consecutive hyphens', (string) $resDoubleHyphen->getFirstError());

        // 5. Non-IDN TLD rejects non-ASCII or punycode
        $resIdn = $this->catalog->validateDomainName('xn--trk-goa6a.com');
        $this->assertFalse($resIdn->isValid());
        $this->assertStringContainsString('IDN', (string) $resIdn->getFirstError());

        // 6. Special required fields for .com.tr
        $resTrMissingField = $this->catalog->validateDomainName('ankara.com.tr');
        $this->assertFalse($resTrMissingField->isValid());
        $this->assertStringContainsString("tckn_or_vkn", (string) $resTrMissingField->getFirstError());

        $resTrWithField = $this->catalog->validateDomainName('ankara.com.tr', 1, ['tckn_or_vkn' => '12345678901']);
        $this->assertTrue($resTrWithField->isValid());

        // 7. Unsupported TLD
        $resUnknown = $this->catalog->validateDomainName('anything.unknown_tld');
        $this->assertFalse($resUnknown->isValid());
        $this->assertStringContainsString('Unsupported domain extension', (string) $resUnknown->getFirstError());
    }
}
