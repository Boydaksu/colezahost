<?php

declare(strict_types=1);

use Coleza\Domain\Identity\Rbac\RbacSchema;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        (new RbacSchema($db))->upgrade();
    }

    public function down(Connection $db): void
    {
        throw new RuntimeException('Identity normalization cannot be reversed safely; restore a verified backup.');
    }
};
