<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildIncomeOptimizationProfile;
use App\Jobs\CategorizePendingTransactions;
use App\Jobs\GenerateOptimizationReport;
use App\Models\IncomeOptimizationProfile;
use App\Models\OptimizationReport;
use App\Models\TaxDocument;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DurableFactsController — Phase 11-05 (STORE-01 anchor UI).
 *
 * Provides read and confirm access to the append-only UserTaxFact store
 * so the LearnedTaxFactsSection in Settings can display + confirm facts.
 *
 * SECURITY:
 *   - All endpoints require auth:sanctum (outer middleware group).
 *   - value column is $hidden on UserTaxFact — never exposed via toArray() or JSON.
 *   - Proposals expose display_value (humanized, owner-scoped) — never the raw encrypted value.
 *   - confirm() enforces owner check before delegating to UserTaxFact::confirmProposal().
 *   - supersede() only overwrites is_current=true facts owned by the caller.
 */
class DurableFactsController extends Controller
{
    /**
     * List the current user's durable tax facts.
     *
     * Returns:
     *   confirmed  — is_current=true facts (user's confirmed profile data)
     *   proposals  — is_current=false, source_type='document_extraction',
     *                confirmed_at=null (awaiting the D4 user-confirm gate).
     *                Each proposal includes display_value (humanized, authenticated-owner-only)
     *                and source_label ("from your Jul 2 Pay Stub").
     *
     * SECURITY: the raw encrypted value column is never serialised via toArray()/JSON
     * ($hidden guard on UserTaxFact). Proposals get display_value computed here in PHP
     * so only the authenticated owner ever receives it.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $confirmedFields = ['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type',
            'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at'];

        $confirmed = UserTaxFact::forUser($userId)
            ->where('is_current', true)
            ->orderBy('label')
            ->orderByDesc('asserted_at')
            ->get($confirmedFields);

        // Proposals: also load 'value' (encrypted, never serialised) + 'source_id'
        // so we can compute display_value and source_label server-side.
        // whereNull('superseded_by_id'): proposals resolved via confirm-with-correction
        // (fix 1b) are linked to the winning user_edit fact and leave the list.
        $proposalRows = UserTaxFact::forUser($userId)
            ->where('is_current', false)
            ->where('source_type', 'document_extraction')
            ->whereNull('confirmed_at')
            ->whereNull('superseded_by_id')
            ->orderBy('label')
            ->orderByDesc('created_at')
            ->get([...$confirmedFields, 'value', 'source_id']);

        // Pre-load referenced TaxDocuments to avoid N+1
        $documentIds = $proposalRows
            ->map(fn (UserTaxFact $f) => (int) ($f->metadata['document_id'] ?? $f->source_id))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $documents = TaxDocument::whereIn('id', $documentIds)
            ->where('user_id', $userId)    // owner safety: only own documents
            ->get(['id', 'category', 'created_at'])
            ->keyBy('id');

        $proposals = $proposalRows->map(function (UserTaxFact $fact) use ($documents): array {
            $docId = (int) ($fact->metadata['document_id'] ?? $fact->source_id ?? 0);
            $doc = $documents->get($docId);

            return [
                'id' => $fact->id,
                'fact_key' => $fact->fact_key,
                'label' => $fact->label,
                'volatility' => $fact->volatility,
                'tax_year' => $fact->tax_year,
                'source_type' => $fact->source_type,
                'is_current' => $fact->is_current,
                'confirmed_at' => $fact->confirmed_at,
                'asserted_at' => $fact->asserted_at,
                'metadata' => $fact->metadata,
                'created_at' => $fact->created_at,
                // Humanized value — authenticated-owner-only; raw encrypted value excluded.
                'display_value' => $this->humanizeValue($fact->fact_key, $fact->value),
                // "from your Jul 2 Pay Stub" — empty string when source cannot be resolved.
                'source_label' => $doc ? $this->buildSourceLabel($doc) : null,
            ];
        });

        // Safety-net dedup: if any residual duplicate open proposals exist for the same
        // fact_key (e.g. written before the recordFact() dedup was deployed), collapse
        // them by keeping only the newest. Sort by id (auto-increment — always
        // monotonically increasing, reliable even when created_at timestamps collide).
        // This is a display-layer guard; the primary fix lives in recordFact().
        $proposals = $proposals
            ->groupBy('fact_key')
            ->map(fn ($group) => $group->sortByDesc('id')->first())
            ->values();

        return response()->json([
            'confirmed' => $confirmed,
            'proposals' => $proposals,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Convert a stored fact value to a human-readable display string.
     *
     * Money (fact_key ends with _cents): integer cents → "$1,234.56"
     * Boolean-mapped (yes/no): "Yes" / "No"
     * W-4 filing status: normalised label
     * Everything else: returned as-is
     *
     * D18 note: we NEVER render raw fact_key paths in UI copy.
     */
    private function humanizeValue(string $factKey, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Money: fact_key suffix _cents → stored as integer-cents string
        if (str_ends_with($factKey, '_cents')) {
            $dollars = (int) $value / 100;

            return '$'.number_format($dollars, 2);
        }

        // Boolean-mapped interview convention
        if ($value === 'yes') {
            return 'Yes';
        }
        if ($value === 'no') {
            return 'No';
        }

        // W-4 filing status → human label.
        // Handles both verbatim form labels (legacy) and normalized engine enums
        // (written by PaystubFactExtractorService::normalizeW4FilingStatus since Fix 1).
        if ($factKey === 'w4.filing_status') {
            return match (strtolower(trim($value))) {
                'single', 'single_or_mfs', 'single or married filing separately', 's' => 'Single / MFS',
                'married', 'married_joint', 'married filing jointly', 'mfj', 'm' => 'Married Filing Jointly',
                'head of household', 'head_of_household', 'hoh', 'h' => 'Head of Household',
                'qualifying widow(er)', 'qw' => 'Qualifying Widow(er)',
                default => ucwords(str_replace('_', ' ', $value)),
            };
        }

        return $value;
    }

    /**
     * Build a human-readable source attribution line.
     *
     * Example: "from your Jul 2 Pay Stub"
     *
     * @param  TaxDocument  $doc  — already verified to belong to the authenticated user
     */
    private function buildSourceLabel(TaxDocument $doc): string
    {
        $categoryLabel = $doc->category?->label() ?? 'document';
        $date = $doc->created_at?->format('M j');

        return 'from your '.($date ? $date.' ' : '').$categoryLabel;
    }

    /**
     * Confirm a document_extraction proposal, making it the current fact.
     *
     * Delegates entirely to UserTaxFact::confirmProposal() which enforces:
     *   - source_type === 'document_extraction' guard
     *   - SELECT FOR UPDATE concurrency guard
     *   - Supersession of any prior current fact for the same key tuple
     *
     * T-11-05-01 mitigation: this is an explicit user-initiated action;
     * the server enforces the D4 gate (never silently confirmed).
     */
    public function confirm(Request $request, UserTaxFact $fact): JsonResponse
    {
        $request->validate([
            'value' => 'nullable|string|max:500',
        ]);

        // Owner-only authorization
        if ($fact->user_id !== $request->user()->id) {
            abort(403, 'You are not authorized to confirm this fact.');
        }

        $correctedValue = $request->input('value');

        // ── Confirm-with-correction (owner UX fix 1b) ─────────────────────────
        // The user reviewed the document proposal and typed a corrected value.
        // D4 semantics: the user's value WINS. It is written through recordFact()
        // with source_type='user_edit' (becomes current, supersedes any prior
        // current fact for the key tuple). The original proposal is marked
        // resolved (superseded_by_id → corrected fact) so it leaves the
        // proposals list, and the document linkage is preserved in metadata.
        if ($correctedValue !== null && trim($correctedValue) !== '') {
            if ($fact->source_type !== 'document_extraction') {
                return response()->json([
                    'message' => 'Only document suggestions can be corrected here.',
                ], 422);
            }

            if ($fact->confirmed_at !== null) {
                return response()->json([
                    'message' => 'This suggestion has already been confirmed.',
                ], 422);
            }

            // Typed conversion per the fact's type — money / int / string.
            // Returns [storedValue, null] on success or [null, specificError].
            [$storedValue, $typeError] = $this->normalizeCorrectedValue($fact->fact_key, trim($correctedValue));
            if ($typeError !== null) {
                return response()->json(['message' => $typeError], 422);
            }

            $newFact = UserTaxFact::recordFact(
                userId: $fact->user_id,
                factKey: $fact->fact_key,
                value: $storedValue,
                sourceType: 'user_edit',
                label: $fact->label,
                volatility: $fact->volatility,
                taxYear: $fact->tax_year,
                entityId: $fact->entity_id,
                sourceId: $fact->source_id,
                metadata: [
                    // Provenance: this user_edit corrected a document proposal.
                    'corrected_from_proposal_id' => $fact->id,
                    'document_id' => $fact->metadata['document_id'] ?? $fact->source_id,
                ],
            );

            // Resolve the proposal: it was answered by the correction, not confirmed
            // as-extracted. superseded_by_id links it to the winning fact and removes
            // it from the proposals list (index() filters resolved proposals out).
            $fact->update(['superseded_by_id' => $newFact->id]);

            // Fix 1 (D13 wiring gap): correction is a USER_ACTION — trigger regen.
            $this->dispatchRegenAfterFactChange($newFact->user_id, $newFact->tax_year ?? now()->year);

            return response()->json([
                'message' => 'Your corrected value has been saved.',
                'fact' => $newFact->only(['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type', 'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at']),
            ]);
        }

        // ── Plain confirm (no correction) — existing D4 behavior ─────────────
        $confirmed = UserTaxFact::confirmProposal($fact->id);

        // Fix 1 (D13 wiring gap): confirming a fact is a USER_ACTION — mark report stale
        // and dispatch a debounced regen job so the optimizer picks it up without the
        // user needing to manually refresh. ShouldBeUnique coalesces rapid confirms.
        $this->dispatchRegenAfterFactChange($confirmed->user_id, $confirmed->tax_year ?? now()->year);

        // ── Item 3 (c): Income reconciliation side effect ─────────────────────
        // When the user confirms income.paystub_monthly_base_cents, update
        // UserFinancialProfile.monthly_income to the derived value (cents → dollars).
        // This is the "14-09 profile-update path" from the item spec.
        if ($confirmed->fact_key === 'income.paystub_monthly_base_cents'
            && ($confirmed->metadata['is_income_reconciliation'] ?? false)
        ) {
            $derivedDollars = (float) ($confirmed->metadata['derived_monthly_dollars'] ?? 0);
            if ($derivedDollars > 0) {
                UserFinancialProfile::updateOrCreate(
                    ['user_id' => $confirmed->user_id],
                    ['monthly_income' => $derivedDollars],
                );
                // Re-trigger categorization and profile rebuild so findings stay current.
                CategorizePendingTransactions::dispatch($confirmed->user_id, true);
                BuildIncomeOptimizationProfile::dispatch($confirmed->user_id, now()->year);
            }
        }

        return response()->json([
            'message' => 'Fact confirmed. This information may help identify relevant tax opportunities.',
            'fact' => $confirmed->only(['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type', 'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at']),
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Fix 1 — D13 wiring gap: mark the report stale and dispatch a debounced regen job
     * after any USER_ACTION that modifies a confirmed fact (confirm, correct, supersede).
     *
     * Uses the same first-visit self-healing pattern as OptimizationReportController@show:
     * when no profile exists yet, dispatch BuildIncomeOptimizationProfile (full pipeline)
     * rather than GenerateOptimizationReport directly (which would produce empty findings).
     *
     * ShouldBeUnique coalesces multiple rapid confirms into a single execution.
     * The 30-second delay debounces bursts (user confirming 12 facts in quick succession).
     */
    private function dispatchRegenAfterFactChange(int $userId, int $taxYear): void
    {
        // Mark stale immediately so the UI shows the "analyzing" state on next poll.
        OptimizationReport::forUser($userId)
            ->where('tax_year', $taxYear)
            ->update(['is_stale' => true, 'stale_since' => now()]);

        // Dispatch regen (USER_ACTION: no D13 freshness/material-change gate).
        $hasProfile = IncomeOptimizationProfile::where('user_id', $userId)
            ->where('tax_year', $taxYear)
            ->exists();

        if (! $hasProfile) {
            // No profile yet — kick the full pipeline so findings exist before regen.
            BuildIncomeOptimizationProfile::dispatch($userId, $taxYear)
                ->delay(now()->addSeconds(30));
        } else {
            GenerateOptimizationReport::dispatch($userId, $taxYear)
                ->delay(now()->addSeconds(30));
        }
    }

    /**
     * Convert a user-typed corrected value into the stored representation
     * for the fact's type, with a specific validation error on failure.
     *
     * Money (fact_key ends _cents): "$4,250.00" / "4250" → integer-cents string.
     * Int (dependents/count facts): "3" → "3"; rejects non-integers.
     * String: stored as-is.
     *
     * @return array{0: string|null, 1: string|null} [storedValue, error]
     */
    private function normalizeCorrectedValue(string $factKey, string $input): array
    {
        // Money facts — stored as integer-cents-as-string
        if (str_ends_with($factKey, '_cents')) {
            $clean = str_replace(['$', ',', ' '], '', $input);
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
                return [null, 'Please enter a dollar amount, like 4,250.00.'];
            }

            return [(string) (int) round((float) $clean * 100), null];
        }

        // Integer facts (e.g. w4.dependents_claimed)
        if (str_ends_with($factKey, 'dependents_claimed') || str_ends_with($factKey, '_count')) {
            if (! preg_match('/^\d+$/', $input)) {
                return [null, 'Please enter a whole number, like 2.'];
            }

            return [$input, null];
        }

        // Everything else: plain string
        return [$input, null];
    }

    /**
     * Supersede a current fact with a user-edited value (re-answer from Settings).
     *
     * Creates a new UserTaxFact row via recordFact() with source_type='user_edit',
     * superseding the existing current row. The old row is preserved in the
     * append-only ledger (is_current=false, superseded_by_id set).
     *
     * SAFE-03 note: this endpoint does NOT accept estimated_value_cents.
     * The answer is a plain string; monetary implications are computed server-side
     * by TaxRulesEngineService only.
     */
    public function supersede(Request $request, UserTaxFact $fact): JsonResponse
    {
        $request->validate([
            'answer' => 'required|string|max:500',
        ]);

        // Owner-only + must be the current active fact
        if ($fact->user_id !== $request->user()->id) {
            abort(403, 'You are not authorized to update this fact.');
        }

        if (! $fact->is_current) {
            return response()->json([
                'message' => 'Only current facts may be superseded.',
            ], 422);
        }

        $newFact = UserTaxFact::recordFact(
            userId: $fact->user_id,
            factKey: $fact->fact_key,
            value: $request->validated('answer'),
            sourceType: 'user_edit',
            label: $fact->label,
            volatility: $fact->volatility,
            taxYear: $fact->tax_year,
            entityId: $fact->entity_id,
        );

        // Fix 1 (D13 wiring gap): user edit is a USER_ACTION — trigger regen.
        $this->dispatchRegenAfterFactChange($newFact->user_id, $newFact->tax_year ?? now()->year);

        return response()->json([
            'message' => 'Your answer has been updated.',
            'fact' => $newFact->only(['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type', 'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at']),
        ]);
    }
}
