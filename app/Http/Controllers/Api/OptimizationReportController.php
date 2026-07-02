<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildIncomeOptimizationProfile;
use App\Jobs\GenerateOptimizationReport;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationFinding;
use App\Models\OptimizationReport;
use App\Services\OptimizationProReviewExportService;
use App\Services\OptimizationReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * OptimizationReportController — Phase 12-04 (RPT-02/RPT-05 API surface).
 *
 * Exposes the optimization report to the client:
 *   GET  /api/v1/optimizer/report/{year}            — show (auto-init, dispatch if stale/empty)
 *   POST /api/v1/optimizer/report/{year}/regenerate — explicit regeneration request
 *   GET  /api/v1/optimizer/report/{year}/download   — stream PDF
 *   POST /api/v1/optimizer/report/finding/{finding}/pro-review-export — RPT-05 packet (download)
 *
 * SECURITY (T-12-04-01):
 *   - All actions require auth:sanctum (outer middleware group)
 *   - All queries use scopeForUser() for cross-user isolation
 *   - Explicit owner-check on every action (mirrors DurableFactsController pattern)
 *   - Pro-review export blocked while docs_missing is non-empty (T-12-04-02)
 */
class OptimizationReportController extends Controller
{
    public function __construct(
        private readonly OptimizationReportExportService $reportExporter,
        private readonly OptimizationProReviewExportService $proReviewExporter,
    ) {}

    /**
     * Show (or initialize) the optimization report for a given tax year.
     *
     * Auto-initializes the report via fetchOrInit() — callers always receive a
     * model instance (Pitfall 8 guard). If the report is stale or has no sections,
     * dispatches a background generation job and returns a "generating" status.
     *
     * First-visit self-healing (RPT-02 chain gap fix):
     *   When the user has no IncomeOptimizationProfile for the year, dispatching
     *   GenerateOptimizationReport directly produces an empty report (no findings).
     *   Instead, dispatch BuildIncomeOptimizationProfile which runs the full pipeline:
     *     BuildIncomeOptimizationProfile
     *       → OptimizationProfileBuilt event
     *         → RunRedFlagDetectors (findings)
     *         → NarrateOptimizationFindings (Claude narration)
     *         → SurfaceHighPriorityRedFlags (AIQuestion rows)
     *         → MarkOptimizationReportStale (flag flip)
     *         → DispatchReportGeneration (debounced, 30s delay)
     *           → GenerateOptimizationReport (ShouldBeUnique — coalesces bursts)
     *
     * Never returns null or 500 on first access (RPT-02).
     */
    public function show(Request $request, int $year): JsonResponse
    {
        $userId = $request->user()->id;

        // fetchOrInit() always returns a model — never null (Pitfall 8)
        $report = OptimizationReport::fetchOrInit($userId, $year);

        $isEmpty = empty($report->sections);
        $needsGeneration = $report->is_stale || $isEmpty;

        if ($needsGeneration) {
            $hasProfile = IncomeOptimizationProfile::forUser($userId)
                ->where('tax_year', $year)
                ->exists();

            if (! $hasProfile) {
                // No profile yet — kick the full pipeline so findings exist before report generation.
                // BuildIncomeOptimizationProfile fires OptimizationProfileBuilt which triggers
                // RunRedFlagDetectors, NarrateOptimizationFindings, and DispatchReportGeneration.
                BuildIncomeOptimizationProfile::dispatch($userId, $year)
                    ->delay(now()->addSeconds(5));

                Log::info('OptimizationReportController@show: no profile found — dispatched full pipeline', [
                    'user_id' => $userId,
                    'tax_year' => $year,
                ]);
            } else {
                // Profile exists but report is stale — findings are already in DB, just regen the report.
                GenerateOptimizationReport::dispatch($userId, $year)
                    ->delay(now()->addSeconds(5));

                Log::info('OptimizationReportController@show: profile exists — dispatched report regen', [
                    'user_id' => $userId,
                    'tax_year' => $year,
                    'is_stale' => $report->is_stale,
                    'is_empty' => $isEmpty,
                ]);
            }
        }

        return response()->json([
            'report' => [
                'id' => $report->id,
                'tax_year' => $report->tax_year,
                'is_stale' => $report->is_stale,
                'status' => $needsGeneration ? 'generating' : 'ready',
                'sections' => $report->sections ?? [],
                'executive_summary' => $report->executive_summary,
                'rebuilt_at' => $report->rebuilt_at?->toIso8601String(),
                'stale_since' => $report->stale_since?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Request explicit regeneration of the optimization report.
     *
     * Applies the same first-visit self-healing as show(): if no profile exists,
     * dispatches the full pipeline via BuildIncomeOptimizationProfile instead of
     * GenerateOptimizationReport directly. When a profile already exists, only
     * the report job is dispatched (ShouldBeUnique coalesces concurrent requests).
     */
    public function regenerate(Request $request, int $year): JsonResponse
    {
        $userId = $request->user()->id;

        // Ensure the report row exists (auto-init guards against null)
        OptimizationReport::fetchOrInit($userId, $year);

        $hasProfile = IncomeOptimizationProfile::forUser($userId)
            ->where('tax_year', $year)
            ->exists();

        if (! $hasProfile) {
            BuildIncomeOptimizationProfile::dispatch($userId, $year)
                ->delay(now()->addSeconds(2));

            Log::info('OptimizationReportController@regenerate: no profile — dispatched full pipeline', [
                'user_id' => $userId,
                'tax_year' => $year,
            ]);
        } else {
            GenerateOptimizationReport::dispatch($userId, $year)
                ->delay(now()->addSeconds(2));

            Log::info('OptimizationReportController@regenerate: queued report regen', [
                'user_id' => $userId,
                'tax_year' => $year,
            ]);
        }

        return response()->json([
            'message' => 'Report regeneration queued. Check back in a moment.',
            'tax_year' => $year,
            'status' => 'generating',
        ]);
    }

    /**
     * Download the optimization report as a PDF.
     *
     * Generates the PDF on demand via OptimizationReportExportService (mirrors
     * TaxController::download() pattern).
     */
    public function download(Request $request, int $year): BinaryFileResponse
    {
        $userId = $request->user()->id;

        $report = OptimizationReport::fetchOrInit($userId, $year);

        // Generate fresh PDF — always current, not cached on disk
        $pdfPath = $this->reportExporter->generatePdf($report);

        $downloadName = "SpendifiAI_OptimizationReport_{$year}.pdf";

        return response()->download(
            $pdfPath,
            $downloadName,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Generate and download the RPT-05 pro-review export packet for a single finding.
     *
     * Blocked with 422 when:
     *   - The finding has docs_missing entries (required documents not uploaded)
     *   - The finding's pro_export_ready flag is false
     *
     * Every packet PDF carries:
     *   - "prepared from user-asserted, unverified facts" disclaimer
     *   - "educational material, not tax advice" disclaimer
     *   - static legal_basis + citations (never Claude output)
     *   - static defensibility rating from config
     *
     * SECURITY (T-12-04-01): Owner-check prevents cross-user finding access.
     * SECURITY (T-12-04-02): Blocked gate + persistent disclaimer.
     */
    public function proReviewExport(Request $request, OptimizationFinding $finding): BinaryFileResponse|JsonResponse
    {
        // Owner-check (mirrors DurableFactsController pattern — T-12-04-01)
        if ($finding->user_id !== $request->user()->id) {
            abort(403, 'You are not authorized to export this finding.');
        }

        // RPT-05 gate: block if docs are missing or export not ready (T-12-04-02)
        try {
            $this->proReviewExporter->assertExportReady($finding);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'docs_missing' => $finding->docs_missing ?? [],
                'pro_export_ready' => $finding->pro_export_ready,
            ], 422);
        }

        $pdfPath = $this->proReviewExporter->generatePacket($finding);

        $downloadName = 'SpendifiAI_ProReview_'.
            str_replace('_', '-', $finding->finding_key).'_'.
            $finding->tax_year.'.pdf';

        return response()->download(
            $pdfPath,
            $downloadName,
            ['Content-Type' => 'application/pdf']
        );
    }
}
