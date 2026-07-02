<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxDocument;
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
        $proposalRows = UserTaxFact::forUser($userId)
            ->where('is_current', false)
            ->where('source_type', 'document_extraction')
            ->whereNull('confirmed_at')
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

        // W-4 filing status → human label
        if ($factKey === 'w4.filing_status') {
            return match (strtolower(trim($value))) {
                'single', 'single or married filing separately', 's' => 'Single / MFS',
                'married', 'married filing jointly', 'mfj', 'm' => 'Married Filing Jointly',
                'head of household', 'hoh', 'h' => 'Head of Household',
                'qualifying widow(er)', 'qw' => 'Qualifying Widow(er)',
                default => ucwords($value),
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
        // Owner-only authorization
        if ($fact->user_id !== $request->user()->id) {
            abort(403, 'You are not authorized to confirm this fact.');
        }

        $confirmed = UserTaxFact::confirmProposal($fact->id);

        return response()->json([
            'message' => 'Fact confirmed. This information may help identify relevant tax opportunities.',
            'fact' => $confirmed->only(['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type', 'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at']),
        ]);
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

        return response()->json([
            'message' => 'Your answer has been updated.',
            'fact' => $newFact->only(['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type', 'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at']),
        ]);
    }
}
