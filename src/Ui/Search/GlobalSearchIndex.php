<?php

declare(strict_types=1);

namespace Coleza\Ui\Search;

final class GlobalSearchIndex
{
    /** @var array<int, array{id: string, title: string, category: string, url: string, keywords: array<int, string>, permission?: string}> */
    private array $items = [];

    /**
     * Register a searchable item or action into the command palette.
     *
     * @param array<int, string> $keywords
     */
    public function registerItem(
        string $id,
        string $title,
        string $category,
        string $url,
        array $keywords = [],
        ?string $permission = null
    ): self {
        $this->items[] = [
            'id' => $id,
            'title' => $title,
            'category' => $category,
            'url' => $url,
            'keywords' => array_map('strtolower', $keywords),
            'permission' => $permission,
        ];
        return $this;
    }

    /**
     * Search index matching query against title, category, and keywords.
     * Filters results based on user's authorized permissions.
     *
     * @param callable(string): bool|null $permissionChecker
     * @return array<int, array{id: string, title: string, category: string, url: string}>
     */
    public function search(string $query, ?callable $permissionChecker = null, int $limit = 10): array
    {
        $cleanQuery = strtolower(trim($query));
        if ($cleanQuery === '') {
            return [];
        }

        $results = [];

        foreach ($this->items as $item) {
            // Permission gate
            if (!empty($item['permission']) && $permissionChecker !== null) {
                if (!$permissionChecker($item['permission'])) {
                    continue;
                }
            }

            $matched = false;
            $titleLower = strtolower($item['title']);
            $categoryLower = strtolower($item['category']);

            if (str_contains($titleLower, $cleanQuery) || str_contains($categoryLower, $cleanQuery)) {
                $matched = true;
            } else {
                foreach ($item['keywords'] as $keyword) {
                    if (str_contains($keyword, $cleanQuery)) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched) {
                $results[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'category' => $item['category'],
                    'url' => $item['url'],
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Render the Command Palette (Ctrl+K) modal skeleton.
     */
    public function renderPaletteModal(): string
    {
        return '<div id="command-palette" class="co-command-palette-backdrop" role="dialog" aria-modal="true" aria-label="Command Palette" hidden>
            <div class="co-command-palette-dialog">
                <div class="co-command-search-box">
                    <span class="co-search-icon" aria-hidden="true">🔍</span>
                    <input id="command-palette-input" type="search" class="co-command-input" placeholder="Type a command or search..." autocomplete="off" aria-autocomplete="list" aria-controls="command-results"/>
                    <kbd class="co-kbd">ESC</kbd>
                </div>
                <div id="command-results" class="co-command-results" role="listbox" aria-label="Search Results">
                    <p class="co-empty-hint">Type to start searching navigation, actions, and settings.</p>
                </div>
                <div class="co-command-footer">
                    <span><kbd class="co-kbd">↑</kbd><kbd class="co-kbd">↓</kbd> to navigate</span>
                    <span><kbd class="co-kbd">↵</kbd> to select</span>
                    <span><kbd class="co-kbd">ESC</kbd> to close</span>
                </div>
            </div>
        </div>';
    }
}
