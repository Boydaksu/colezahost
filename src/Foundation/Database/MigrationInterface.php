<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

interface MigrationInterface
{
    /**
     * Run the migrations.
     */
    public function up(Connection $db): void;

    /**
     * Reverse the migrations.
     */
    public function down(Connection $db): void;
}
