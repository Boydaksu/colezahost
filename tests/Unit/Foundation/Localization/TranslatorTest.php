<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Foundation\Localization;

use Coleza\Foundation\Localization\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private string $langPath;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->langPath = dirname(__DIR__, 4) . '/config/lang';
        $this->translator = new Translator($this->langPath, 'tr_TR');
    }

    public function testDefaultAndFallbackTranslations(): void
    {
        $trHello = $this->translator->get('auth.welcome_user', ['name' => 'Alican']);
        $this->assertSame('Hoş geldiniz, Alican!', $trHello);

        $enHello = $this->translator->get('auth.welcome_user', ['name' => 'John'], 'en_US');
        $this->assertSame('Welcome, John!', $enHello);

        // Unknown key returns key string
        $this->assertSame('unknown.key', $this->translator->get('unknown.key'));
    }

    public function testHierarchicalLocaleResolution(): void
    {
        // User locale takes highest precedence
        $resolved = $this->translator->resolveLocale('de_DE', 'fr_FR', 'en_US');
        $this->assertSame('de_DE', $resolved);

        // Org locale next
        $resolvedOrg = $this->translator->resolveLocale(null, 'fr_FR', 'en_US');
        $this->assertSame('fr_FR', $resolvedOrg);

        // Brand locale next
        $resolvedBrand = $this->translator->resolveLocale(null, null, 'en_US');
        $this->assertSame('en_US', $resolvedBrand);

        // Fallback to system default
        $resolvedDefault = $this->translator->resolveLocale(null, null, null);
        $this->assertSame('tr_TR', $resolvedDefault);
    }

    public function testTurkishAndEnglishKeyAndPlaceholderParity(): void
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
    }
}
