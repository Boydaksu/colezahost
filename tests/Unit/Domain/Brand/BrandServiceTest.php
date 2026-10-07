<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Brand;

use Coleza\Domain\Brand\BrandService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class BrandServiceTest extends TestCase
{
    private Connection $connection;
    private BrandService $brandService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->brandService = new BrandService($this->connection);
    }

    public function testGetActiveBrandAutoInitializesDefaultBrand(): void
    {
        $brand = $this->brandService->getActiveBrand([
            'name' => 'Coleza Host Cloud',
            'code' => 'coleza-cloud',
            'default_locale' => 'tr_TR',
            'default_currency' => 'TRY',
        ]);

        $this->assertGreaterThan(0, $brand['id']);
        $this->assertSame('Coleza Host Cloud', $brand['name']);
        $this->assertSame('coleza-cloud', $brand['code']);
        $this->assertSame('tr_TR', $brand['default_locale']);
        $this->assertSame('TRY', $brand['default_currency']);
        $this->assertSame(1, (int) $brand['is_active']);
    }

    public function testUpdateActiveBrandSettings(): void
    {
        $this->brandService->getActiveBrand(['name' => 'Original Brand']);

        $updated = $this->brandService->updateActiveBrand([
            'name' => 'Coleza Enterprise Host',
            'domain' => 'colezahost.com',
            'default_currency' => 'USD',
            'settings' => [
                'support_email' => 'support@colezahost.com',
                'theme' => 'dark_mode',
            ],
        ]);

        $this->assertSame('Coleza Enterprise Host', $updated['name']);
        $this->assertSame('colezahost.com', $updated['domain']);
        $this->assertSame('USD', $updated['default_currency']);
        $this->assertSame('support@colezahost.com', $updated['settings']['support_email']);
    }

    public function testStrictSingleActiveBrandEnforcementInV1(): void
    {
        // First active brand created
        $this->brandService->getActiveBrand();

        // Attempting to create a second brand in V1 must throw ValidationException
        $this->expectException(ValidationException::class);
        $this->brandService->createBrand('Secondary Brand', 'sec-brand');
    }
}
