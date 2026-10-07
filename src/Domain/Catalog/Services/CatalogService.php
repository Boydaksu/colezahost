<?php

declare(strict_types=1);

namespace Coleza\Domain\Catalog\Services;

use Coleza\Domain\Catalog\Entities\Product;
use Coleza\Domain\Catalog\Entities\ProductGroup;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class CatalogService
{
    private string $groupsTable = 'product_groups';
    private string $productsTable = 'products';

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
}
