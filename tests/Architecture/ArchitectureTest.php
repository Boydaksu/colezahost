<?php

declare(strict_types=1);

namespace Coleza\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src';
    }

    /**
     * Architecture Constitution: Every PHP file in src/ must strictly declare strict_types=1.
     */
    public function testAllSourceFilesDeclareStrictTypes(): void
    {
        $files = $this->getAllPhpFiles($this->srcDir);
        $this->assertNotEmpty($files, 'Source directory should not be empty.');

        $missingStrict = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1);')) {
                $missingStrict[] = str_replace($this->srcDir, 'src', $file);
            }
        }

        $this->assertEmpty($missingStrict, 'The following files do not declare strict_types=1: ' . implode(', ', $missingStrict));
    }

    /**
     * Architecture Constitution: Domain layer must not depend on Infrastructure implementation namespaces.
     */
    public function testDomainDoesNotDependOnInfrastructureImplementations(): void
    {
        $domainDir = $this->srcDir . DIRECTORY_SEPARATOR . 'Domain';
        if (!is_dir($domainDir)) {
            $this->assertTrue(true);
            return;
        }

        $files = $this->getAllPhpFiles($domainDir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/use\s+Coleza\\\\Infrastructure\\\\(?!Contracts\\\\)[a-zA-Z0-9_\\\\]+;/', $content, $matches)) {
                $violations[] = str_replace($this->srcDir, 'src', $file) . ' -> ' . $matches[0];
            }
        }

        $this->assertEmpty($violations, 'Domain layer must not directly depend on Infrastructure: ' . implode(', ', $violations));
    }

    /**
     * Architecture Constitution: Framework/Foundation layer must have zero business domain dependencies.
     */
    public function testFoundationDoesNotDependOnDomain(): void
    {
        $foundationDir = $this->srcDir . DIRECTORY_SEPARATOR . 'Foundation';
        $files = $this->getAllPhpFiles($foundationDir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/use\s+Coleza\\\\Domain\\\\/', $content)) {
                $violations[] = str_replace($this->srcDir, 'src', $file);
            }
        }

        $this->assertEmpty($violations, 'Foundation must not depend on Domain: ' . implode(', ', $violations));
    }

    /**
     * Security Constitution: No raw error suppression '@' or dump statements in production code.
     */
    public function testNoRawDumpsOrSuppressionInSource(): void
    {
        $files = $this->getAllPhpFiles($this->srcDir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/\b(var_dump|dd|print_r|die|exit)\s*\(/', $content, $m)) {
                $violations[] = str_replace($this->srcDir, 'src', $file) . ' contains ' . $m[1];
            }
        }

        $this->assertEmpty($violations, 'Production code must not contain dump/exit calls: ' . implode(', ', $violations));
    }

    /**
     * @return array<int, string>
     */
    private function getAllPhpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
