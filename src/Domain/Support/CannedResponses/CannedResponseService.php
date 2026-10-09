<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\CannedResponses;

use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class CannedResponseService
{
    private string $table = 'support_canned_responses';

    public function __construct(
        private readonly Connection $db,
        private readonly ?DepartmentService $departmentService = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                title VARCHAR(150) NOT NULL,
                content TEXT NOT NULL,
                shortcut VARCHAR(50) NULL,
                department_id INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );
        $this->db->statement($sql);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): CannedResponse
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(
                ['title' => 'Canned response title is required.'],
                'Invalid title'
            );
        }

        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            throw new ValidationException(
                ['content' => 'Canned response content template is required.'],
                'Invalid content'
            );
        }

        $shortcut = isset($data['shortcut']) ? trim((string) $data['shortcut']) : null;
        if ($shortcut !== null && $shortcut !== '') {
            if (!str_starts_with($shortcut, '/')) {
                $shortcut = '/' . $shortcut;
            }
        } else {
            $shortcut = null;
        }

        $departmentId = isset($data['department_id']) && $data['department_id'] !== null
            ? (int) $data['department_id']
            : null;

        if ($departmentId !== null && $this->departmentService !== null) {
            $this->departmentService->requireDepartment($departmentId);
        }

        $isActive = (bool) ($data['is_active'] ?? true);
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $insertedId = $this->db->insert(
            $this->table,
            [
                'title' => $title,
                'content' => $content,
                'shortcut' => $shortcut,
                'department_id' => $departmentId,
                'is_active' => $isActive ? 1 : 0,
                'metadata_json' => json_encode($metadata),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]
        );

        $id = (int) $insertedId;

        return new CannedResponse(
            id: $id,
            title: $title,
            content: $content,
            shortcut: $shortcut,
            departmentId: $departmentId,
            isActive: $isActive,
            metadata: $metadata,
            createdAt: $now,
            updatedAt: $now
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): CannedResponse
    {
        $current = $this->require($id);

        $updates = [];
        $title = $current->getTitle();
        $content = $current->getContent();
        $shortcut = $current->getShortcut();
        $departmentId = $current->getDepartmentId();
        $isActive = $current->isActive();
        $metadata = $current->getMetadata();

        if (array_key_exists('title', $data)) {
            $newTitle = trim((string) $data['title']);
            if ($newTitle === '') {
                throw new ValidationException(
                    ['title' => 'Canned response title cannot be empty.'],
                    'Invalid title'
                );
            }
            $title = $newTitle;
            $updates['title'] = $title;
        }

        if (array_key_exists('content', $data)) {
            $newContent = trim((string) $data['content']);
            if ($newContent === '') {
                throw new ValidationException(
                    ['content' => 'Canned response content cannot be empty.'],
                    'Invalid content'
                );
            }
            $content = $newContent;
            $updates['content'] = $content;
        }

        if (array_key_exists('shortcut', $data)) {
            $newShortcut = $data['shortcut'] !== null ? trim((string) $data['shortcut']) : null;
            if ($newShortcut !== null && $newShortcut !== '') {
                if (!str_starts_with($newShortcut, '/')) {
                    $newShortcut = '/' . $newShortcut;
                }
                $shortcut = $newShortcut;
            } else {
                $shortcut = null;
            }
            $updates['shortcut'] = $shortcut;
        }

        if (array_key_exists('department_id', $data)) {
            $departmentId = $data['department_id'] !== null ? (int) $data['department_id'] : null;
            if ($departmentId !== null && $this->departmentService !== null) {
                $this->departmentService->requireDepartment($departmentId);
            }
            $updates['department_id'] = $departmentId;
        }

        if (array_key_exists('is_active', $data)) {
            $isActive = (bool) $data['is_active'];
            $updates['is_active'] = $isActive ? 1 : 0;
        }

        if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
            $metadata = array_merge($metadata, $data['metadata']);
            $updates['metadata_json'] = json_encode($metadata);
        }

        $now = new DateTimeImmutable();
        $updates['updated_at'] = $now->format('Y-m-d H:i:s');

        $this->db->update($this->table, $updates, 'id = :where_id', ['where_id' => $id]);

        return new CannedResponse(
            id: $id,
            title: $title,
            content: $content,
            shortcut: $shortcut,
            departmentId: $departmentId,
            isActive: $isActive,
            metadata: $metadata,
            createdAt: $current->getCreatedAt(),
            updatedAt: $now
        );
    }

    public function delete(int $id): bool
    {
        $this->require($id);
        $affected = $this->db->delete($this->table, 'id = :where_id', ['where_id' => $id]);

        return $affected > 0;
    }

    public function get(int $id): ?CannedResponse
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->table),
            ['id' => $id]
        );

        return $row !== null ? CannedResponse::fromArray($row) : null;
    }

    public function require(int $id): CannedResponse
    {
        $resp = $this->get($id);
        if ($resp === null) {
            throw new ValidationException(
                ['canned_response_id' => "Canned response with ID {$id} not found."],
                'Canned response not found'
            );
        }

        return $resp;
    }

    public function findByShortcut(string $shortcut, ?int $departmentId = null): ?CannedResponse
    {
        $cleanShortcut = str_starts_with($shortcut, '/') ? $shortcut : '/' . $shortcut;

        if ($departmentId !== null) {
            $row = $this->db->selectOne(
                sprintf('SELECT * FROM %s WHERE shortcut = :sc AND (department_id = :dep_id OR department_id IS NULL) AND is_active = 1 ORDER BY department_id DESC LIMIT 1', $this->table),
                ['sc' => $cleanShortcut, 'dep_id' => $departmentId]
            );
        } else {
            $row = $this->db->selectOne(
                sprintf('SELECT * FROM %s WHERE shortcut = :sc AND is_active = 1 LIMIT 1', $this->table),
                ['sc' => $cleanShortcut]
            );
        }

        return $row !== null ? CannedResponse::fromArray($row) : null;
    }

    /**
     * @return array<int, CannedResponse>
     */
    public function listResponses(?int $departmentId = null, bool $onlyActive = true): array
    {
        $conditions = [];
        $bindings = [];

        if ($onlyActive) {
            $conditions[] = 'is_active = 1';
        }

        if ($departmentId !== null) {
            $conditions[] = '(department_id = :dep_id OR department_id IS NULL)';
            $bindings['dep_id'] = $departmentId;
        }

        $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = sprintf('SELECT * FROM %s %s ORDER BY title ASC', $this->table, $whereClause);

        $rows = $this->db->select($sql, $bindings);

        return array_map(fn (array $r) => CannedResponse::fromArray($r), $rows);
    }
}
