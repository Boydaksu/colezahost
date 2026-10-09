<?php

declare(strict_types=1);

namespace Tests\Integration\LocalizationAndAccessibility;

use Coleza\Domain\Documents\Rendering\DocumentLocalization;
use Coleza\Foundation\Localization\Translator;
use Coleza\Ui\Accessibility\AccessibilityValidator;
use Coleza\Ui\Admin\AdminShell;
use Coleza\Ui\Client\ClientShell;
use Coleza\Ui\Components\ComponentRenderer;
use Coleza\Ui\Patterns\DataTable;
use Coleza\Ui\Patterns\Drawer;
use Coleza\Ui\Patterns\Modal;
use PHPUnit\Framework\TestCase;

/**
 * P18.8 TR/EN Completeness + Accessibility / Visual Regression Suite:
 * 1. LOC-01: Full TR/EN translation key & placeholder parity
 * 2. LOC-02: Document & transactional localization completeness (money, date, terms)
 * 3. A11Y-01: Design token WCAG AA contrast audit (Light & Dark modes)
 * 4. A11Y-02: Core components & patterns semantic structure & ARIA compliance
 * 5. A11Y-03: Admin & Client shell layouts accessibility certification
 */
final class LocalizationCompletenessAndAccessibilitySuiteTest extends TestCase
{
    private string $langPath;
    private Translator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->langPath = dirname(__DIR__, 3) . '/config/lang';
        $this->translator = new Translator($this->langPath, 'tr_TR');
    }

    /**
     * LOC-01: 100% TR/EN translation parity across all core domains.
     */
    public function testTurkishAndEnglishFullTranslationParity(): void
    {
        $parity = $this->translator->verifyParity('tr_TR', 'en_US');

        $this->assertEmpty(
            $parity['missing_in_secondary'],
            'Keys missing in en_US: ' . implode(', ', $parity['missing_in_secondary'])
        );

        $this->assertEmpty(
            $parity['missing_in_primary'],
            'Keys missing in tr_TR: ' . implode(', ', $parity['missing_in_primary'])
        );

        $this->assertEmpty(
            $parity['mismatched_placeholders'],
            'Placeholders mismatch between tr_TR and en_US: ' . json_encode($parity['mismatched_placeholders'])
        );

        // Verify key domain prefixes are populated
        $expectedDomains = [
            'auth.',
            'two_factor.',
            'org.',
            'impersonation.',
            'nav.',
            'common.',
            'billing.',
            'services.',
            'domains.',
            'tickets.',
            'status.',
            'validation.',
        ];

        $enData = require $this->langPath . '/en_US.php';
        $keys = array_keys($enData);

        foreach ($expectedDomains as $prefix) {
            $matching = array_filter($keys, fn(string $k) => str_starts_with($k, $prefix));
            $this->assertNotEmpty($matching, "Domain prefix [{$prefix}] must have registered translation keys.");
        }
    }

    /**
     * LOC-02: Document localization completeness, currency and date formatting.
     */
    public function testDocumentLocalizationAndFormattingCompleteness(): void
    {
        // Core financial terms in Turkish and English
        $this->assertSame('Invoice', DocumentLocalization::trans('invoice', 'en'));
        $this->assertSame('Fatura', DocumentLocalization::trans('invoice', 'tr'));

        $this->assertSame('PAID', DocumentLocalization::trans('status_paid', 'en'));
        $this->assertSame('ÖDENDİ', DocumentLocalization::trans('status_paid', 'tr'));

        $this->assertSame('Tax No / VAT', DocumentLocalization::trans('tax_number', 'en'));
        $this->assertSame('Vergi No / TCKN', DocumentLocalization::trans('tax_number', 'tr'));

        // Currency formatting
        $trMoney = DocumentLocalization::formatMoney(1250.50, 'TL', 'tr');
        $this->assertSame('1.250,50 TL', $trMoney);

        $enMoney = DocumentLocalization::formatMoney(1250.50, '$', 'en');
        $this->assertSame('$1,250.50', $enMoney);

        // Date formatting
        $trDate = DocumentLocalization::formatDate('2026-10-09', 'tr');
        $this->assertSame('09.10.2026', $trDate);

        $enDate = DocumentLocalization::formatDate('2026-10-09', 'en');
        $this->assertSame('2026-10-09', $enDate);
    }

    /**
     * A11Y-01: Design token WCAG AA color contrast ratio audit (Light & Dark modes).
     */
    public function testDesignTokenColorContrastRatioAudit(): void
    {
        // Light Mode Tokens:
        // Main Text (#111827) on Surface (#ffffff) -> WCAG AA >= 4.5:1
        $lightSurfaceContrast = AccessibilityValidator::contrastRatio('#111827', '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $lightSurfaceContrast);
        $this->assertGreaterThanOrEqual(15.0, $lightSurfaceContrast);

        // Main Text (#111827) on App Background (#f9fafb) -> WCAG AA >= 4.5:1
        $lightAppContrast = AccessibilityValidator::contrastRatio('#111827', '#f9fafb');
        $this->assertGreaterThanOrEqual(4.5, $lightAppContrast);

        // Muted Text (#6b7280) on Surface (#ffffff) -> WCAG AA >= 4.5:1
        $lightMutedContrast = AccessibilityValidator::contrastRatio('#6b7280', '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $lightMutedContrast);

        // Primary Button (#4f46e5) with White Text (#ffffff) -> WCAG AA >= 4.5:1
        $primaryBtnContrast = AccessibilityValidator::contrastRatio('#4f46e5', '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $primaryBtnContrast);

        // Danger Button (#dc2626) with White Text (#ffffff) -> WCAG AA >= 4.5:1
        $dangerBtnContrast = AccessibilityValidator::contrastRatio('#dc2626', '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $dangerBtnContrast);

        // Dark Mode Tokens:
        // Dark Main Text (#f8fafc) on Dark Surface (#1e293b) -> WCAG AA >= 4.5:1
        $darkSurfaceContrast = AccessibilityValidator::contrastRatio('#f8fafc', '#1e293b');
        $this->assertGreaterThanOrEqual(4.5, $darkSurfaceContrast);
        $this->assertGreaterThanOrEqual(12.0, $darkSurfaceContrast);

        // Dark Main Text (#f8fafc) on Dark App Background (#0f172a) -> WCAG AA >= 4.5:1
        $darkAppContrast = AccessibilityValidator::contrastRatio('#f8fafc', '#0f172a');
        $this->assertGreaterThanOrEqual(4.5, $darkAppContrast);

        // Dark Muted Text (#94a3b8) on Dark Surface (#1e293b) -> WCAG AA >= 4.5:1
        $darkMutedContrast = AccessibilityValidator::contrastRatio('#94a3b8', '#1e293b');
        $this->assertGreaterThanOrEqual(4.5, $darkMutedContrast);
    }

    /**
     * A11Y-02: Core UI Components and Patterns Semantic Structure & ARIA compliance.
     */
    public function testComponentAndPatternAccessibilityCompliance(): void
    {
        // 1. Button variant checks
        $primaryBtn = ComponentRenderer::button('Save Changes', 'primary');
        $this->assertEmpty(AccessibilityValidator::validate($primaryBtn));

        $dangerBtn = ComponentRenderer::button('Delete Service', 'danger');
        $this->assertEmpty(AccessibilityValidator::validate($dangerBtn));

        // 2. Form input with label & error state
        $inputValid = ComponentRenderer::input('user_email', 'Email Address', 'user@colezahost.test');
        $this->assertEmpty(AccessibilityValidator::validate($inputValid));

        $inputError = ComponentRenderer::input('user_password', 'Password', '', 'Password is required.');
        $this->assertEmpty(AccessibilityValidator::validate($inputError));
        $this->assertStringContainsString('aria-invalid="true"', $inputError);
        $this->assertStringContainsString('role="alert"', $inputError);

        // 3. Status badges (non-color dependent symbols)
        $badgeSuccess = ComponentRenderer::badge('Active', 'success');
        $this->assertStringContainsString('role="status"', $badgeSuccess);
        $this->assertStringContainsString('aria-hidden="true"', $badgeSuccess);

        // 4. Alerts
        $alertWarning = ComponentRenderer::alert('Account renewal is pending payment.', 'warning');
        $this->assertStringContainsString('role="alert"', $alertWarning);

        // 5. Modal dialog
        $modalHtml = Modal::render('confirm_modal', 'Confirm Cancellation', '<p>Are you sure?</p>');
        $this->assertEmpty(AccessibilityValidator::validate($modalHtml));
        $this->assertStringContainsString('role="dialog"', $modalHtml);
        $this->assertStringContainsString('aria-labelledby=', $modalHtml);

        // 6. Drawer pattern
        $drawerHtml = Drawer::render('detail_drawer', 'Service Details', '<div>Detailed info</div>');
        $this->assertStringContainsString('role="region"', $drawerHtml);
        $this->assertStringContainsString('aria-label="Service Details"', $drawerHtml);

        // 7. DataTable pattern
        $table = new DataTable(
            columns: [
                ['key' => 'id', 'label' => 'ID', 'sortable' => true],
                ['key' => 'name', 'label' => 'Service Name', 'sortable' => true],
                ['key' => 'status', 'label' => 'Status'],
            ],
            rows: [
                ['id' => 1, 'name' => 'cPanel Cloud', 'status' => 'Active'],
                ['id' => 2, 'name' => 'VPS Pro', 'status' => 'Pending'],
            ],
            pagination: ['page' => 1, 'total_pages' => 1]
        );

        $tableHtml = $table->render();
        $this->assertStringContainsString('<th scope="col"', $tableHtml);
        $this->assertStringContainsString('role="grid"', $tableHtml);
        $this->assertStringContainsString('aria-sort="none"', $tableHtml);
    }

    /**
     * A11Y-03: Admin Shell and Client Shell layout accessibility verification.
     */
    public function testAdminAndClientShellLayoutAccessibility(): void
    {
        $admin = new AdminShell();
        $adminHtml = $admin->render('<main id="main"><h1>Admin Overview</h1></main>');
        $this->assertEmpty(AccessibilityValidator::validate($adminHtml));

        $client = new ClientShell();
        $clientHtml = $client->render('<main id="main"><h1>Client Portal</h1></main>');
        $this->assertEmpty(AccessibilityValidator::validate($clientHtml));
    }
}
