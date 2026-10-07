<?php

declare(strict_types=1);

namespace Coleza\Domain\Documents\Rendering;

final class PurePhpPdfRenderer implements PdfRendererInterface
{
    public function getEngineName(): string
    {
        return 'pure_php_pdf_1.4';
    }

    /**
     * @param string $html
     * @param array<string, mixed> $options
     * @return string Valid PDF 1.4 binary content
     */
    public function render(string $html, array $options = []): string
    {
        $title = $options['title'] ?? 'Commercial Document';
        $author = $options['author'] ?? 'Coleza Host';
        $subject = $options['subject'] ?? 'Commercial Invoice/Document';

        // Extract readable text lines from HTML for the PDF content stream
        $textLines = $this->extractTextLinesFromHtml($html);

        // Build PDF content stream
        $contentStream = $this->buildContentStream($textLines, $options);
        $streamLen = strlen($contentStream);

        // Construct PDF objects
        $objects = [];

        // 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        // 2: Pages
        $objects[2] = "<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>";

        // 3: Page (A4: 595.28 x 841.89 points)
        $objects[3] = "<< /Type /Page /Parent 2 0 R /Resources 4 0 R /MediaBox [ 0 0 595.28 841.89 ] /Contents 5 0 R >>";

        // 4: Resources
        $objects[4] = "<< /Font << /F1 6 0 R /F2 7 0 R >> /ProcSet [ /PDF /Text ] >>";

        // 5: Contents
        $objects[5] = "<< /Length {$streamLen} >>\nstream\n" . $contentStream . "\nendstream";

        // 6: Font Regular (Helvetica)
        $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

        // 7: Font Bold (Helvetica-Bold)
        $objects[7] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // 8: Document Info
        $dateStr = date('YmdHis');
        $escapedTitle = $this->escapePdfString($title);
        $escapedAuthor = $this->escapePdfString($author);
        $escapedSubject = $this->escapePdfString($subject);
        $objects[8] = "<< /Producer (Coleza Host PDF Engine) /Title ({$escapedTitle}) /Author ({$escapedAuthor}) /Subject ({$escapedSubject}) /CreationDate (D:{$dateStr}+00'00') >>";

        // Assemble PDF output with xref table
        $output = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [0 => 0];

        foreach ($objects as $objId => $objContent) {
            $offsets[$objId] = strlen($output);
            $output .= "{$objId} 0 obj\n" . $objContent . "\nendobj\n";
        }

        $xrefStart = strlen($output);
        $count = count($objects) + 1;
        $output .= "xref\n0 {$count}\n";
        $output .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $offset = $offsets[$i];
            $output .= sprintf("%010d 00000 n \n", $offset);
        }

        $docId = md5($output . microtime());
        $output .= "trailer\n<< /Size {$count} /Root 1 0 R /Info 8 0 R /ID [ <{$docId}> <{$docId}> ] >>\n";
        $output .= "startxref\n{$xrefStart}\n%%EOF";

        return $output;
    }

    /**
     * @param string $html
     * @return array<string>
     */
    private function extractTextLinesFromHtml(string $html): array
    {
        // Strip scripts and styles
        $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html) ?? $html;
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $clean) ?? $clean;

        // Replace headers and table rows with newlines
        $clean = preg_replace('/<\/(h[1-6]|tr|div|p|li)>/i', "\n", $clean) ?? $clean;
        $clean = preg_replace('/<td[^>]*>/i', "  |  ", $clean) ?? $clean;
        $clean = strip_tags($clean);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $rawLines = explode("\n", $clean);
        $filtered = [];

        foreach ($rawLines as $line) {
            $trimmed = trim($line);
            // Clean repeated whitespace
            $trimmed = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
            if ($trimmed !== '') {
                $filtered[] = $trimmed;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string> $lines
     * @param array<string, mixed> $options
     * @return string
     */
    private function buildContentStream(array $lines, array $options): string
    {
        $ops = [];
        // Header background banner
        $ops[] = "q 0.06 0.09 0.16 rg 0 780 595.28 61.89 re f Q"; // dark navy top bar

        // Top Header text in White
        $ops[] = "BT /F2 16 Tf 1 1 1 rg 40 805 Td (" . $this->escapePdfString($options['title'] ?? 'COLEZA HOST DOCUMENT') . ") Tj ET";

        // Document body starting coordinate
        $y = 750;
        $leftMargin = 40;
        $lineHeight = 16;

        foreach ($lines as $line) {
            if ($y < 60) {
                // Keep within page boundaries
                break;
            }

            $isHeader = str_starts_with($line, 'FATURA') || str_starts_with($line, 'INVOICE') 
                || str_starts_with($line, 'SAYIN') || str_starts_with($line, 'BILLED')
                || str_starts_with($line, 'GENEL') || str_starts_with($line, 'GRAND');

            $font = $isHeader ? '/F2 11' : '/F1 10';
            $color = $isHeader ? "0.06 0.09 0.16 rg" : "0.2 0.2 0.2 rg";

            // If line contains table separator '|', format nicely
            $escaped = $this->escapePdfString($line);
            $ops[] = "BT {$font} Tf {$color} {$leftMargin} {$y} Td ({$escaped}) Tj ET";

            $y -= $lineHeight;
        }

        // Footer rule and metadata
        $ops[] = "q 0.8 0.8 0.8 RG 40 45 m 555 45 l S Q";
        $ops[] = "BT /F1 8 Tf 0.5 0.5 0.5 rg 40 32 Td (Generated by Coleza Host Platform - Certified Document Verification Engine) Tj ET";

        return implode("\n", $ops);
    }

    private function escapePdfString(string $text): string
    {
        // Transliterate or normalize to ISO-8859-1 for standard Type1 font compliance
        $transliterated = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        if (!is_string($transliterated) || $transliterated === '') {
            $transliterated = $text;
        }

        // Escape backslash and parentheses
        $escaped = str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $transliterated
        );

        return $escaped;
    }
}
