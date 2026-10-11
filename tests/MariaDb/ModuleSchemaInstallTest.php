<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class ModuleSchemaInstallTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\ModuleSchemaInstallCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
}
