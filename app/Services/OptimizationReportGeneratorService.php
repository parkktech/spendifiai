<?php

namespace App\Services;

use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationFinding;
use App\Models\OptimizationReport;
use App\Models\User;
use App\Models\UserTaxFact;
use Illuminate\Support\Facades\Log;

/**
 * OptimizationReportGeneratorService — RPT-01/02/06/07/08.
 *
 * Assembles a ranked, sectioned optimization report from existing
 * OptimizationFinding records and TaxRulesEngineService outputs.
 *
 * SECURITY RULES (non-negotiable):
 *
 *  SAFE-03: This service READS estimated_value_cents for ranking but
 *  NEVER ASSIGNS IT. The SAFE-03 grep-gate (EstimatedValueGuardTest) scans
 *  all PHP files in app/ and fails the build if any file other than
 *  TaxRulesEngineService.php assigns estimated_value_cents.
 *
 *  Dollar figures from findings are used ONLY for sorting. They are NEVER
 *  included in the Claude narrator payload (T-12-03-01) and NEVER stored
 *  in OptimizationReport.sections (the report stores finding IDs + prose only).
 *
 *  T-12-03-02: All OptimizationReport queries go through scopeForUser().
 */
class OptimizationReportGeneratorService
{
    public function __construct(
        protected OptimizationReportNarratorService $narrator,
    ) {}

    /**
     * Generate (or regenerate) the optimization report for a user + tax year.
     *
     * Steps:
     *  1. Load all OptimizationFindings for the user/year
     *  2. Group into 4 topical sections (config map)
     *  3. Rank each section by severity tier then estimated_value_cents DESC (READ only)
     *  4. Build 3 RPT-06 wrapper sections
     *  5. Build RPT-08 year-end awareness section
     *  6. Attach RPT-07 glossary section
     *  7. Narrate each topical section via OptimizationReportNarratorService
     *  8. Narrate executive summary
     *  9. Upsert OptimizationReport (is_stale=false, rebuilt_at=now())
     */
    public function generate(User $user, int $taxYear): OptimizationReport
    {
        Log::info('OptimizationReportGeneratorService: starting', [
            'user_id' => $user->id,
            'tax_year' => $taxYear,
        ]);

        // Load all findings for this user + year
        $findings = OptimizationFinding::forUser($user->id)
            ->where('tax_year', $taxYear)
            ->get();

        $sections = [];

        // ── Step 1: 4 Topical Sections (RPT-01) ──────────────────────────

        $topicalSections = $this->buildTopicalSections($findings->toArray());
        $sections = array_merge($sections, $topicalSections);

        // ── Step 2: 3 Wrapper Sections (RPT-06) ──────────────────────────

        $wrapperSections = $this->buildWrapperSections($findings->toArray());
        $sections = array_merge($sections, $wrapperSections);

        // ── Step 3: Year-End Awareness Section (RPT-08) ──────────────────

        $yearEndSection = $this->buildYearEndSection($findings->toArray(), $taxYear);
        $sections[] = $yearEndSection;

        // ── Step 4: Glossary Section (RPT-07) ────────────────────────────

        $glossarySection = $this->buildGlossarySection();
        $sections[] = $glossarySection;

        // ── Step 4b: Chosen Plan Section (SCN-08 / §D.7) ─────────────────
        // Injected ONLY when the user has an active scenario.chosen_option fact.
        // SAFE-03: carries no dollar figures — delta-tier strings + metadata only.

        $chosenPlanSection = $this->buildChosenPlanSection($user, $taxYear);
        if ($chosenPlanSection !== null) {
            $sections[] = $chosenPlanSection;
        }

        // ── Step 5: Narrate Executive Summary ────────────────────────────

        $sectionSummaries = array_map(fn (array $s): array => [
            'title' => $s['title'],
            'finding_count' => count($s['findings'] ?? []),
        ], array_filter($topicalSections, fn ($s): bool => $s['section_type'] === 'topical'));

        // D19: structured executive summary {summary, bullets} | null.
        $execSummaryStructured = $this->narrator->narrateExecutiveSummary($sectionSummaries);
        $executiveSummary = $execSummaryStructured['summary'] ?? null; // backward compat

        // ── Step 6: Build built_against snapshot (D13) ───────────────────
        // Snapshot the income/savings aggregates from the current profile so
        // MarkOptimizationReportStale can detect material changes in future
        // data-churn events and suppress within-window staleness correctly.

        $profile = IncomeOptimizationProfile::where('user_id', $user->id)
            ->where('tax_year', $taxYear)
            ->first();

        $findingKeys = $findings->pluck('finding_type')->unique()->sort()->values()->all();

        $builtAgainst = [
            'income_cents' => ReportStalenessPolicy::computeIncomeCents($profile),
            'savings_cents' => ReportStalenessPolicy::computeSavingsCents($profile),
            'finding_keys' => $findingKeys,
            'generated_at' => now()->toIso8601String(),
        ];

        // ── Step 7: Upsert Report ─────────────────────────────────────────

        $report = OptimizationReport::updateOrCreate(
            ['user_id' => $user->id, 'tax_year' => $taxYear],
            [
                'sections' => $sections,
                'executive_summary' => $executiveSummary,
                'executive_summary_structured' => $execSummaryStructured, // D19
                'is_stale' => false,
                'rebuilt_at' => now(),
                'stale_since' => null,
                'built_against' => $builtAgainst,
            ]
        );

        Log::info('OptimizationReportGeneratorService: complete', [
            'user_id' => $user->id,
            'tax_year' => $taxYear,
            'section_count' => count($sections),
            'finding_count' => $findings->count(),
            'income_cents_snapshot' => $builtAgainst['income_cents'],
            'savings_cents_snapshot' => $builtAgainst['savings_cents'],
        ]);

        return $report;
    }

    // ─── Private: Topical Section Builders ───────────────────────────────

    /**
     * Build 4 topical sections (deductions / taxes / filings / retirement_401k).
     *
     * Ranking: severity tier (high=0, medium=1, low=2) then estimated_value_cents
     * DESC. estimated_value_cents is READ only — never assigned (SAFE-03).
     *
     * @param  array<int, array<string, mixed>>  $findingsArray
     * @return array<int, array<string, mixed>>
     */
    protected function buildTopicalSections(array $findingsArray): array
    {
        $sectionDefs = config('optimization-report.sections', []);
        $typeMap = config('optimization-report.finding_type_to_section', []);
        $defaultSection = config('optimization-report.default_section', 'deductions');
        $severityOrder = config('optimization-report.severity_order', ['high' => 0, 'medium' => 1, 'low' => 2]);
        $disclaimer = config('optimization-report.section_disclaimer', '');

        // Group findings into sections
        $grouped = [];
        foreach ($findingsArray as $finding) {
            $type = $finding['finding_type'] ?? '';
            $sectionKey = $typeMap[$type] ?? $defaultSection;
            $grouped[$sectionKey][] = $finding;
        }

        // Rank each section: severity tier ASC then estimated_value_cents DESC
        // READ: $finding['estimated_value_cents'] is accessed for sorting only (SAFE-03)
        foreach ($grouped as $key => $sectionFindings) {
            usort($sectionFindings, function (array $a, array $b) use ($severityOrder): int {
                $aSeverity = $severityOrder[$a['severity'] ?? 'low'] ?? 2;
                $bSeverity = $severityOrder[$b['severity'] ?? 'low'] ?? 2;
                if ($aSeverity !== $bSeverity) {
                    return $aSeverity <=> $bSeverity;  // lower = higher priority
                }
                // READ estimated_value_cents for ranking — never assign it (SAFE-03)
                $aValue = (int) ($a['estimated_value_cents'] ?? 0);
                $bValue = (int) ($b['estimated_value_cents'] ?? 0);

                return $bValue <=> $aValue;  // higher value = higher rank
            });
            $grouped[$key] = $sectionFindings;
        }

        $sections = [];
        foreach ($sectionDefs as $def) {
            $key = $def['section_key'];
            $sectionFindings = $grouped[$key] ?? [];

            // Build finding rows for the section (no monetary columns)
            $findingRows = array_map(fn (array $f): array => [
                'finding_id' => $f['id'] ?? null,
                'finding_type' => $f['finding_type'] ?? '',
                'severity' => $f['severity'] ?? 'low',
                'band' => $f['band'] ?? null,
                'legal_basis' => $f['legal_basis'] ?? null,
                'description' => $f['description'] ?? null,
                'docs_missing' => $f['docs_missing'] ?? [],
                'pro_export_ready' => $f['pro_export_ready'] ?? false,
                // estimated_value_cents NOT included here — stays in the finding model
            ], $sectionFindings);

            // D19: Narrate the section (structured output — {summary, bullets}).
            // narrator_prose (backward compat) = summary string | null.
            // narrator_structured (D19) = {summary, bullets} | null.
            $narratorStructured = null;
            $narratorProse = null;
            if (! empty($findingRows)) {
                $narratorStructured = $this->narrator->narrateSection([
                    'section_title' => $def['title'],
                    // Payload to Claude contains only non-monetary metadata (T-12-03-01)
                    'findings' => array_map(fn (array $r): array => [
                        'finding_type' => $r['finding_type'],
                        'severity' => $r['severity'],
                        'band' => $r['band'],
                        'legal_basis' => $r['legal_basis'],
                        // estimated_value_cents and all monetary columns deliberately excluded
                    ], $findingRows),
                ]);
                // Backward compat: narrator_prose = summary text (renderers use narrator_structured first)
                $narratorProse = $narratorStructured['summary'] ?? null;
            }

            $sections[] = [
                'section_key' => $key,
                'section_type' => 'topical',
                'title' => $def['title'],
                'description' => $def['description'],
                'findings' => $findingRows,
                'narrator_prose' => $narratorProse,            // backward compat (D17 display clamp)
                'narrator_structured' => $narratorStructured,  // D19 structured fields
                // RPT-03: persistent per-section educational disclaimer
                'disclaimer' => $disclaimer,
            ];
        }

        return $sections;
    }

    // ─── Private: Wrapper Section Builders (RPT-06) ──────────────────────

    /**
     * Build 3 RPT-06 wrapper sections:
     *  1. Documents Missing
     *  2. Needs Professional Review (specialist-band findings)
     *  3. What We Refused and Why
     *
     * @param  array<int, array<string, mixed>>  $findingsArray
     * @return array<int, array<string, mixed>>
     */
    protected function buildWrapperSections(array $findingsArray): array
    {
        $disclaimer = config('optimization-report.section_disclaimer', '');
        $specialistBands = config('optimization-report.specialist_bands', ['specialist']);

        // ── Section 5: Documents Missing ─────────────────────────────────

        $docsMissing = [];
        foreach ($findingsArray as $finding) {
            $missingDocs = $finding['docs_missing'] ?? [];
            if (! empty($missingDocs)) {
                foreach ((array) $missingDocs as $doc) {
                    $docsMissing[] = [
                        'document' => $doc,
                        'finding_type' => $finding['finding_type'] ?? '',
                        'finding_id' => $finding['id'] ?? null,
                    ];
                }
            }
        }

        $sections[] = [
            'section_key' => 'documents_missing',
            'section_type' => 'wrapper',
            'title' => 'Documents That Would Strengthen Your Position',
            'description' => 'The following document types were referenced by your findings but are not yet in your vault. Uploading them can improve the completeness of your report and help substantiate items for a professional review.',
            'findings' => [],
            'docs_missing' => $docsMissing,
            'narrator_prose' => null,
            'disclaimer' => $disclaimer,
        ];

        // ── Section 6: Needs Professional Review ─────────────────────────

        $specialistFindings = array_values(array_filter(
            $findingsArray,
            fn (array $f): bool => in_array($f['band'] ?? '', $specialistBands, true)
        ));

        $specialistRows = array_map(fn (array $f): array => [
            'finding_id' => $f['id'] ?? null,
            'finding_type' => $f['finding_type'] ?? '',
            'severity' => $f['severity'] ?? 'low',
            'band' => $f['band'] ?? null,
            'legal_basis' => $f['legal_basis'] ?? null,
            'description' => $f['description'] ?? null,
        ], $specialistFindings);

        $sections[] = [
            'section_key' => 'needs_professional_review',
            'section_type' => 'wrapper',
            'title' => 'Items That Warrant a Professional Review',
            'description' => 'The following findings are in the "specialist" confidence band, meaning they involve complex or fact-specific areas where a CPA, EA, or tax attorney is best positioned to evaluate your specific situation.',
            'findings' => $specialistRows,
            'narrator_prose' => null,
            'disclaimer' => $disclaimer,
        ];

        // ── Section 7: What We Refused and Why (RPT-06 trust feature) ────

        $refusedList = config('optimization-report.refused_recommendations', []);

        $sections[] = [
            'section_key' => 'what_we_refused',
            'section_type' => 'wrapper',
            'title' => 'What This Report Did Not Recommend — And Why',
            'description' => 'SpendifiAI is an educational tool, not a tax advisor or investment adviser. The following categories of recommendations are outside our scope. We describe WHAT was declined and WHY — never the mechanics of how these approaches work.',
            'findings' => [],
            'refused_recommendations' => $refusedList,
            'narrator_prose' => null,
            'disclaimer' => $disclaimer,
        ];

        return $sections;
    }

    // ─── Private: Year-End Section Builder (RPT-08) ──────────────────────

    /**
     * Build the year-end awareness section (RPT-08).
     *
     * Dec-31 items are framed as "commonly reviewed before year end".
     * Jan-15 / April items surfaced informatively.
     * State-layer items suppressed (STATE-01).
     * NO countdown/pressure — educational surfacing only.
     *
     * @param  array<int, array<string, mixed>>  $findingsArray
     */
    protected function buildYearEndSection(array $findingsArray, int $taxYear): array
    {
        $yearEndConfig = config('optimization-report.year_end_awareness', []);

        // Extract findings with deadline metadata
        $deadlineFindings = array_values(array_filter(
            $findingsArray,
            fn (array $f): bool => ! empty($f['deadline'])
        ));

        // Categorize by deadline window (Dec 31 vs Jan/April)
        // State-layer items are suppressed — no state-jurisdiction logic here
        $dec31Items = [];
        $jan15Items = [];
        $aprilItems = [];

        foreach ($deadlineFindings as $finding) {
            // $finding['deadline'] is cast to 'date' in the model, stored as string here
            $deadlineStr = is_object($finding['deadline'])
                ? $finding['deadline']->format('m')
                : (string) ($finding['deadline'] ?? '');

            // Parse month from deadline
            $month = 0;
            if (strlen($deadlineStr) >= 7) {
                $month = (int) substr($deadlineStr, 5, 2);  // YYYY-MM-DD → MM
            } elseif (is_numeric($deadlineStr)) {
                // Already a month integer
                $month = (int) $deadlineStr;
            }

            $row = [
                'finding_id' => $finding['id'] ?? null,
                'finding_type' => $finding['finding_type'] ?? '',
                'description' => $finding['description'] ?? null,
                'lead_time_days' => $finding['lead_time_days'] ?? null,
                'reversible' => $finding['reversible'] ?? true,
                // No dollar amounts included
            ];

            if ($month === 12) {
                $dec31Items[] = $row;
            } elseif ($month === 1) {
                $jan15Items[] = $row;
            } elseif ($month === 4 || $month === 3) {
                $aprilItems[] = $row;
            }
        }

        $framing = $yearEndConfig['dec_31_framing'] ?? 'commonly reviewed before year end';

        return [
            'section_key' => 'year_end_awareness',
            'section_type' => 'year_end',
            'title' => $yearEndConfig['section_title'] ?? 'Year-End Tax Awareness',
            'description' => $yearEndConfig['section_intro'] ?? '',
            'findings' => [],
            'dec_31_items' => $dec31Items,
            'jan_15_items' => $jan15Items,
            'april_items' => $aprilItems,
            'dec_31_framing' => $framing,
            'jan_15_framing' => $yearEndConfig['jan_15_framing'] ?? 'commonly relevant in January',
            'april_framing' => $yearEndConfig['april_framing'] ?? 'commonly relevant around the April filing window',
            'narrator_prose' => null,
            // RPT-03: persistent disclaimer
            'disclaimer' => $yearEndConfig['disclaimer'] ?? config('optimization-report.section_disclaimer', ''),
        ];
    }

    // ─── Private: Glossary Section Builder (RPT-07) ──────────────────────

    protected function buildGlossarySection(): array
    {
        $glossary = config('optimization-report.glossary', []);

        return [
            'section_key' => 'glossary',
            'section_type' => 'glossary',
            'title' => 'Educational Glossary',
            'description' => 'Key terms and concepts referenced in this report. All content is educational only — none of this constitutes tax advice.',
            'findings' => [],
            'glossary_entries' => $glossary,
            'narrator_prose' => null,
            'disclaimer' => config('optimization-report.section_disclaimer', ''),
        ];
    }

    // ─── Private: Chosen Plan Section Builder (SCN-08 / §D.7) ───────────

    /**
     * Build the chosen_plan report section (§D.7).
     *
     * Injected into the report only when the user has an active scenario.chosen_option
     * UserTaxFact for this tax year. Returns null when no choice has been made.
     *
     * SAFE-03: this section stores NO monetary values. The 'outcomes' field carries
     * tier-strings (positive_large, neutral, etc.) produced by ScenarioController::deltaTier()
     * — never raw cents. The narrator payload is also non-monetary (narrateScenarioComparison).
     */
    protected function buildChosenPlanSection(User $user, int $taxYear): ?array
    {
        // Check if a scenario choice exists for this year
        $chosenFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_option', null, $taxYear);
        if ($chosenFact === null) {
            return null;
        }

        $optionKey = $chosenFact->value;
        $factSetId = $chosenFact->metadata['fact_set_id'] ?? null;

        // Load chosen knobs (if available) for outcome tiers
        $knobsFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_knobs', null, $taxYear);
        $chosenKnobs = [];
        if ($knobsFact !== null) {
            $chosenKnobs = json_decode($knobsFact->value, true) ?? [];
        }

        // Checklist item count for the narrator context
        $checklistCount = OptimizationChecklistItem::where('user_id', $user->id)
            ->where('tax_year', $taxYear)
            ->where('kind', '!=', 'header')
            ->count();

        // Outcome tiers from metadata (stored by ScenarioController — no dollar amounts)
        $outcomes = $chosenFact->metadata['outcomes'] ?? [];

        // Config section definition
        $sectionConfig = config('optimization-report.chosen_plan_section', []);

        // Narrate — SAFE-03 payload (no cents fields)
        $narratorContext = [
            'option_key' => $optionKey,
            'option_label' => $this->optionLabel($optionKey),
            'checklist_item_count' => $checklistCount,
            'readiness' => [],   // not available at section-build time — narrator uses option_key
            'outcomes' => $outcomes,
        ];
        $narrated = $this->narrator->narrateScenarioComparison($narratorContext);
        $narratorProse = $narrated['summary'] ?? null;

        return [
            'section_key' => 'chosen_plan',
            'section_type' => 'chosen_plan',
            'title' => $sectionConfig['title'] ?? 'Your Optimization Plan',
            'description' => $sectionConfig['description'] ?? '',
            'icon' => $sectionConfig['icon'] ?? 'check-circle',
            'order' => (int) ($sectionConfig['order'] ?? 100),
            'findings' => [],
            'chosen' => [
                'option_key' => $optionKey,
                'option_label' => $this->optionLabel($optionKey),
                'fact_set_id' => $factSetId,
                'checklist_item_count' => $checklistCount,
                // SAFE-03: no _cents fields in this payload
                'outcomes' => $outcomes,
            ],
            'narrator_structured' => $narrated,
            'narrator_prose' => $narratorProse,
            'disclaimer' => config('optimization-report.section_disclaimer', ''),
        ];
    }

    /**
     * Map an option_key to a human-readable label (mirrors ScenarioController).
     */
    private function optionLabel(string $optionKey): string
    {
        return match ($optionKey) {
            'take_home' => 'Maximize take-home pay',
            'tax_burden' => 'Minimize 2026 federal tax',
            'retirement' => 'Maximize retirement contributions',
            'balanced' => 'Balanced approach',
            'merged' => 'Balanced across all objectives',
            'custom' => 'Custom plan',
            default => ucfirst(str_replace('_', ' ', $optionKey)),
        };
    }
}
