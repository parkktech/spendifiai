<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     *                confirmed_at=null (awaiting the D4 user-confirm gate)
     *
     * The encrypted value column is excluded via UserTaxFact::$hidden.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $fields = ['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type',
            'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at'];

        $confirmed = UserTaxFact::forUser($userId)
            ->where('is_current', true)
            ->orderBy('label')
            ->orderByDesc('asserted_at')
            ->get($fields);

        $proposals = UserTaxFact::forUser($userId)
            ->where('is_current', false)
            ->where('source_type', 'document_extraction')
            ->whereNull('confirmed_at')
            ->orderBy('label')
            ->orderByDesc('created_at')
            ->get($fields);

        return response()->json([
            'confirmed' => $confirmed,
            'proposals' => $proposals,
        ]);
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
