<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Ui;

use Coleza\Ui\Patterns\DataTable;
use Coleza\Ui\Patterns\Drawer;
use Coleza\Ui\Patterns\Modal;
use PHPUnit\Framework\TestCase;

final class WorkspacePatternsTest extends TestCase
{
    public function testDataTableRenderingWithRowsAndPagination(): void
    {
        $columns = [
            ['key' => 'id', 'label' => 'Invoice #', 'sortable' => true],
            ['key' => 'client', 'label' => 'Customer'],
            ['key' => 'amount', 'label' => 'Total'],
        ];

        $rows = [
            ['id' => 'INV-1001', 'client' => 'John Doe', 'amount' => '$50.00'],
            ['id' => 'INV-1002', 'client' => 'Acme Corp', 'amount' => '$120.00'],
        ];

        $pagination = [
            'current_page' => 1,
            'total_pages' => 5,
            'total_items' => 50,
        ];

        $table = new DataTable($columns, $rows, $pagination);
        $html = $table->render();

        $this->assertStringContainsString('role="grid"', $html);
        $this->assertStringContainsString('aria-sort="none"', $html);
        $this->assertStringContainsString('INV-1001', $html);
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('Showing 50 total items', $html);
        $this->assertStringContainsString('Page 1 of 5', $html);
    }

    public function testDataTableEmptyState(): void
    {
        $columns = [
            ['key' => 'name', 'label' => 'Server Name'],
        ];

        $table = new DataTable($columns, [], emptyMessage: 'No servers deployed yet.');
        $html = $table->render();

        $this->assertStringContainsString('No servers deployed yet.', $html);
        $this->assertStringContainsString('colspan="1"', $html);
    }

    public function testModalRenderingWithTypedConfirmation(): void
    {
        $modalHtml = Modal::render(
            id: 'modal_delete_server',
            title: 'Terminate Server',
            bodyHtml: '<p>This action is irreversible.</p>',
            confirmLabel: 'Delete Server',
            confirmVariant: 'danger',
            typedConfirmationText: 'DELETE-SRV-99'
        );

        $this->assertStringContainsString('role="dialog"', $modalHtml);
        $this->assertStringContainsString('aria-modal="true"', $modalHtml);
        $this->assertStringContainsString('aria-labelledby="modal_delete_server_title"', $modalHtml);
        $this->assertStringContainsString('Terminate Server', $modalHtml);
        $this->assertStringContainsString('class="co-btn co-btn-danger"', $modalHtml);
        $this->assertStringContainsString('data-expected="DELETE-SRV-99"', $modalHtml);
    }

    public function testDrawerRendering(): void
    {
        $drawerHtml = Drawer::render(
            id: 'drawer_server_metrics',
            title: 'Server CPU & Memory Details',
            bodyHtml: '<div>Graph data</div>',
            position: 'right'
        );

        $this->assertStringContainsString('role="region"', $drawerHtml);
        $this->assertStringContainsString('aria-label="Server CPU &amp; Memory Details"', $drawerHtml);
        $this->assertStringContainsString('co-drawer-right', $drawerHtml);
        $this->assertStringContainsString('Graph data', $drawerHtml);
    }
}
