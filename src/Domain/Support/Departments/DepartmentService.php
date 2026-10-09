<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Departments;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;

final class DepartmentService
{
    private string $departmentsTable = 'support_departments';
    private string $departmentAgentsTable = 'support_department_agents';

    public function __construct(
        private readonly Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlDepartments = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                email VARCHAR(150) NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->departmentsTable,
            $autoInc
        );
        $this->db->statement($sqlDepartments);

        $sqlAgents = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                department_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT \'agent\',
                can_assign TINYINT(1) NOT NULL DEFAULT 1,
                receives_notifications TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (department_id, user_id)
            )',
            $this->departmentAgentsTable,
            $autoInc
        );
        $this->db->statement($sqlAgents);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDepartment(array $data): Department
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(
                ['name' => 'Department name is required and cannot be empty.'],
                'Department validation failed'
            );
        }

        $existing = $this->findByName($name);
        if ($existing !== null) {
            throw new ValidationException(
                ['name' => "Department with name '{$name}' already exists."],
                'Duplicate department name'
            );
        }

        $email = isset($data['email']) ? trim((string) $data['email']) : null;
        if ($email !== null && $email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException(
                    ['email' => "Invalid email address format: '{$email}'."],
                    'Invalid email address'
                );
            }
        } else {
            $email = null;
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $isPublic = isset($data['is_public']) ? (bool) $data['is_public'] : true;
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $insertedId = $this->db->insert(
            $this->departmentsTable,
            [
                'name' => $name,
                'description' => $description,
                'email' => $email,
                'is_public' => $isPublic ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'sort_order' => $sortOrder,
                'metadata_json' => json_encode($metadata),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]
        );

        $id = (int) $insertedId;

        return new Department(
            id: $id,
            name: $name,
            description: $description,
            email: $email,
            isPublic: $isPublic,
            isActive: $isActive,
            sortOrder: $sortOrder,
            metadata: $metadata,
            createdAt: $now,
            updatedAt: $now
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateDepartment(int $id, array $data): Department
    {
        $current = $this->requireDepartment($id);

        $updates = [];
        $name = $current->getName();
        $description = $current->getDescription();
        $email = $current->getEmail();
        $isPublic = $current->isPublic();
        $isActive = $current->isActive();
        $sortOrder = $current->getSortOrder();
        $metadata = $current->getMetadata();

        if (array_key_exists('name', $data)) {
            $newName = trim((string) $data['name']);
            if ($newName === '') {
                throw new ValidationException(
                    ['name' => 'Department name cannot be empty.'],
                    'Department validation failed'
                );
            }
            if ($newName !== $current->getName()) {
                $existing = $this->findByName($newName);
                if ($existing !== null && $existing->getId() !== $id) {
                    throw new ValidationException(
                        ['name' => "Department with name '{$newName}' already exists."],
                        'Duplicate department name'
                    );
                }
            }
            $name = $newName;
            $updates['name'] = $name;
        }

        if (array_key_exists('description', $data)) {
            $description = $data['description'] !== null ? trim((string) $data['description']) : null;
            $updates['description'] = $description;
        }

        if (array_key_exists('email', $data)) {
            $newEmail = $data['email'] !== null ? trim((string) $data['email']) : null;
            if ($newEmail !== null && $newEmail !== '') {
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationException(
                        ['email' => "Invalid email address format: '{$newEmail}'."],
                        'Invalid email address'
                    );
                }
                $email = $newEmail;
            } else {
                $email = null;
            }
            $updates['email'] = $email;
        }

        if (array_key_exists('is_public', $data)) {
            $isPublic = (bool) $data['is_public'];
            $updates['is_public'] = $isPublic ? 1 : 0;
        }

        if (array_key_exists('is_active', $data)) {
            $isActive = (bool) $data['is_active'];
            $updates['is_active'] = $isActive ? 1 : 0;
        }

        if (array_key_exists('sort_order', $data)) {
            $sortOrder = (int) $data['sort_order'];
            $updates['sort_order'] = $sortOrder;
        }

        if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
            $metadata = array_merge($metadata, $data['metadata']);
            $updates['metadata_json'] = json_encode($metadata);
        }

        $now = new DateTimeImmutable();
        $updates['updated_at'] = $now->format('Y-m-d H:i:s');

        $this->db->update($this->departmentsTable, $updates, 'id = :where_id', ['where_id' => $id]);

        return new Department(
            id: $id,
            name: $name,
            description: $description,
            email: $email,
            isPublic: $isPublic,
            isActive: $isActive,
            sortOrder: $sortOrder,
            metadata: $metadata,
            createdAt: $current->getCreatedAt(),
            updatedAt: $now
        );
    }

    public function deleteDepartment(int $id, bool $force = false): bool
    {
        $department = $this->requireDepartment($id);

        $agents = $this->getDepartmentAgents($id, onlyActive: false);
        if (count($agents) > 0 && !$force) {
            throw new ValidationException(
                ['department_id' => "Cannot delete department '{$department->getName()}' because it has assigned agents. Remove agents or use force deletion."],
                'Department has active dependencies'
            );
        }

        if ($force) {
            $this->db->delete($this->departmentAgentsTable, 'department_id = :dep_id', ['dep_id' => $id]);
        }

        $affected = $this->db->delete($this->departmentsTable, 'id = :dep_id', ['dep_id' => $id]);

        return $affected > 0;
    }

    public function getDepartment(int $id): ?Department
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->departmentsTable),
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        return Department::fromArray($row);
    }

    public function requireDepartment(int $id): Department
    {
        $dep = $this->getDepartment($id);
        if ($dep === null) {
            throw new ValidationException(
                ['department_id' => "Department with ID {$id} not found."],
                'Department not found'
            );
        }

        return $dep;
    }

    public function findByName(string $name): ?Department
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE name = :name', $this->departmentsTable),
            ['name' => trim($name)]
        );

        if ($row === null) {
            return null;
        }

        return Department::fromArray($row);
    }

    /**
     * @return array<int, Department>
     */
    public function listDepartments(bool $onlyActive = false, bool $onlyPublic = false): array
    {
        $conditions = [];

        if ($onlyActive) {
            $conditions[] = 'is_active = 1';
        }

        if ($onlyPublic) {
            $conditions[] = 'is_public = 1';
        }

        $whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = sprintf('SELECT * FROM %s %s ORDER BY sort_order ASC, name ASC', $this->departmentsTable, $whereClause);

        $rows = $this->db->select($sql);

        return array_map(fn (array $r) => Department::fromArray($r), $rows);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function assignAgent(
        int $departmentId,
        int $userId,
        string $role = 'agent',
        bool $canAssign = true,
        bool $receivesNotifications = true,
        array $metadata = []
    ): DepartmentAgent {
        $this->requireDepartment($departmentId);

        if ($userId <= 0) {
            throw new ValidationException(
                ['user_id' => 'Invalid user ID for agent assignment.'],
                'Invalid user ID'
            );
        }

        $existing = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE department_id = :dep_id AND user_id = :user_id', $this->departmentAgentsTable),
            ['dep_id' => $departmentId, 'user_id' => $userId]
        );

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        if ($existing !== null) {
            $this->db->update(
                $this->departmentAgentsTable,
                [
                    'role' => $role,
                    'can_assign' => $canAssign ? 1 : 0,
                    'receives_notifications' => $receivesNotifications ? 1 : 0,
                    'is_active' => 1,
                    'metadata_json' => json_encode($metadata),
                    'updated_at' => $nowStr,
                ],
                'id = :where_id',
                ['where_id' => (int) $existing['id']]
            );

            return new DepartmentAgent(
                id: (int) $existing['id'],
                departmentId: $departmentId,
                userId: $userId,
                role: $role,
                canAssign: $canAssign,
                receivesNotifications: $receivesNotifications,
                isActive: true,
                metadata: $metadata,
                createdAt: !empty($existing['created_at']) ? new DateTimeImmutable((string) $existing['created_at']) : $now,
                updatedAt: $now
            );
        }

        $insertedId = $this->db->insert(
            $this->departmentAgentsTable,
            [
                'department_id' => $departmentId,
                'user_id' => $userId,
                'role' => $role,
                'can_assign' => $canAssign ? 1 : 0,
                'receives_notifications' => $receivesNotifications ? 1 : 0,
                'is_active' => 1,
                'metadata_json' => json_encode($metadata),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]
        );

        $id = (int) $insertedId;

        return new DepartmentAgent(
            id: $id,
            departmentId: $departmentId,
            userId: $userId,
            role: $role,
            canAssign: $canAssign,
            receivesNotifications: $receivesNotifications,
            isActive: true,
            metadata: $metadata,
            createdAt: $now,
            updatedAt: $now
        );
    }

    public function removeAgent(int $departmentId, int $userId): bool
    {
        $affected = $this->db->delete(
            $this->departmentAgentsTable,
            'department_id = :dep_id AND user_id = :user_id',
            ['dep_id' => $departmentId, 'user_id' => $userId]
        );

        return $affected > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateAgentMembership(int $departmentId, int $userId, array $data): DepartmentAgent
    {
        $existing = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE department_id = :dep_id AND user_id = :user_id', $this->departmentAgentsTable),
            ['dep_id' => $departmentId, 'user_id' => $userId]
        );

        if ($existing === null) {
            throw new ValidationException(
                ['agent' => "Agent user {$userId} is not assigned to department {$departmentId}."],
                'Agent assignment not found'
            );
        }

        $updates = [];
        $role = (string) ($data['role'] ?? $existing['role']);
        $canAssign = isset($data['can_assign']) ? (bool) $data['can_assign'] : (bool) $existing['can_assign'];
        $receivesNotifications = isset($data['receives_notifications'])
            ? (bool) $data['receives_notifications']
            : (bool) $existing['receives_notifications'];
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : (bool) $existing['is_active'];

        $metadata = [];
        if (!empty($existing['metadata_json'])) {
            $metadata = json_decode((string) $existing['metadata_json'], true) ?: [];
        }
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $metadata = array_merge($metadata, $data['metadata']);
        }

        $now = new DateTimeImmutable();
        $updates['role'] = $role;
        $updates['can_assign'] = $canAssign ? 1 : 0;
        $updates['receives_notifications'] = $receivesNotifications ? 1 : 0;
        $updates['is_active'] = $isActive ? 1 : 0;
        $updates['metadata_json'] = json_encode($metadata);
        $updates['updated_at'] = $now->format('Y-m-d H:i:s');

        $this->db->update(
            $this->departmentAgentsTable,
            $updates,
            'id = :where_id',
            ['where_id' => (int) $existing['id']]
        );

        return new DepartmentAgent(
            id: (int) $existing['id'],
            departmentId: $departmentId,
            userId: $userId,
            role: $role,
            canAssign: $canAssign,
            receivesNotifications: $receivesNotifications,
            isActive: $isActive,
            metadata: $metadata,
            createdAt: !empty($existing['created_at']) ? new DateTimeImmutable((string) $existing['created_at']) : $now,
            updatedAt: $now
        );
    }

    /**
     * @return array<int, DepartmentAgent>
     */
    public function getDepartmentAgents(int $departmentId, bool $onlyActive = true): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE department_id = :dep_id %s ORDER BY role DESC, id ASC',
            $this->departmentAgentsTable,
            $onlyActive ? 'AND is_active = 1' : ''
        );

        $rows = $this->db->select($sql, ['dep_id' => $departmentId]);

        return array_map(fn (array $r) => DepartmentAgent::fromArray($r), $rows);
    }

    /**
     * @return array<int, DepartmentAgent>
     */
    public function getAgentDepartments(int $userId, bool $onlyActive = true): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE user_id = :user_id %s ORDER BY department_id ASC',
            $this->departmentAgentsTable,
            $onlyActive ? 'AND is_active = 1' : ''
        );

        $rows = $this->db->select($sql, ['user_id' => $userId]);

        return array_map(fn (array $r) => DepartmentAgent::fromArray($r), $rows);
    }

    public function isAgentInDepartment(int $departmentId, int $userId): bool
    {
        $row = $this->db->selectOne(
            sprintf(
                'SELECT id FROM %s WHERE department_id = :dep_id AND user_id = :user_id AND is_active = 1',
                $this->departmentAgentsTable
            ),
            ['dep_id' => $departmentId, 'user_id' => $userId]
        );

        return $row !== null;
    }

    /**
     * Retrieve active agents eligible for ticket assignment in a given department.
     *
     * @return array<int, DepartmentAgent>
     */
    public function getAssignableAgents(int $departmentId): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE department_id = :dep_id AND is_active = 1 AND can_assign = 1 ORDER BY id ASC',
            $this->departmentAgentsTable
        );

        $rows = $this->db->select($sql, ['dep_id' => $departmentId]);

        return array_map(fn (array $r) => DepartmentAgent::fromArray($r), $rows);
    }
}
