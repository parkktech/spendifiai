<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

class PdfSplitterService
{
    /**
     * Extract a range of pages from a PDF into a new PDF file.
     *
     * @param  string  $inputPath  Absolute path to the source PDF
     * @param  int  $startPage  First page (1-based)
     * @param  int  $endPage  Last page (1-based, inclusive)
     * @return string Absolute path to the temporary output PDF
     */
    public function extractPages(string $inputPath, int $startPage, int $endPage): string
    {
        $pdf = new Fpdi;

        $pageCount = $pdf->setSourceFile($inputPath);

        // Clamp to actual page count
        $startPage = max(1, $startPage);
        $endPage = min($pageCount, $endPage);

        for ($page = $startPage; $page <= $endPage; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
        }

        $outputPath = sys_get_temp_dir().'/tax_split_'.uniqid().'.pdf';
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Split a PDF into multiple files based on page ranges.
     *
     * @param  string  $inputPath  Absolute path to the source PDF
     * @param  array<array{start: int, end: int}>  $ranges  Page ranges (1-based, inclusive)
     * @return array<string> Array of absolute paths to temporary output PDFs
     */
    public function splitByRanges(string $inputPath, array $ranges): array
    {
        $outputs = [];

        foreach ($ranges as $range) {
            $outputs[] = $this->extractPages($inputPath, $range['start'], $range['end']);
        }

        return $outputs;
    }

    /**
     * Get the total page count of a PDF.
     */
    public function getPageCount(string $inputPath): int
    {
        $pdf = new Fpdi;

        return $pdf->setSourceFile($inputPath);
    }
}
