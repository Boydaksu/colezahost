<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Ui;

use Coleza\Ui\Search\GlobalSearchIndex;
use PHPUnit\Framework\TestCase;

final class GlobalSearchIndexTest extends TestCase
{
    private GlobalSearchIndex $index;

    protected function setUp(): void
    {
        $this->index = new GlobalSearchIndex();
        $this->index->registerItem('nav_invoices', 'Manage Invoices', 'Billing', '/admin/invoices', ['fatura', 'payment', 'receipt'], 'invoices.view');
        $this->index->registerItem('nav_servers', 'Server Clusters', 'Infrastructure', '/admin/servers', ['cpanel', 'node', 'vps'], 'servers.view');
        $this->index->registerItem('action_brand', 'Brand Identity Settings', 'Settings', '/admin/settings/brand', ['logo', 'theme', 'color']);
    }

    public function testSearchByTitleAndCategory(): void
    {
        $results = $this->index->search('invoices');
        $this->assertCount(1, $results);
        $this->assertSame('Manage Invoices', $results[0]['title']);

        $categoryResults = $this->index->search('infrastructure');
        $this->assertCount(1, $categoryResults);
        $this->assertSame('Server Clusters', $categoryResults[0]['title']);
    }

    public function testSearchByKeywords(): void
    {
        $results = $this->index->search('fatura');
        $this->assertCount(1, $results);
        $this->assertSame('Manage Invoices', $results[0]['title']);

        $vpsResults = $this->index->search('vps');
        $this->assertCount(1, $vpsResults);
        $this->assertSame('Server Clusters', $vpsResults[0]['title']);
    }

    public function testSearchEnforcesPermissionGating(): void
    {
        // User has invoices.view permission but NOT servers.view
        $checker = fn (string $perm) => $perm === 'invoices.view';

        $serverResults = $this->index->search('server', $checker);
        $this->assertEmpty($serverResults);

        $invoiceResults = $this->index->search('invoices', $checker);
        $this->assertCount(1, $invoiceResults);
    }

    public function testPaletteModalMarkup(): void
    {
        $html = $this->index->renderPaletteModal();

        $this->assertStringContainsString('id="command-palette"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('id="command-palette-input"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('<kbd class="co-kbd">ESC</kbd>', $html);
    }
}
