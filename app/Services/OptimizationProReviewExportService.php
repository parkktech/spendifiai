<?php

namespace App\Services;

use App\Models\OptimizationFinding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OptimizationProReviewExportService — RPT-05.
 *
 * Generates a professional review packet PDF for a single OptimizationFinding.
 * Mirrors TaxExportService dompdf pattern exactly.
 *
 * Packet contents (all static config or user-asserted data — never Claude output):
 *   1. Fact-pattern narrative (from finding.description)
 *   2. Timestamped user_assertions[] log
 *   3. docs_captured document references
 *   4. STATIC legal_basis + citations from finding (written by TaxRulesEngineService)
 *   5. STATIC defensibility rating from config (band → rating map)
 *   6. The professional question (from finding.details or constructed from finding_type)
 *
 * SECURITY (T-12-04-02):
 *   - Blocked (throws exception) when docs_missing is non-empty OR pro_export_ready=false
 *   - Every PDF carries the persistent educational disclaimer + unverified-facts notice
 *   - legal_basis/citations are from static config/DB fields — never Claude output
 *   - user_assertions are $hidden on the model; decrypted only within this service
 *
 * SAFE-03: This service does NOT read or write estimated_value_cents.
 */
class OptimizationProReviewExportService
{
    /**
     * Generate the professional review packet PDF for a finding.
     *
     * @return string Absolute path to the generated PDF file.
     *
     * @throws \InvalidArgumentException When the finding is not export-ready (docs_missing, pro_export_ready=false).
     * @throws \RuntimeException When PDF generation fails.
     */
    public function generatePacket(OptimizationFinding $finding): string
    {
        // BLOCK export when any docs are missing or pro_export_ready is false (RPT-05 gate)
        $this->assertExportReady($finding);

        ini_set('memory_limit', '512M');

        $user = $finding->user;
        $timestamp = now()->format('Y-m-d_His');
        $dir = "optimization-pro-review/{$finding->user_id}";

        Storage::disk('local')->makeDirectory($dir);

        $outputPath = Storage::disk('local')->path(
            "{$dir}/ProReview_{$finding->finding_key}_{$timestamp}.pdf"
        );

        $data = $this->buildPacketData($finding);

        $pdf = Pdf::loadView('pdf.optimization-pro-review', $data);
        $pdf->setPaper('letter', 'portrait');
        $pdf->save($outputPath);

        if (! file_exists($outputPath)) {
            throw new RuntimeException(
                'Pro-review packet PDF generation failed: file not found at '.$outputPath
            );
        }

        Log::info('OptimizationProReviewExportService: packet generated', [
            'user_id' => $finding->user_id,
            'finding_key' => $finding->finding_key,
            'path' => $outputPath,
        ]);

        return $outputPath;
    }

    /**
     * Assert the finding is ready for pro-review export.
     *
     * Conditions that block export (422 — T-12-04-02 mitigation):
     *   - docs_missing is a non-empty array (required documents not yet uploaded)
     *   - pro_export_ready is explicitly false (review gate not cleared)
     *
     * @throws \InvalidArgumentException
     */
    public function assertExportReady(OptimizationFinding $finding): void
    {
        $docsMissing = $finding->docs_missing ?? [];

        if (! empty($docsMissing)) {
            throw new \InvalidArgumentException(
                'Pro-review export blocked: required documents are missing — '.
                implode(', ', $docsMissing)
            );
        }

        if ($finding->pro_export_ready === false) {
            throw new \InvalidArgumentException(
                'Pro-review export blocked: finding is not marked pro_export_ready.'
            );
        }
    }

    /**
     * Build the view data array for the Blade template.
     *
     * All monetary columns (estimated_value_cents, net_cash_cost, etc.) are
     * deliberately absent from this payload (SAFE-03).
     */
    protected function buildPacketData(OptimizationFinding $finding): array
    {
        $band = $finding->band ?? 'conditional';
        $defensibilityRating = $this->resolveDefensibilityRating($band);
        $defensibilityDescription = $this->getDefensibilityDescription($defensibilityRating);

        $userAssertions = $this->decryptUserAssertions($finding);
        $professionalQuestion = $this->buildProfessionalQuestion($finding);

        $disclaimer = config(
            'optimization-report.pro_review_disclaimer',
            'EDUCATIONAL MATERIAL ONLY — NOT TAX ADVICE. This packet was prepared from information self-asserted by the user and has not been independently verified.'
        );

        return [
            'user_name' => $finding->user?->name ?? 'User',
            'user_email' => $finding->user?->email ?? '',
            'tax_year' => $finding->tax_year,
            'generated_at' => now()->format('F j, Y'),
            'finding_type_label' => $this->humanizeFindingType($finding->finding_type),
            'severity' => $finding->severity ?? 'medium',
            'band' => $band,
            'description' => $finding->description ?? 'No description available for this finding.',
            'user_assertions' => $userAssertions,
            'docs_captured' => $finding->docs_captured ?? [],
            'legal_basis' => $finding->legal_basis,
            'assumptions' => $finding->assumptions ?? [],
            'defensibility_rating' => $defensibilityRating,
            'defensibility_description' => $defensibilityDescription,
            'professional_question' => $professionalQuestion,
            'pro_review_disclaimer' => $disclaimer,
        ];
    }

    /**
     * Resolve the static defensibility rating from the finding's band.
     *
     * Source: config/optimization-report.php defensibility_ratings map.
     * Never computed from user data — always a static config lookup.
     */
    protected function resolveDefensibilityRating(string $band): string
    {
        $map = config('optimization-report.defensibility_ratings', []);

        return $map[$band] ?? 'fact-dependent';
    }

    /**
     * Get the human-readable description for a defensibility rating.
     */
    protected function getDefensibilityDescription(string $rating): string
    {
        return match ($rating) {
            'solid' => 'This area is generally well-established in the tax code. The underlying treatment has broad acceptance and a strong track record when properly documented.',
            'fact-dependent' => 'The correct treatment depends significantly on the specific facts of your situation. Small differences in facts can lead to materially different outcomes. Professional evaluation of your specific circumstances is important.',
            'frequently-abused' => 'This area is subject to frequent IRS scrutiny and has been identified as an area where improper claims are common. Arrangements in this category require careful professional review to ensure they reflect genuine economic substance and comply with applicable rules.',
            default => 'The defensibility of this item depends on professional evaluation of your specific facts.',
        };
    }

    /**
     * Decrypt and format user_assertions for the PDF template.
     *
     * user_assertions is encrypted on the model; we decrypt only here for the
     * PDF generation path (T-12-04-02: facts leave system in educational packet only).
     */
    protected function decryptUserAssertions(OptimizationFinding $finding): array
    {
        try {
            // Access the encrypted field — Laravel's 'encrypted' cast handles decryption
            $raw = $finding->user_assertions;

            if (empty($raw)) {
                return [];
            }

            // If it's a JSON string (when stored as plain string), decode it
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            return is_array($raw) ? $raw : [];
        } catch (\Throwable $e) {
            Log::warning('OptimizationProReviewExportService: could not decrypt user_assertions', [
                'finding_id' => $finding->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the question for the professional from the finding's details or type.
     */
    protected function buildProfessionalQuestion(OptimizationFinding $finding): string
    {
        // Check for an explicit question in details
        $details = $finding->details ?? [];
        if (! empty($details['professional_question'])) {
            return $details['professional_question'];
        }

        // Construct a type-based default question
        return $this->defaultQuestionForType($finding->finding_type ?? '');
    }

    /**
     * Return a default professional question based on finding type.
     */
    protected function defaultQuestionForType(string $findingType): string
    {
        return match (true) {
            str_contains($findingType, 'retirement') => 'Based on the retirement-related information in this packet, are there contribution strategies, plan types, or timing considerations I should evaluate for my situation?',
            str_contains($findingType, 'deduction') || str_contains($findingType, 'saas') || str_contains($findingType, 'business') => 'Based on the deduction-related information in this packet, what documentation or substantiation would I need to properly claim these items?',
            str_contains($findingType, 'withholding') || str_contains($findingType, 'estimated') => 'Based on the information in this packet, what steps should I take to ensure I am not under-withholding or subject to underpayment penalties?',
            str_contains($findingType, 'filing') || str_contains($findingType, 'entity') => 'Based on the filing-related information in this packet, are there structural or filing choices that I should be aware of for my situation?',
            str_contains($findingType, 'hsa') || str_contains($findingType, 'benefit') => 'Based on the benefit-related information in this packet, am I taking full advantage of tax-advantaged accounts available to me?',
            default => 'Given the information in this packet, what are the key tax considerations I should discuss with you for my specific situation?',
        };
    }

    /**
     * Convert a finding_type snake_case key to a human-readable label.
     */
    protected function humanizeFindingType(string $findingType): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $findingType));
    }
}
