<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity\TwoFactor;
use Coleza\Domain\Identity\IdentityPersistence;
use Coleza\Foundation\Database\Connection;
final class TwoFactorLoginSchema
{
    private const TABLES = ['two_factor_login_steps' => ['user_id', 'last_used_step']];
    public static function schemaRequirements(): array { return self::TABLES; }
    public static function install(Connection $db, bool $installation = true): void
    {
        if (IdentityPersistence::installed($db, self::TABLES, $installation, primary: ['two_factor_login_steps' => 'user_id'], onlyPrimary: array_keys(self::TABLES))) { return; }
        $db->statement('CREATE TABLE IF NOT EXISTS two_factor_login_steps (user_id INT NOT NULL PRIMARY KEY, last_used_step BIGINT NOT NULL)' . ($db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }
    public static function prepare(Connection $db): void
    {
        if (IdentityPersistence::installed($db, self::TABLES, installation: false, primary: ['two_factor_login_steps' => 'user_id'], onlyPrimary: array_keys(self::TABLES))) { return; }
        self::install($db);
    }
}
