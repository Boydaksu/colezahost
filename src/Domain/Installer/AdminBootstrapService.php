<?php

declare(strict_types=1);

namespace Coleza\Domain\Installer;

use Coleza\Foundation\Database\Connection;

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
        $rbac = new \Coleza\Domain\Identity\Rbac\RbacService($db, $prefix);
        $tUsers = $prefix . 'users';

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

        $roleId = $rbac->findOrCreateRole('super_admin', 'system', 'Super Administrator');
        $rbac->grantPermission($roleId, '*');
        $rbac->assignRole($userId, 'super_admin');

        return [
            'id' => $userId,
            'email' => $dto->getEmail(),
            'first_name' => $dto->getFirstName(),
            'last_name' => $dto->getLastName(),
            'role' => 'super_admin',
        ];
    }
}
