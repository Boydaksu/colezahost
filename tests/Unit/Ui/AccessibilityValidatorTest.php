<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Ui;

use Coleza\Ui\Accessibility\AccessibilityValidator;
use Coleza\Ui\Admin\AdminShell;
use Coleza\Ui\Client\ClientShell;
use Coleza\Ui\Components\ComponentRenderer;
use Coleza\Ui\Patterns\Modal;
use PHPUnit\Framework\TestCase;

final class AccessibilityValidatorTest extends TestCase
{
    public function testCoreComponentsPassAccessibilityAudit(): void
    {
        // 1. Button test
        $btnHtml = ComponentRenderer::button('Submit Invoice', 'primary');
        $this->assertEmpty(AccessibilityValidator::validate($btnHtml));

        // 2. Input with label test
        $inputHtml = ComponentRenderer::input('user_email', 'Your Email Address');
        $this->assertEmpty(AccessibilityValidator::validate($inputHtml));

        // 3. Modal test
        $modalHtml = Modal::render('test_dialog', 'Confirm Action', '<p>Details</p>');
        $this->assertEmpty(AccessibilityValidator::validate($modalHtml));
    }

    public function testDetectsMissingLabelViolations(): void
    {
        $badInput = '<input type="text" name="secret_code" id="secret_code" />';
        $violations = AccessibilityValidator::validate($badInput);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('lacks an accessible label', $violations[0]);
    }

    public function testDetectsEmptyButtonWithoutAriaLabel(): void
    {
        $badButton = '<button type="button" class="icon-only"></button>';
        $violations = AccessibilityValidator::validate($badButton);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('has no readable text content and lacks aria-label', $violations[0]);
    }

    public function testAdminAndClientShellsAccessibilityValidation(): void
    {
        $admin = new AdminShell();
        $adminHtml = $admin->render('<h1>Dashboard</h1>');
        $this->assertEmpty(AccessibilityValidator::validate($adminHtml));

        $client = new ClientShell();
        $clientHtml = $client->render('<p>Welcome</p>');
        $this->assertEmpty(AccessibilityValidator::validate($clientHtml));
    }

    public function testColorContrastRatioCalculation(): void
    {
        // Black on White: ~21:1 (Max contrast)
        $ratio = AccessibilityValidator::contrastRatio('#000000', '#ffffff');
        $this->assertSame(21.0, $ratio);

        // Core Brand Dark Gray on White: Meets WCAG AA (>= 4.5:1)
        $brandTextRatio = AccessibilityValidator::contrastRatio('#111827', '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $brandTextRatio);

        // Low contrast combination fails standard
        $lowRatio = AccessibilityValidator::contrastRatio('#999999', '#ffffff');
        $this->assertLessThan(4.5, $lowRatio);
    }
}
