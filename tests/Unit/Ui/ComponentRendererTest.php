<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Ui;

use Coleza\Ui\Components\ComponentRenderer;
use PHPUnit\Framework\TestCase;

final class ComponentRendererTest extends TestCase
{
    public function testButtonRendering(): void
    {
        $html = ComponentRenderer::button('Save Changes', 'primary', ['id' => 'btn_save', 'type' => 'submit']);
        $this->assertStringContainsString('class="co-btn co-btn-primary"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('id="btn_save"', $html);
        $this->assertStringContainsString('>Save Changes<', $html);
    }

    public function testBadgeRenderingWithNonColorIndicators(): void
    {
        $successBadge = ComponentRenderer::badge('Active', 'success');
        $this->assertStringContainsString('role="status"', $successBadge);
        $this->assertStringContainsString('class="co-badge co-badge-success"', $successBadge);
        $this->assertStringContainsString('✓', $successBadge); // Icon indicator per UI Constitution

        $dangerBadge = ComponentRenderer::badge('Terminated', 'danger');
        $this->assertStringContainsString('✕', $dangerBadge);
    }

    public function testInputWithAriaErrorBinding(): void
    {
        // Valid input without error
        $validHtml = ComponentRenderer::input('email', 'Email Address', 'test@coleza.com');
        $this->assertStringContainsString('for="input_email"', $validHtml);
        $this->assertStringNotContainsString('aria-invalid="true"', $validHtml);

        // Invalid input with error message
        $invalidHtml = ComponentRenderer::input('email', 'Email Address', '', 'Email is required.');
        $this->assertStringContainsString('aria-invalid="true"', $invalidHtml);
        $this->assertStringContainsString('aria-describedby="input_email_error"', $invalidHtml);
        $this->assertStringContainsString('id="input_email_error"', $invalidHtml);
        $this->assertStringContainsString('role="alert"', $invalidHtml);
        $this->assertStringContainsString('Email is required.', $invalidHtml);
    }

    public function testAlertRendering(): void
    {
        $alertHtml = ComponentRenderer::alert('Service suspended due to overdue invoice.', 'danger');
        $this->assertStringContainsString('role="alert"', $alertHtml);
        $this->assertStringContainsString('class="co-alert co-alert-danger"', $alertHtml);
        $this->assertStringContainsString('Service suspended due to overdue invoice.', $alertHtml);
    }

    public function testTokensCssExistsAndContainsVariables(): void
    {
        $cssPath = dirname(__DIR__, 3) . '/src/Ui/tokens.css';
        $this->assertFileExists($cssPath);
        $content = (string) file_get_contents($cssPath);
        $this->assertStringContainsString('--co-color-primary-500:', $content);
        $this->assertStringContainsString('[data-theme="dark"]', $content);
        $this->assertStringContainsString('[data-density="compact"]', $content);
    }
}
