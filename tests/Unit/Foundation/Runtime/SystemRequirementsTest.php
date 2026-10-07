<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Runtime;

use Coleza\Foundation\Runtime\SystemRequirements;
use PHPUnit\Framework\TestCase;

final class SystemRequirementsTest extends TestCase
{
    public function testCheckPassesOnCurrentPhpRuntime(): void
    {
        $sysReq = new SystemRequirements();
        $errors = $sysReq->check();

        // On our PHP 8.4 runtime with standard extensions, requirements should be satisfied
        $this->assertEmpty($errors, 'Requirements check should have zero errors: ' . implode(', ', $errors));
        $this->assertTrue($sysReq->isSatisfied());
    }

    public function testConstantDefinitions(): void
    {
        $this->assertSame('8.4.0', SystemRequirements::MIN_PHP_VERSION);
        $this->assertContains('pdo', SystemRequirements::REQUIRED_EXTENSIONS);
        $this->assertContains('pdo_mysql', SystemRequirements::REQUIRED_EXTENSIONS);
        $this->assertContains('mbstring', SystemRequirements::REQUIRED_EXTENSIONS);
    }
}
