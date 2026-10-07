<?php

declare(strict_types=1);

namespace Coleza\Ui\Patterns;

final class DataTable
{
    /**
     * @param array<int, array{key: string, label: string, sortable?: bool}> $columns
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $pagination
     */
    public function __construct(
        private array $columns,
        private array $rows,
        private array $pagination = [],
        private string $emptyMessage = 'No records found.'
    ) {
    }

    /**
     * Render accessible SSR DataTable.
     */
    public function render(): string
    {
        $headersHtml = '';
        foreach ($this->columns as $col) {
            $sortAttr = !empty($col['sortable']) ? ' aria-sort="none"' : '';
            $headersHtml .= sprintf(
                '<th scope="col" class="co-th"%s>%s</th>',
                $sortAttr,
                htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8')
            );
        }

        $rowsHtml = '';
        if (empty($this->rows)) {
            $colSpan = count($this->columns);
            $rowsHtml = sprintf(
                '<tr><td colspan="%d" class="co-td co-td-empty">%s</td></tr>',
                $colSpan,
                htmlspecialchars($this->emptyMessage, ENT_QUOTES, 'UTF-8')
            );
        } else {
            foreach ($this->rows as $row) {
                $cellsHtml = '';
                foreach ($this->columns as $col) {
                    $val = $row[$col['key']] ?? '';
                    $cellsHtml .= sprintf('<td class="co-td">%s</td>', htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8'));
                }
                $rowsHtml .= sprintf('<tr class="co-tr">%s</tr>', $cellsHtml);
            }
        }

        $paginationHtml = $this->renderPagination();

        return sprintf(
            '<div class="co-datatable-wrapper">
                <table class="co-datatable" role="grid">
                    <thead><tr class="co-tr">%s</tr></thead>
                    <tbody>%s</tbody>
                </table>
                %s
            </div>',
            $headersHtml,
            $rowsHtml,
            $paginationHtml
        );
    }

    private function renderPagination(): string
    {
        if (empty($this->pagination)) {
            return '';
        }

        $currentPage = (int) ($this->pagination['current_page'] ?? 1);
        $totalPages = (int) ($this->pagination['total_pages'] ?? 1);
        $totalItems = (int) ($this->pagination['total_items'] ?? 0);

        return sprintf(
            '<div class="co-pagination" aria-label="Table Pagination">
                <span class="co-pagination-info">Showing %d total items</span>
                <nav class="co-pagination-nav">
                    <span class="co-pagination-page">Page %d of %d</span>
                </nav>
            </div>',
            $totalItems,
            $currentPage,
            $totalPages
        );
    }
}
