<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Services;

use Coleza\Domain\Catalog\Entities\ConfigurableOption;
use Coleza\Domain\Catalog\Entities\ConfigurableOptionSub;
use Coleza\Domain\Catalog\Entities\Product;
use Coleza\Domain\Catalog\Entities\ProductAddon;
use Coleza\Domain\Catalog\Entities\ProductAvailability;
use Coleza\Domain\Catalog\Entities\ProductGroup;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class CatalogService
{
    private string $groupsTable = 'product_groups';
    private string $productsTable = 'products';
    private string $optionsTable = 'configurable_options';
    private string $subOptionsTable = 'configurable_option_subs';
    private string $addonsTable = 'product_addons';
    private string $availabilityTable = 'product_availability';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Product groups table
        $sqlGroups = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                slug VARCHAR(100) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->groupsTable,
            $autoInc
        );
        $this->db->statement($sqlGroups);

        // Products table
        $sqlProducts = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                group_id INT NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                type VARCHAR(50) NOT NULL DEFAULT "hosting",
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                tag_line VARCHAR(255) NULL,
                features_json TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                is_featured INT NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->productsTable,
            $autoInc
        );
        $this->db->statement($sqlProducts);

        // Configurable options table
        $sqlOptions = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                product_id INT NULL,
                group_id INT NULL,
                name VARCHAR(150) NOT NULL,
                code VARCHAR(100) NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT "dropdown",
                sort_order INT NOT NULL DEFAULT 0,
                is_required INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->optionsTable,
            $autoInc
        );
        $this->db->statement($sqlOptions);

        // Configurable sub-options table
        $sqlSubOptions = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                option_id INT NOT NULL,
                name VARCHAR(150) NOT NULL,
                code VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->subOptionsTable,
            $autoInc
        );
        $this->db->statement($sqlSubOptions);

        // Product addons table
        $sqlAddons = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(150) NOT NULL,
                code VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                applicable_products_json TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active INT NOT NULL DEFAULT 1,
                translations_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->addonsTable,
            $autoInc
        );
        $this->db->statement($sqlAddons);

        // Product availability table
        $sqlAvailability = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                product_id INT PRIMARY KEY,
                status VARCHAR(50) NOT NULL DEFAULT "available",
                stock_tracking_enabled INT NOT NULL DEFAULT 0,
                stock_quantity INT NOT NULL DEFAULT 0,
                allow_backorders INT NOT NULL DEFAULT 0,
                max_per_customer INT NULL,
                available_from VARCHAR(50) NULL,
                available_until VARCHAR(50) NULL
            )',
            $this->availabilityTable
        );
        $this->db->statement($sqlAvailability);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProductGroup(array $data): ProductGroup
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Name is required.']], 'Product group name is required.');
        }

        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->sanitizeSlug($slug);
        }

        if ($this->findGroupBySlug($slug) !== null) {
            throw new ValidationException(['slug' => ['Slug already in use.']], "Product group slug '{$slug}' already exists.");
        }

        $description = isset($data['description']) ? trim((string)$data['description']) : null;
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = (bool)($data['is_active'] ?? true);
        $translations = isset($data['translations']) && is_array($data['translations']) ? $data['translations'] : [];

        $sql = sprintf(
            'INSERT INTO %s (slug, name, description, sort_order, is_active, translations_json) VALUES (?, ?, ?, ?, ?, ?)',
            $this->groupsTable
        );

        $this->db->statement($sql, [
            $slug,
            $name,
            $description,
            $sortOrder,
            $isActive ? 1 : 0,
            json_encode($translations, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new ProductGroup(
            id: $id,
            slug: $slug,
            name: $name,
            description: $description,
            sortOrder: $sortOrder,
            isActive: $isActive,
            translations: $translations,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findGroupById(int $id): ?ProductGroup
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->groupsTable), [$id]);
        return $row ? $this->hydrateGroup($row) : null;
    }

    public function findGroupBySlug(string $slug): ?ProductGroup
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE slug = ?', $this->groupsTable), [$slug]);
        return $row ? $this->hydrateGroup($row) : null;
    }

    /**
     * @return array<ProductGroup>
     */
    public function listGroups(bool $onlyActive = false): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->groupsTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $rows = $this->db->select($sql);
        return array_map([$this, 'hydrateGroup'], $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProductGroup(int $id, array $data): ProductGroup
    {
        $group = $this->findGroupById($id);
        if ($group === null) {
            throw new RuntimeException("Product group with ID {$id} not found.");
        }

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                throw new ValidationException(['name' => ['Name cannot be empty.']], 'Product group name cannot be empty.');
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }

        if (isset($data['slug'])) {
            $slug = $this->sanitizeSlug((string)$data['slug']);
            $existing = $this->findGroupBySlug($slug);
            if ($existing !== null && $existing->getId() !== $id) {
                throw new ValidationException(['slug' => ['Slug already in use.']], "Product group slug '{$slug}' already in use.");
            }
            $fields[] = 'slug = ?';
            $params[] = $slug;
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $data['description'] !== null ? trim((string)$data['description']) : null;
        }

        if (isset($data['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$data['sort_order'];
        }

        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }

        if (isset($data['translations']) && is_array($data['translations'])) {
            $fields[] = 'translations_json = ?';
            $params[] = json_encode($data['translations'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($fields)) {
            $params[] = $id;
            $sql = sprintf('UPDATE %s SET %s WHERE id = ?', $this->groupsTable, implode(', ', $fields));
            $this->db->statement($sql, $params);
        }

        return $this->findGroupById($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProduct(array $data): Product
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if ($this->findGroupById($groupId) === null) {
            throw new ValidationException(['group_id' => ['Invalid group ID.']], "Product group ID {$groupId} does not exist.");
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Name is required.']], 'Product name is required.');
        }

        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->sanitizeSlug($slug);
        }

        if ($this->findProductBySlug($slug) !== null) {
            throw new ValidationException(['slug' => ['Slug already in use.']], "Product slug '{$slug}' already exists.");
        }

        $validTypes = [
            Product::TYPE_HOSTING,
            Product::TYPE_DOMAIN,
            Product::TYPE_SSL,
            Product::TYPE_SERVER,
            Product::TYPE_OTHER,
        ];
        $type = (string)($data['type'] ?? Product::TYPE_HOSTING);
        if (!in_array($type, $validTypes, true)) {
            throw new ValidationException(['type' => ['Invalid type.']], "Invalid product type '{$type}'.");
        }

        $description = isset($data['description']) ? trim((string)$data['description']) : null;
        $tagLine = isset($data['tag_line']) ? trim((string)$data['tag_line']) : null;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = (bool)($data['is_active'] ?? true);
        $isFeatured = (bool)($data['is_featured'] ?? false);
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        $translations = isset($data['translations']) && is_array($data['translations']) ? $data['translations'] : [];

        $sql = sprintf(
            'INSERT INTO %s (group_id, slug, type, name, description, tag_line, features_json, sort_order, is_active, is_featured, metadata_json, translations_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->productsTable
        );

        $this->db->statement($sql, [
            $groupId,
            $slug,
            $type,
            $name,
            $description,
            $tagLine,
            json_encode($features, JSON_UNESCAPED_UNICODE),
            $sortOrder,
            $isActive ? 1 : 0,
            $isFeatured ? 1 : 0,
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
            json_encode($translations, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new Product(
            id: $id,
            groupId: $groupId,
            slug: $slug,
            type: $type,
            name: $name,
            description: $description,
            tagLine: $tagLine,
            features: $features,
            sortOrder: $sortOrder,
            isActive: $isActive,
            isFeatured: $isFeatured,
            metadata: $metadata,
            translations: $translations,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findProductById(int $id): ?Product
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->productsTable), [$id]);
        return $row ? $this->hydrateProduct($row) : null;
    }

    public function findProductBySlug(string $slug): ?Product
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE slug = ?', $this->productsTable), [$slug]);
        return $row ? $this->hydrateProduct($row) : null;
    }

    /**
     * @return array<Product>
     */
    public function listProductsByGroup(int $groupId, bool $onlyActive = false): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE group_id = ?', $this->productsTable);
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $rows = $this->db->select($sql, [$groupId]);
        return array_map([$this, 'hydrateProduct'], $rows);
    }

    /**
     * @return array<Product>
     */
    public function listAllProducts(bool $onlyActive = false): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->productsTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $rows = $this->db->select($sql);
        return array_map([$this, 'hydrateProduct'], $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProduct(int $id, array $data): Product
    {
        $product = $this->findProductById($id);
        if ($product === null) {
            throw new RuntimeException("Product with ID {$id} not found.");
        }

        $fields = [];
        $params = [];

        if (isset($data['group_id'])) {
            $groupId = (int)$data['group_id'];
            if ($this->findGroupById($groupId) === null) {
                throw new ValidationException(['group_id' => ['Invalid group ID.']], "Product group ID {$groupId} does not exist.");
            }
            $fields[] = 'group_id = ?';
            $params[] = $groupId;
        }

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                throw new ValidationException(['name' => ['Name cannot be empty.']], 'Product name cannot be empty.');
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }

        if (isset($data['slug'])) {
            $slug = $this->sanitizeSlug((string)$data['slug']);
            $existing = $this->findProductBySlug($slug);
            if ($existing !== null && $existing->getId() !== $id) {
                throw new ValidationException(['slug' => ['Slug already in use.']], "Product slug '{$slug}' already in use.");
            }
            $fields[] = 'slug = ?';
            $params[] = $slug;
        }

        if (isset($data['type'])) {
            $validTypes = [
                Product::TYPE_HOSTING,
                Product::TYPE_DOMAIN,
                Product::TYPE_SSL,
                Product::TYPE_SERVER,
                Product::TYPE_OTHER,
            ];
            $type = (string)$data['type'];
            if (!in_array($type, $validTypes, true)) {
                throw new ValidationException(['type' => ['Invalid type.']], "Invalid product type '{$type}'.");
            }
            $fields[] = 'type = ?';
            $params[] = $type;
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $data['description'] !== null ? trim((string)$data['description']) : null;
        }

        if (array_key_exists('tag_line', $data)) {
            $fields[] = 'tag_line = ?';
            $params[] = $data['tag_line'] !== null ? trim((string)$data['tag_line']) : null;
        }

        if (isset($data['features']) && is_array($data['features'])) {
            $fields[] = 'features_json = ?';
            $params[] = json_encode($data['features'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$data['sort_order'];
        }

        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }

        if (isset($data['is_featured'])) {
            $fields[] = 'is_featured = ?';
            $params[] = $data['is_featured'] ? 1 : 0;
        }

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $fields[] = 'metadata_json = ?';
            $params[] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['translations']) && is_array($data['translations'])) {
            $fields[] = 'translations_json = ?';
            $params[] = json_encode($data['translations'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($fields)) {
            $params[] = $id;
            $sql = sprintf('UPDATE %s SET %s WHERE id = ?', $this->productsTable, implode(', ', $fields));
            $this->db->statement($sql, $params);
        }

        return $this->findProductById($id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateGroup(array $row): ProductGroup
    {
        $translations = [];
        if (!empty($row['translations_json'])) {
            $decoded = json_decode((string)$row['translations_json'], true);
            if (is_array($decoded)) {
                $translations = $decoded;
            }
        }

        return new ProductGroup(
            id: (int)$row['id'],
            slug: (string)$row['slug'],
            name: (string)$row['name'],
            description: $row['description'] !== null ? (string)$row['description'] : null,
            sortOrder: (int)$row['sort_order'],
            isActive: (bool)$row['is_active'],
            translations: $translations,
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateProduct(array $row): Product
    {
        $features = [];
        if (!empty($row['features_json'])) {
            $decoded = json_decode((string)$row['features_json'], true);
            if (is_array($decoded)) {
                $features = $decoded;
            }
        }

        $metadata = [];
        if (!empty($row['metadata_json'])) {
            $decoded = json_decode((string)$row['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $translations = [];
        if (!empty($row['translations_json'])) {
            $decoded = json_decode((string)$row['translations_json'], true);
            if (is_array($decoded)) {
                $translations = $decoded;
            }
        }

        return new Product(
            id: (int)$row['id'],
            groupId: (int)$row['group_id'],
            slug: (string)$row['slug'],
            type: (string)$row['type'],
            name: (string)$row['name'],
            description: $row['description'] !== null ? (string)$row['description'] : null,
            tagLine: $row['tag_line'] !== null ? (string)$row['tag_line'] : null,
            features: $features,
            sortOrder: (int)$row['sort_order'],
            isActive: (bool)$row['is_active'],
            isFeatured: (bool)$row['is_featured'],
            metadata: $metadata,
            translations: $translations,
            createdAt: (string)$row['created_at']
        );
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        return trim((string)$slug, '-');
    }

    private function sanitizeSlug(string $slug): string
    {
        $sanitized = strtolower(trim($slug));
        $sanitized = preg_replace('/[^a-z0-9\-]+/i', '-', $sanitized);
        $clean = trim((string)$sanitized, '-');
        if ($clean === '') {
            throw new ValidationException('Invalid slug provided.', ['slug' => ['Slug is invalid.']]);
        }
        return $clean;
    }

    // ==========================================
    // Configurable Options & SubOptions Methods
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createConfigurableOption(array $data): ConfigurableOption
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Option name is required.']], 'Option name is required.');
        }

        $code = trim((string)($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateSlug($name);
        } else {
            $code = $this->sanitizeSlug($code);
        }

        $productId = isset($data['product_id']) ? (int)$data['product_id'] : null;
        $groupId = isset($data['group_id']) ? (int)$data['group_id'] : null;

        if ($productId === null && $groupId === null) {
            throw new ValidationException(['target' => ['Either product_id or group_id must be provided.']], 'Either product_id or group_id must be provided.');
        }

        $validTypes = [
            ConfigurableOption::TYPE_DROPDOWN,
            ConfigurableOption::TYPE_RADIO,
            ConfigurableOption::TYPE_CHECKBOX,
            ConfigurableOption::TYPE_QUANTITY,
        ];
        $type = (string)($data['type'] ?? ConfigurableOption::TYPE_DROPDOWN);
        if (!in_array($type, $validTypes, true)) {
            throw new ValidationException(['type' => ['Invalid option type.']], "Invalid option type '{$type}'.");
        }

        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isRequired = (bool)($data['is_required'] ?? false);
        $isActive = (bool)($data['is_active'] ?? true);
        $translations = isset($data['translations']) && is_array($data['translations']) ? $data['translations'] : [];

        $sql = sprintf(
            'INSERT INTO %s (product_id, group_id, name, code, type, sort_order, is_required, is_active, translations_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->optionsTable
        );

        $this->db->statement($sql, [
            $productId,
            $groupId,
            $name,
            $code,
            $type,
            $sortOrder,
            $isRequired ? 1 : 0,
            $isActive ? 1 : 0,
            json_encode($translations, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        // Handle sub-options if provided
        $subOptions = [];
        if (isset($data['sub_options']) && is_array($data['sub_options'])) {
            foreach ($data['sub_options'] as $subData) {
                if (is_array($subData)) {
                    $subData['option_id'] = $id;
                    $subOptions[] = $this->createConfigurableOptionSub($subData);
                }
            }
        }

        return new ConfigurableOption(
            id: $id,
            productId: $productId,
            groupId: $groupId,
            name: $name,
            code: $code,
            type: $type,
            sortOrder: $sortOrder,
            isRequired: $isRequired,
            isActive: $isActive,
            subOptions: $subOptions,
            translations: $translations,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createConfigurableOptionSub(array $data): ConfigurableOptionSub
    {
        $optionId = (int)($data['option_id'] ?? 0);
        if ($optionId <= 0) {
            throw new ValidationException(['option_id' => ['Valid option_id is required.']], 'Valid option_id is required.');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Sub-option name is required.']], 'Sub-option name is required.');
        }

        $code = trim((string)($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateSlug($name);
        } else {
            $code = $this->sanitizeSlug($code);
        }

        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = (bool)($data['is_active'] ?? true);
        $translations = isset($data['translations']) && is_array($data['translations']) ? $data['translations'] : [];

        $sql = sprintf(
            'INSERT INTO %s (option_id, name, code, sort_order, is_active, translations_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            $this->subOptionsTable
        );

        $this->db->statement($sql, [
            $optionId,
            $name,
            $code,
            $sortOrder,
            $isActive ? 1 : 0,
            json_encode($translations, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new ConfigurableOptionSub(
            id: $id,
            optionId: $optionId,
            name: $name,
            code: $code,
            sortOrder: $sortOrder,
            isActive: $isActive,
            translations: $translations,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * @return array<ConfigurableOption>
     */
    public function getOptionsForProduct(int $productId, ?int $groupId = null): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE is_active = 1 AND (product_id = ? OR group_id = ?) ORDER BY sort_order ASC, id ASC',
            $this->optionsTable
        );

        $rows = $this->db->select($sql, [$productId, $groupId ?? -1]);
        $options = [];

        foreach ($rows as $row) {
            $optionId = (int)$row['id'];
            $subsSql = sprintf('SELECT * FROM %s WHERE option_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC', $this->subOptionsTable);
            $subRows = $this->db->select($subsSql, [$optionId]);

            $subs = array_map(function ($sRow) {
                $translations = !empty($sRow['translations_json']) ? json_decode((string)$sRow['translations_json'], true) : [];
                return new ConfigurableOptionSub(
                    id: (int)$sRow['id'],
                    optionId: (int)$sRow['option_id'],
                    name: (string)$sRow['name'],
                    code: (string)$sRow['code'],
                    sortOrder: (int)$sRow['sort_order'],
                    isActive: (bool)$sRow['is_active'],
                    translations: is_array($translations) ? $translations : [],
                    createdAt: (string)$sRow['created_at']
                );
            }, $subRows);

            $translations = !empty($row['translations_json']) ? json_decode((string)$row['translations_json'], true) : [];

            $options[] = new ConfigurableOption(
                id: $optionId,
                productId: $row['product_id'] !== null ? (int)$row['product_id'] : null,
                groupId: $row['group_id'] !== null ? (int)$row['group_id'] : null,
                name: (string)$row['name'],
                code: (string)$row['code'],
                type: (string)$row['type'],
                sortOrder: (int)$row['sort_order'],
                isRequired: (bool)$row['is_required'],
                isActive: (bool)$row['is_active'],
                subOptions: $subs,
                translations: is_array($translations) ? $translations : [],
                createdAt: (string)$row['created_at']
            );
        }

        return $options;
    }

    // ==========================================
    // Product Addons Methods
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createProductAddon(array $data): ProductAddon
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => ['Addon name is required.']], 'Addon name is required.');
        }

        $code = trim((string)($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateSlug($name);
        } else {
            $code = $this->sanitizeSlug($code);
        }

        $existing = $this->db->selectOne(sprintf('SELECT id FROM %s WHERE code = ?', $this->addonsTable), [$code]);
        if ($existing !== null) {
            throw new ValidationException(['code' => ['Addon code already exists.']], "Addon code '{$code}' already exists.");
        }

        $description = isset($data['description']) ? trim((string)$data['description']) : null;
        $applicableProducts = isset($data['applicable_product_ids']) && is_array($data['applicable_product_ids']) ? $data['applicable_product_ids'] : [];
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = (bool)($data['is_active'] ?? true);
        $translations = isset($data['translations']) && is_array($data['translations']) ? $data['translations'] : [];

        $sql = sprintf(
            'INSERT INTO %s (name, code, description, applicable_products_json, sort_order, is_active, translations_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            $this->addonsTable
        );

        $this->db->statement($sql, [
            $name,
            $code,
            $description,
            json_encode($applicableProducts),
            $sortOrder,
            $isActive ? 1 : 0,
            json_encode($translations, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new ProductAddon(
            id: $id,
            name: $name,
            code: $code,
            description: $description,
            applicableProductIds: $applicableProducts,
            sortOrder: $sortOrder,
            isActive: $isActive,
            translations: $translations,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * @return array<ProductAddon>
     */
    public function getAddonsForProduct(int $productId): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE is_active = 1 ORDER BY sort_order ASC, id ASC', $this->addonsTable);
        $rows = $this->db->select($sql);

        $results = [];
        foreach ($rows as $row) {
            $applicable = !empty($row['applicable_products_json']) ? json_decode((string)$row['applicable_products_json'], true) : [];
            $applicableIds = is_array($applicable) ? array_map('intval', $applicable) : [];

            $translations = !empty($row['translations_json']) ? json_decode((string)$row['translations_json'], true) : [];

            $addon = new ProductAddon(
                id: (int)$row['id'],
                name: (string)$row['name'],
                code: (string)$row['code'],
                description: $row['description'] !== null ? (string)$row['description'] : null,
                applicableProductIds: $applicableIds,
                sortOrder: (int)$row['sort_order'],
                isActive: (bool)$row['is_active'],
                translations: is_array($translations) ? $translations : [],
                createdAt: (string)$row['created_at']
            );

            if ($addon->isApplicableTo($productId)) {
                $results[] = $addon;
            }
        }

        return $results;
    }

    // ==========================================
    // Product Availability & Stock Methods
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function setProductAvailability(int $productId, array $data): ProductAvailability
    {
        if ($this->findProductById($productId) === null) {
            throw new ValidationException(['product_id' => ['Product not found.']], "Product ID {$productId} not found.");
        }

        $status = (string)($data['status'] ?? ProductAvailability::STATUS_AVAILABLE);
        $stockTracking = (bool)($data['stock_tracking_enabled'] ?? false);
        $stockQuantity = (int)($data['stock_quantity'] ?? 0);
        $allowBackorders = (bool)($data['allow_backorders'] ?? false);
        $maxPerCustomer = isset($data['max_per_customer']) ? (int)$data['max_per_customer'] : null;
        $availableFrom = isset($data['available_from']) ? (string)$data['available_from'] : null;
        $availableUntil = isset($data['available_until']) ? (string)$data['available_until'] : null;

        $existing = $this->db->selectOne(sprintf('SELECT product_id FROM %s WHERE product_id = ?', $this->availabilityTable), [$productId]);

        if ($existing !== null) {
            $sql = sprintf(
                'UPDATE %s SET status = ?, stock_tracking_enabled = ?, stock_quantity = ?, allow_backorders = ?, max_per_customer = ?, available_from = ?, available_until = ?
                 WHERE product_id = ?',
                $this->availabilityTable
            );
            $this->db->statement($sql, [
                $status,
                $stockTracking ? 1 : 0,
                $stockQuantity,
                $allowBackorders ? 1 : 0,
                $maxPerCustomer,
                $availableFrom,
                $availableUntil,
                $productId,
            ]);
        } else {
            $sql = sprintf(
                'INSERT INTO %s (product_id, status, stock_tracking_enabled, stock_quantity, allow_backorders, max_per_customer, available_from, available_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $this->availabilityTable
            );
            $this->db->statement($sql, [
                $productId,
                $status,
                $stockTracking ? 1 : 0,
                $stockQuantity,
                $allowBackorders ? 1 : 0,
                $maxPerCustomer,
                $availableFrom,
                $availableUntil,
            ]);
        }

        return new ProductAvailability(
            productId: $productId,
            status: $status,
            stockTrackingEnabled: $stockTracking,
            stockQuantity: $stockQuantity,
            allowBackorders: $allowBackorders,
            maxPerCustomer: $maxPerCustomer,
            availableFrom: $availableFrom,
            availableUntil: $availableUntil
        );
    }

    public function getProductAvailability(int $productId): ProductAvailability
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE product_id = ?', $this->availabilityTable), [$productId]);
        if ($row === null) {
            // Default: unlimited available
            return new ProductAvailability(
                productId: $productId,
                status: ProductAvailability::STATUS_AVAILABLE,
                stockTrackingEnabled: false,
                stockQuantity: 0,
                allowBackorders: false
            );
        }

        return new ProductAvailability(
            productId: (int)$row['product_id'],
            status: (string)$row['status'],
            stockTrackingEnabled: (bool)$row['stock_tracking_enabled'],
            stockQuantity: (int)$row['stock_quantity'],
            allowBackorders: (bool)$row['allow_backorders'],
            maxPerCustomer: $row['max_per_customer'] !== null ? (int)$row['max_per_customer'] : null,
            availableFrom: $row['available_from'] !== null ? (string)$row['available_from'] : null,
            availableUntil: $row['available_until'] !== null ? (string)$row['available_until'] : null
        );
    }

    public function decrementStock(int $productId, int $quantity = 1): bool
    {
        $avail = $this->getProductAvailability($productId);
        if (!$avail->isStockTrackingEnabled()) {
            return true;
        }

        if ($avail->getStockQuantity() < $quantity && !$avail->allowsBackorders()) {
            return false;
        }

        $newQty = $avail->getStockQuantity() - $quantity;
        $this->db->statement(
            sprintf('UPDATE %s SET stock_quantity = ? WHERE product_id = ?', $this->availabilityTable),
            [$newQty, $productId]
        );

        return true;
    }
}

