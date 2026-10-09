<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * Bootstraps the root Super Administrator account and security credentials.
 */
final class AdminBootstrapService
{
    /**
     * @return array{id: int, email: string, first_name: string, last_name: string, role: string}
     */
    public function bootstrapAdmin(Connection $db, AdminSetupDto $dto, string $prefix = ''): array
    {
        $tUsers = $prefix . 'users';
        $tRoles = $prefix . 'roles';
        $tUserRoles = $prefix . 'user_roles';

        // Check if user already exists
        $existing = $db->selectOne(sprintf('SELECT id FROM %s WHERE email = ?', $tUsers), [$dto->getEmail()]);
        $passwordHash = password_hash($dto->getPassword(), PASSWORD_BCRYPT, ['cost' => 12]);

        if ($existing !== null) {
            $userId = (int) $existing['id'];
            $db->statement(
                sprintf('UPDATE %s SET password_hash = ?, first_name = ?, last_name = ?, status = "active", email_verified_at = CURRENT_TIMESTAMP WHERE id = ?', $tUsers),
                [$passwordHash, $dto->getFirstName(), $dto->getLastName(), $userId]
            );
        } else {
            $db->statement(
                sprintf(
                    'INSERT INTO %s (email, password_hash, first_name, last_name, status, email_verified_at) VALUES (?, ?, ?, ?, "active", CURRENT_TIMESTAMP)',
                    $tUsers
                ),
                [$dto->getEmail(), $passwordHash, $dto->getFirstName(), $dto->getLastName()]
            );
            $userId = (int) $db->getPdo()->lastInsertId();
        }

        // Ensure super_admin role exists
        $role = $db->selectOne(sprintf('SELECT id FROM %s WHERE name = "super_admin"', $tRoles));
        if ($role === null) {
            $db->statement(
                sprintf('INSERT INTO %s (name, display_name, permissions_json, is_system) VALUES ("super_admin", "Super Administrator", ?, 1)', $tRoles),
                [json_encode(['*'])]
            );
            $roleId = (int) $db->getPdo()->lastInsertId();
        } else {
            $roleId = (int) $role['id'];
        }

        // Map super_admin role to user
        $hasRole = $db->selectOne(sprintf('SELECT 1 FROM %s WHERE user_id = ? AND role_id = ?', $tUserRoles), [$userId, $roleId]);
        if ($hasRole === null) {
            $db->statement(sprintf('INSERT INTO %s (user_id, role_id) VALUES (?, ?)', $tUserRoles), [$userId, $roleId]);
        }

        return [
            'id' => $userId,
            'email' => $dto->getEmail(),
            'first_name' => $dto->getFirstName(),
            'last_name' => $dto->getLastName(),
            'role' => 'super_admin',
        ];
    }
}
