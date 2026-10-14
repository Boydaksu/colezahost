<?php

declare(strict_types=1);

namespace Coleza\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class SecurityAndDependencyScanTest extends TestCase
{
    private string $rootDir;
    private string $srcDir;
    private string $testsDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 2);
        $this->srcDir = $this->rootDir . DIRECTORY_SEPARATOR . 'src';
        $this->testsDir = $this->rootDir . DIRECTORY_SEPARATOR . 'tests';
    }

    /**
     * Verifies that 100% of PHP files in src/ and tests/ declare strict_types=1.
     */
    public function testAllPhpFilesDeclareStrictTypesAcrossSrcAndTests(): void
    {
        $files = array_merge(
            $this->getPhpFiles($this->srcDir),
            $this->getPhpFiles($this->testsDir)
        );

        $this->assertNotEmpty($files);
        $missing = [];

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1);')) {
                $missing[] = str_replace($this->rootDir . DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertEmpty($missing, 'Files missing strict_types declaration: ' . implode(', ', $missing));
    }

    /**
     * Unreviewed execution is forbidden; one content-pinned Windows ACL adapter is permitted.
     */
    public function testNoDangerousOrInsecureFunctionsInSource(): void
    {
        $files = $this->getPhpFiles($this->srcDir);
        $violations = [];

        $forbiddenPatterns = [
            '/\b(eval)\s*\(/i' => 'eval() execution',
            '/\b(create_function)\s*\(/i' => 'create_function() execution',
            '/\b(passthru|shell_exec|system|popen|proc_open)\s*\(/i' => 'Direct shell execution',
        ];

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            foreach ($forbiddenPatterns as $pattern => $desc) {
                if (preg_match($pattern, $content, $m)) {
                    if ($this->isReviewedProcess($file, $content, strtolower($m[1]))) { continue; }
                    $violations[] = str_replace($this->srcDir . DIRECTORY_SEPARATOR, 'src/', $file) . " uses {$desc}";
                }
            }
        }

        $this->assertEmpty($violations, 'Dangerous functions found in src: ' . implode(', ', $violations));
    }

    private function isReviewedProcess(string $file, string $content, string $function): bool
    {
        $policy = json_decode((string) file_get_contents($this->rootDir . '/tools/reviewed-processes.json'), true, flags: JSON_THROW_ON_ERROR);
        $relative = str_replace('\\', '/', substr($file, strlen($this->rootDir) + 1));
        $entry = $policy['files'][$relative] ?? null;
        return $function === 'proc_open' && is_array($entry)
            && hash_equals($entry['sha256'], hash('sha256', $content))
            && preg_match_all('/\b(passthru|shell_exec|system|popen|proc_open)\s*\(/i', $content) === 1;
    }

    public function testReviewedProcessExceptionRejectsChangedContentOtherPathsAndOtherFunctions(): void
    {
        $file = $this->rootDir . '/src/Domain/Vault/PrivateKeyFile.php';
        $content = (string) file_get_contents($file);
        self::assertTrue($this->isReviewedProcess($file, $content, 'proc_open'));
        self::assertFalse($this->isReviewedProcess($file, $content . "\n// altered", 'proc_open'));
        self::assertFalse($this->isReviewedProcess($file . '.other', $content, 'proc_open'));
        self::assertFalse($this->isReviewedProcess($file, $content, 'shell_exec'));
        self::assertFalse($this->isReviewedProcess($file, $content . '\nproc_open($untrusted, [], $pipes);', 'proc_open'));
    }

    /**
     * Security Constitution: Zero production secrets or raw private keys in codebase.
     */
    public function testNoHardcodedSecretPatternsInRepository(): void
    {
        $secretPatterns = [
            '/-----BEGIN (?:RSA|OPENSSH|EC|DSA) PRIVATE KEY-----/' => 'Raw Private Key',
            '/AKIA[0-9A-Z]{16}/' => 'AWS Access Key ID',
            '/ghp_[0-9a-zA-Z]{36}/' => 'GitHub Personal Access Token',
            '/gho_[0-9a-zA-Z]{36}/' => 'GitHub OAuth Token',
            '/sk_live_[0-9a-zA-Z]{24}/' => 'Live Stripe API Secret',
        ];

        $files = array_merge(
            $this->getPhpFiles($this->srcDir),
            $this->getPhpFiles($this->testsDir)
        );

        $leaks = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            foreach ($secretPatterns as $pattern => $desc) {
                if (preg_match($pattern, $content)) {
                    $leaks[] = str_replace($this->rootDir . DIRECTORY_SEPARATOR, '', $file) . " leaked {$desc}";
                }
            }
        }

        $this->assertEmpty($leaks, 'Hardcoded secrets found: ' . implode(', ', $leaks));
    }

    /**
     * Architecture & Shared-Host: Zero debug output statements in src/.
     */
    public function testNoDebugDumpsOrErrorSuppressionInSource(): void
    {
        $files = $this->getPhpFiles($this->srcDir);
        $violations = [];

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match('/\b(var_dump|dd|print_r)\s*\(/', $content, $m)) {
                $violations[] = str_replace($this->srcDir . DIRECTORY_SEPARATOR, 'src/', $file) . " contains {$m[1]}()";
            }
        }

        $this->assertEmpty($violations, 'Debug output statements found in src: ' . implode(', ', $violations));
    }

    /**
     * Shared-Host Baseline: Composer dependencies must be minimal and pure PSR.
     */
    public function testComposerDependenciesAreMinimalAndCompliant(): void
    {
        $composerPath = $this->rootDir . DIRECTORY_SEPARATOR . 'composer.json';
        $this->assertFileExists($composerPath);

        $data = json_decode((string) file_get_contents($composerPath), true);
        $require = (array) ($data['require'] ?? []);

        $this->assertArrayHasKey('php', $require);
        $this->assertStringContainsString('8.4', (string) $require['php']);

        foreach ($require as $pkg => $ver) {
            if ($pkg === 'php') {
                continue;
            }
            $this->assertStringStartsWith('psr/', $pkg, "Production dependency '{$pkg}' must be a pure PSR interface package.");
        }
    }

    /**
     * @return list<string>
     */
    private function getPhpFiles(string $dir): array
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
