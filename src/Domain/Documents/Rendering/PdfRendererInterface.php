<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

interface PdfRendererInterface
{
    /**
     * Renders HTML content or document view model into a raw PDF binary string.
     *
     * @param string $html HTML content to render
     * @param array<string, mixed> $options Rendering configuration options
     * @return string Raw binary PDF content
     */
    public function render(string $html, array $options = []): string;

    /**
     * Returns the engine identification name.
     */
    public function getEngineName(): string;
}
