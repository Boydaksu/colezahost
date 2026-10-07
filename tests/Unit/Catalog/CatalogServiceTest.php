<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use Coleza\Domain\Catalog\Entities\Product;
use Coleza\Domain\Catalog\Entities\ProductGroup;
use Coleza\Domain\Catalog\Services\CatalogService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class CatalogServiceTest extends TestCase
{
    private Connection $db;
    private CatalogService $service;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->service = new CatalogService($this->db);
        $this->service->ensureTables();
    }

    public function testCreateAndRetrieveProductGroup(): void
    {
        $group = $this->service->createProductGroup([
            'name' => 'Web Hosting',
            'slug' => 'web-hosting',
            'description' => 'Fast cPanel web hosting solutions',
            'sort_order' => 1,
            'is_active' => true,
            'translations' => [
                'tr_TR' => [
                    'name' => 'Web Barındırma',
                    'description' => 'Hızlı cPanel web barındırma çözümleri',
                ],
                'en_US' => [
                    'name' => 'Web Hosting',
                    'description' => 'Fast cPanel web hosting solutions',
                ],
            ],
        ]);

        $this->assertNotNull($group->getId());
        $this->assertSame('web-hosting', $group->getSlug());
        $this->assertSame('Web Hosting', $group->getName());
        $this->assertSame('Web Barındırma', $group->getName('tr_TR'));
        $this->assertSame('Hızlı cPanel web barındırma çözümleri', $group->getDescription('tr_TR'));
        $this->assertTrue($group->isActive());

        $found = $this->service->findGroupBySlug('web-hosting');
        $this->assertNotNull($found);
        $this->assertSame($group->getId(), $found->getId());
        $this->assertSame('Web Barındırma', $found->getName('tr_TR'));
    }

    public function testDuplicateGroupSlugThrowsValidationException(): void
    {
        $this->service->createProductGroup([
            'name' => 'Cloud VPS',
            'slug' => 'cloud-vps',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createProductGroup([
            'name' => 'Another Cloud VPS',
            'slug' => 'cloud-vps',
        ]);
    }

    public function testCreateProductWithLocalizedContentAndFeatures(): void
    {
        $group = $this->service->createProductGroup([
            'name' => 'Shared Hosting',
            'slug' => 'shared-hosting',
        ]);

        $product = $this->service->createProduct([
            'group_id' => $group->getId(),
            'name' => 'Starter Plan',
            'slug' => 'starter-plan',
            'type' => Product::TYPE_HOSTING,
            'description' => 'Great for starter blogs and personal websites',
            'tag_line' => 'Best value for starters',
            'features' => ['10 GB NVMe Storage', 'Unmetered Bandwidth', '1 cPanel Account'],
            'sort_order' => 10,
            'is_active' => true,
            'is_featured' => true,
            'metadata' => [
                'cpanel_plan' => 'starter_pkg',
                'server_pool' => 'shared-lon1',
            ],
            'translations' => [
                'tr_TR' => [
                    'name' => 'Başlangıç Paketi',
                    'description' => 'Kişisel bloglar ve yeni başlayanlar için ideal',
                    'tag_line' => 'Yeni başlayanlar için en iyi fiyat',
                    'features' => ['10 GB NVMe Disk', 'Sınırsız Trafik', '1 cPanel Hesabı'],
                ],
                'en_US' => [
                    'name' => 'Starter Plan',
                    'description' => 'Great for starter blogs and personal websites',
                    'tag_line' => 'Best value for starters',
                    'features' => ['10 GB NVMe Storage', 'Unmetered Bandwidth', '1 cPanel Account'],
                ],
            ],
        ]);

        $this->assertNotNull($product->getId());
        $this->assertSame($group->getId(), $product->getGroupId());
        $this->assertSame(Product::TYPE_HOSTING, $product->getType());
        $this->assertTrue($product->isFeatured());
        $this->assertTrue($product->isActive());

        // Default & Localized text queries
        $this->assertSame('Starter Plan', $product->getName());
        $this->assertSame('Başlangıç Paketi', $product->getName('tr_TR'));
        $this->assertSame('Yeni başlayanlar için en iyi fiyat', $product->getTagLine('tr_TR'));
        $this->assertSame('10 GB NVMe Disk', $product->getFeatures('tr_TR')[0]);
        $this->assertSame('starter_pkg', $product->getMetadata()['cpanel_plan']);

        $found = $this->service->findProductBySlug('starter-plan');
        $this->assertNotNull($found);
        $this->assertSame($product->getId(), $found->getId());
        $this->assertSame('Başlangıç Paketi', $found->getName('tr_TR'));
    }

    public function testListProductsAndFiltering(): void
    {
        $group1 = $this->service->createProductGroup(['name' => 'Group 1', 'slug' => 'group-1']);
        $group2 = $this->service->createProductGroup(['name' => 'Group 2', 'slug' => 'group-2']);

        $this->service->createProduct([
            'group_id' => $group1->getId(),
            'name' => 'Product A',
            'slug' => 'product-a',
            'is_active' => true,
        ]);

        $this->service->createProduct([
            'group_id' => $group1->getId(),
            'name' => 'Product B',
            'slug' => 'product-b',
            'is_active' => false,
        ]);

        $this->service->createProduct([
            'group_id' => $group2->getId(),
            'name' => 'Product C',
            'slug' => 'product-c',
            'is_active' => true,
        ]);

        // Group 1: all vs active
        $allGroup1 = $this->service->listProductsByGroup($group1->getId(), false);
        $activeGroup1 = $this->service->listProductsByGroup($group1->getId(), true);
        $this->assertCount(2, $allGroup1);
        $this->assertCount(1, $activeGroup1);
        $this->assertSame('product-a', $activeGroup1[0]->getSlug());

        // All products across groups
        $allProducts = $this->service->listAllProducts(false);
        $allActive = $this->service->listAllProducts(true);
        $this->assertCount(3, $allProducts);
        $this->assertCount(2, $allActive);
    }

    public function testUpdateProductAndSlugUniqueness(): void
    {
        $group = $this->service->createProductGroup(['name' => 'Hosting', 'slug' => 'hosting']);
        $product = $this->service->createProduct([
            'group_id' => $group->getId(),
            'name' => 'Standard Plan',
            'slug' => 'standard-plan',
        ]);

        $updated = $this->service->updateProduct($product->getId(), [
            'name' => 'Standard Plus Plan',
            'sort_order' => 25,
            'is_featured' => true,
            'translations' => [
                'tr_TR' => ['name' => 'Standart Artı Paketi'],
            ],
        ]);

        $this->assertSame('Standard Plus Plan', $updated->getName());
        $this->assertSame('Standart Artı Paketi', $updated->getName('tr_TR'));
        $this->assertSame(25, $updated->getSortOrder());
        $this->assertTrue($updated->isFeatured());
    }

    public function testInvalidProductTypeThrowsException(): void
    {
        $group = $this->service->createProductGroup(['name' => 'Test Group', 'slug' => 'test-group']);

        $this->expectException(ValidationException::class);
        $this->service->createProduct([
            'group_id' => $group->getId(),
            'name' => 'Bad Type Product',
            'type' => 'unsupported_type',
        ]);
    }

    public function testConfigurableOptionsAndSubOptions(): void
    {
        $group = $this->service->createProductGroup(['name' => 'Cloud Servers', 'slug' => 'cloud-servers']);
        $product = $this->service->createProduct([
            'group_id' => $group->getId(),
            'name' => 'Cloud Compute 1',
            'slug' => 'compute-1',
            'type' => Product::TYPE_SERVER,
        ]);

        $option = $this->service->createConfigurableOption([
            'product_id' => $product->getId(),
            'name' => 'Operating System',
            'code' => 'operating-system',
            'type' => \Coleza\Domain\Catalog\Entities\ConfigurableOption::TYPE_DROPDOWN,
            'is_required' => true,
            'translations' => [
                'tr_TR' => ['name' => 'İşletim Sistemi'],
            ],
            'sub_options' => [
                [
                    'name' => 'Ubuntu 24.04 LTS',
                    'code' => 'ubuntu-2404',
                    'sort_order' => 1,
                ],
                [
                    'name' => 'AlmaLinux 9',
                    'code' => 'almalinux-9',
                    'sort_order' => 2,
                ],
                [
                    'name' => 'Debian 12',
                    'code' => 'debian-12',
                    'sort_order' => 3,
                ],
            ],
        ]);

        $this->assertNotNull($option->getId());
        $this->assertSame('operating-system', $option->getCode());
        $this->assertSame('İşletim Sistemi', $option->getName('tr_TR'));
        $this->assertTrue($option->isRequired());
        $this->assertCount(3, $option->getSubOptions());

        $fetchedOptions = $this->service->getOptionsForProduct($product->getId(), $group->getId());
        $this->assertCount(1, $fetchedOptions);
        $this->assertSame('Ubuntu 24.04 LTS', $fetchedOptions[0]->getSubOptions()[0]->getName());
    }

    public function testProductAddonsTargetingAndRetrieval(): void
    {
        $group = $this->service->createProductGroup(['name' => 'VPS', 'slug' => 'vps']);
        $p1 = $this->service->createProduct(['group_id' => $group->getId(), 'name' => 'VPS A', 'slug' => 'vps-a']);
        $p2 = $this->service->createProduct(['group_id' => $group->getId(), 'name' => 'VPS B', 'slug' => 'vps-b']);

        // Addon 1: Applicable only to p1
        $this->service->createProductAddon([
            'name' => 'Dedicated IP Address',
            'code' => 'dedicated-ip',
            'description' => 'Static IPv4 allocation',
            'applicable_product_ids' => [$p1->getId()],
            'translations' => [
                'tr_TR' => [
                    'name' => 'Statik IP Adresi',
                    'description' => 'Sabit IPv4 tahsisi',
                ],
            ],
        ]);

        // Addon 2: Global addon (applicable to all)
        $this->service->createProductAddon([
            'name' => 'Automated Daily Backups',
            'code' => 'daily-backups',
            'description' => 'Automated nightly snapshot and offsite backup',
            'applicable_product_ids' => [],
        ]);

        $addonsP1 = $this->service->getAddonsForProduct($p1->getId());
        $addonsP2 = $this->service->getAddonsForProduct($p2->getId());

        $this->assertCount(2, $addonsP1);
        $this->assertCount(1, $addonsP2);
        $this->assertSame('dedicated-ip', $addonsP1[0]->getCode());
        $this->assertSame('Statik IP Adresi', $addonsP1[0]->getName('tr_TR'));
        $this->assertSame('daily-backups', $addonsP2[0]->getCode());
    }

    public function testProductAvailabilityAndStockControl(): void
    {
        $group = $this->service->createProductGroup(['name' => 'Hardware', 'slug' => 'hardware']);
        $product = $this->service->createProduct([
            'group_id' => $group->getId(),
            'name' => 'Dedicated Xeon E3',
            'slug' => 'xeon-e3',
            'type' => Product::TYPE_SERVER,
        ]);

        // Initially without availability record -> default purchasable
        $defaultAvail = $this->service->getProductAvailability($product->getId());
        $this->assertTrue($defaultAvail->isPurchasable());

        // Configure stock tracking: 2 in stock, no backorders
        $avail = $this->service->setProductAvailability($product->getId(), [
            'status' => \Coleza\Domain\Catalog\Entities\ProductAvailability::STATUS_AVAILABLE,
            'stock_tracking_enabled' => true,
            'stock_quantity' => 2,
            'allow_backorders' => false,
            'max_per_customer' => 1,
        ]);

        $this->assertTrue($avail->isPurchasable());
        $this->assertSame(2, $avail->getStockQuantity());
        $this->assertSame(1, $avail->getMaxPerCustomer());

        // Decrement stock by 1
        $ok1 = $this->service->decrementStock($product->getId(), 1);
        $this->assertTrue($ok1);
        $this->assertSame(1, $this->service->getProductAvailability($product->getId())->getStockQuantity());

        // Decrement stock by 1 -> becomes 0
        $ok2 = $this->service->decrementStock($product->getId(), 1);
        $this->assertTrue($ok2);
        $emptyAvail = $this->service->getProductAvailability($product->getId());
        $this->assertSame(0, $emptyAvail->getStockQuantity());
        $this->assertFalse($emptyAvail->isPurchasable());

        // Attempting to decrement further fails without backorders
        $ok3 = $this->service->decrementStock($product->getId(), 1);
        $this->assertFalse($ok3);

        // Status override to retired makes it unpurchasable regardless
        $this->service->setProductAvailability($product->getId(), [
            'status' => \Coleza\Domain\Catalog\Entities\ProductAvailability::STATUS_RETIRED,
            'stock_tracking_enabled' => false,
        ]);
        $retired = $this->service->getProductAvailability($product->getId());
        $this->assertFalse($retired->isPurchasable());
    }
}

