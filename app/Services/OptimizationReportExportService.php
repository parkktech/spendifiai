<?php

namespace App\Services;

use App\Models\OptimizationReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * OptimizationReportExportService — RPT-04.
 *
 * Generates a PDF version of the optimization report via dompdf.
 * Mirrors the TaxExportService PDF pattern exactly.
 *
 * Security:
 * - Report data is flattened before passing to Blade (no heavy nested loops).
 * - ini_set memory guard (512M) before loadView (Pitfall 5).
 * - Inline CSS only in Blade (dompdf cannot fetch external resources in queued jobs).
 *
 * Dollar amounts are NOT passed to the Blade view directly — the sections array
 * contains finding prose and IDs only. Numbers traceable to findings stay in the
 * OptimizationFinding model, not in the PDF template.
 */
class OptimizationReportExportService
{
    /**
     * Generate a PDF for the given OptimizationReport.
     *
     * Returns the absolute path to the generated PDF file.
     *
     * @throws \RuntimeException If PDF generation fails.
     */
    public function generatePdf(OptimizationReport $report): string
    {
        // Memory guard for dompdf on long reports (Pitfall 5)
        ini_set('memory_limit', '512M');

        $user = $report->user;
        $timestamp = now()->format('Y-m-d_His');
        $dir = "optimization-reports/{$report->user_id}";

        Storage::disk('local')->makeDirectory($dir);

        $outputPath = Storage::disk('local')->path("{$dir}/OptimizationReport_{$report->tax_year}_{$timestamp}.pdf");

        // Flatten sections array before passing to Blade — no nested collection
        // iteration in the template reduces memory pressure and dompdf complexity.
        $sections = $this->flattenSections($report->sections ?? []);

        $pdf = Pdf::loadView('pdf.optimization-report', [
            'user_name' => $user?->name ?? 'User',
            'user_email' => $user?->email ?? '',
            'tax_year' => $report->tax_year,
            'generated_at' => now()->format('F j, Y'),
            'executive_summary' => $report->executive_summary,
            'sections' => $sections,
            // RPT-03: document-level educational disclaimer
            'report_disclaimer' => config('optimization-report.report_disclaimer',
                'This report is educational material only and does not constitute tax, legal, or financial advice. Always consult a qualified tax professional before making any tax-related decisions.'),
            'section_disclaimer' => config('optimization-report.section_disclaimer',
                'This section presents educational information only. Consult a tax professional before taking action.'),
        ]);

        $pdf->setPaper('letter', 'portrait');
        $pdf->save($outputPath);

        if (! file_exists($outputPath)) {
            throw new \RuntimeException('OptimizationReport PDF generation failed: file not found at '.$outputPath);
        }

        return $outputPath;
    }

    /**
     * Flatten sections array for safe Blade iteration.
     *
     * Separates sections by type so Blade can render them in the correct order
     * without nested loops over large data structures.
     *
     * @param  array<int, array<string, mixed>>  $rawSections
     * @return array{
     *   topical: array,
     *   docs_missing: array|null,
     *   needs_professional_review: array|null,
     *   what_we_refused: array|null,
     *   year_end: array|null,
     *   glossary: array|null,
     * }
     */
    protected function flattenSections(array $rawSections): array
    {
        $result = [
            'topical' => [],
            'docs_missing' => null,
            'needs_professional_review' => null,
            'what_we_refused' => null,
            'year_end' => null,
            'glossary' => null,
        ];

        foreach ($rawSections as $section) {
            $key = $section['section_key'] ?? '';
            $type = $section['section_type'] ?? '';

            if ($type === 'topical') {
                $result['topical'][] = $section;
            } elseif ($key === 'documents_missing') {
                $result['docs_missing'] = $section;
            } elseif ($key === 'needs_professional_review') {
                $result['needs_professional_review'] = $section;
            } elseif ($key === 'what_we_refused') {
                $result['what_we_refused'] = $section;
            } elseif ($type === 'year_end') {
                $result['year_end'] = $section;
            } elseif ($type === 'glossary') {
                $result['glossary'] = $section;
            }
        }

        return $result;
    }
}
