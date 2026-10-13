<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Identity\IdentityPersistence::installed($db, array_merge(
            \Coleza\Domain\Identity\Session\SessionRevocationSchema::schemaRequirements(),
            \Coleza\Domain\Identity\TwoFactor\TwoFactorLoginSchema::schemaRequirements()
        ), primary: ['session_revocations' => 'session_id', 'two_factor_login_steps' => 'user_id'], onlyPrimary: ['session_lifecycle_lock', 'session_revocations', 'two_factor_login_steps']);
        \Coleza\Domain\Identity\Session\SessionRevocationSchema::install($db);
        \Coleza\Domain\Identity\TwoFactor\TwoFactorLoginSchema::install($db);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new \RuntimeException('Identity lifecycle rollback requires an explicit maintenance plan with backup; revoked IDs must not be reactivated.');
    }
};
