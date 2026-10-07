<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Ui;

use Coleza\Ui\Client\ClientShell;
use PHPUnit\Framework\TestCase;

final class ClientShellTest extends TestCase
{
    public function testGuestClientShellRendersSignInButton(): void
    {
        $shell = new ClientShell(
            title: 'Welcome',
            brandName: 'Coleza Cloud'
        );

        $html = $shell->render('<div>Product Catalog</div>');

        $this->assertStringContainsString('Welcome - Coleza Cloud', $html);
        $this->assertStringContainsString('href="/login"', $html);
        $this->assertStringContainsString('>Sign In<', $html);
        $this->assertStringContainsString('Product Catalog', $html);
    }

    public function testAuthenticatedClientShellWithOrgSwitcher(): void
    {
        $nav = [
            ['label' => 'Services', 'url' => '/client/services', 'active' => true],
            ['label' => 'Invoices', 'url' => '/client/invoices'],
            ['label' => 'Support', 'url' => '/client/tickets'],
        ];

        $orgs = [
            ['id' => 1, 'name' => 'Acme Corp'],
            ['id' => 2, 'name' => 'Beta Labs'],
        ];

        $shell = new ClientShell(
            title: 'My Services',
            brandName: 'Coleza Host',
            primaryNav: $nav,
            currentUserName: 'Alican Boydak',
            activeOrganizationName: 'Beta Labs',
            organizations: $orgs
        );

        $html = $shell->render('<div>Your Active VPS</div>');

        // Nav active page check
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('href="/client/services"', $html);
        $this->assertStringContainsString('Alican Boydak', $html);
        $this->assertStringContainsString('href="/logout"', $html);

        // Org Switcher check
        $this->assertStringContainsString('id="org_select"', $html);
        $this->assertStringContainsString('value="1">Acme Corp<', $html);
        $this->assertStringContainsString('value="2" selected>Beta Labs<', $html);
    }
}
