<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFinancialProfileRequest;
use App\Jobs\BuildIncomeOptimizationProfile;
use App\Jobs\CategorizePendingTransactions;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\PlaidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserProfileController extends Controller
{
    /**
     * Update or create the user's financial profile.
     *
     * Fix 1: When ira_types is present, sync ira_type to the first element for
     * backward compatibility with existing callers that read ira_type as a single string.
     *
     * Fix 2: When the user saves a value that conflicts with an existing fact
     * (e.g. has_hsa=true but finance.has_hsa='no' exists), write a user_edit fact
     * so the system reflects the explicit user choice. User choice ALWAYS wins.
     */
    public function updateFinancial(UpdateFinancialProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = auth()->id();

        // Fix 1: Sync ira_type (legacy) from ira_types (multi-select).
        // If ira_types is provided and non-empty, backfill ira_type with the first element
        // so existing callers that read ira_type as a scalar continue to work.
        if (array_key_exists('ira_types', $validated)) {
            $iraTypes = $validated['ira_types'] ?? [];
            if (! empty($iraTypes)) {
                $validated['ira_type'] = $iraTypes[0];
            }
            // Empty array → user cleared all IRA types; keep existing ira_type unless
            // has_ira is also being set to false.
            if (empty($iraTypes) && array_key_exists('has_ira', $validated) && $validated['has_ira'] === false) {
                $validated['ira_type'] = null;
            }
        }

        $profile = UserFinancialProfile::updateOrCreate(
            ['user_id' => $userId],
            $validated
        );

        // Fix 2: Write user_edit facts for fields that override document-derived facts.
        // This ensures the facts store stays consistent with the user's explicit choices.
        $this->syncAccountFacts($userId, $validated);

        // Re-categorize all transactions with the updated profile context
        CategorizePendingTransactions::dispatch($userId, true);

        // Rebuild the income optimization profile so findings and the report stay current.
        // This fires OptimizationProfileBuilt → MarkOptimizationReportStale (flag flip) +
        // DispatchReportGeneration (debounced unique job). Additive — existing dispatches unchanged.
        BuildIncomeOptimizationProfile::dispatch($userId, now()->year);

        return response()->json([
            'message' => 'Profile updated',
            'profile' => $profile,
        ]);
    }

    /**
     * Write user_edit facts for tax-advantaged account fields.
     *
     * Called on profile save. Only writes facts for fields that are explicitly
     * present in the validated payload — absent fields are NOT touched.
     *
     * Facts written:
     *   finance.has_hsa  — 'yes'/'no' matching has_hsa boolean
     *   finance.has_ira  — 'yes'/'no' matching has_ira boolean
     *
     * source_type = 'user_edit' (self-confirmed, wins over document_extraction proposals).
     *
     * IMPLEMENTATION NOTE: Uses UserTaxFact::recordFact() which relies on
     * DB::transaction() + lockForUpdate() for append-only concurrency safety.
     * Profile saves are user-scoped and not concurrent in practice. If a
     * transient deadlock occurs (e.g. test infrastructure contention), the
     * exception is caught and logged; the profile save itself is unaffected.
     */
    protected function syncAccountFacts(int $userId, array $validated): void
    {
        try {
            if (array_key_exists('has_hsa', $validated) && $validated['has_hsa'] !== null) {
                UserTaxFact::recordFact(
                    userId: $userId,
                    factKey: 'finance.has_hsa',
                    value: $validated['has_hsa'] ? 'yes' : 'no',
                    sourceType: 'user_edit',
                    label: 'Health Savings Account (from profile)',
                    volatility: 'stable',
                );
            }

            if (array_key_exists('has_ira', $validated) && $validated['has_ira'] !== null) {
                UserTaxFact::recordFact(
                    userId: $userId,
                    factKey: 'finance.has_ira',
                    value: $validated['has_ira'] ? 'yes' : 'no',
                    sourceType: 'user_edit',
                    label: 'IRA (from profile)',
                    volatility: 'stable',
                );
            }

            // Bonus structure (2026-07-06): yearly bonus expressed either way —
            // 'percent' of base salary or 'flat' annual dollars. Percent mirrors to
            // income.bonus_structure_pct (assembleBaseline gives it precedence and
            // recomputes with income); flat mirrors to income.bonus_annual_cents
            // directly (user_edit — the strongest tier). Setting one shape clears
            // the other so the optimizer never sees a stale competing structure.
            $bonusType = $validated['bonus_structure_type'] ?? null;
            if ($bonusType === 'percent' && ($validated['bonus_structure_pct'] ?? null) !== null) {
                UserTaxFact::recordFact(
                    userId: $userId,
                    factKey: 'income.bonus_structure_pct',
                    value: (string) round((float) $validated['bonus_structure_pct'], 2),
                    sourceType: 'user_edit',
                    label: 'Annual bonus (% of base salary, from profile)',
                    volatility: 'annual',
                    taxYear: now()->year,
                );
            } elseif ($bonusType === 'flat' && ($validated['bonus_structure_amount'] ?? null) !== null) {
                UserTaxFact::recordFact(
                    userId: $userId,
                    factKey: 'income.bonus_annual_cents',
                    value: (string) (int) round((float) $validated['bonus_structure_amount'] * 100),
                    sourceType: 'user_edit',
                    label: 'Yearly bonus (flat amount, from profile)',
                    volatility: 'annual',
                    taxYear: now()->year,
                );
                // Clear any percent structure so the flat amount governs.
                UserTaxFact::recordFact(
                    userId: $userId,
                    factKey: 'income.bonus_structure_pct',
                    value: '0',
                    sourceType: 'user_edit',
                    label: 'Annual bonus (% of base salary, from profile)',
                    volatility: 'annual',
                    taxYear: now()->year,
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal: fact sync is a best-effort bridge. The profile save
            // itself has already succeeded. Log and continue.
            Log::warning('syncAccountFacts: failed to write user_edit facts', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'sqlstate' => $e instanceof \Illuminate\Database\QueryException
                    ? $e->getSqlState() : null,
            ]);
        }
    }

    /**
     * Show the user's financial profile.
     *
     * Fix 2: Adds an additive `derived_accounts` block that the form uses to
     * pre-fill and annotate checkboxes based on what the system KNOWS from
     * documents and interviews.
     *
     * Existing `profile` response shape is NOT modified — derived_accounts is
     * a new top-level key so callers that ignore it remain unaffected.
     *
     * Structure:
     * {
     *   "hsa": {
     *     "value": "no",        // 'yes'|'no'|null (null = no fact exists)
     *     "source": "documents" // 'documents'|'interview'|'user_edit'|null
     *   },
     *   "ira": {
     *     "value": "yes",
     *     "source": "documents"
     *   },
     *   "ira_types": {
     *     "traditional": {"value": true, "source": "documents"},
     *     "roth":        {"value": true, "source": "documents"}
     *   }
     * }
     */
    public function showFinancial(): JsonResponse
    {
        $userId = auth()->id();
        $profile = UserFinancialProfile::where('user_id', $userId)->first();

        if (! $profile) {
            return response()->json([
                'message' => 'No financial profile set up yet.',
                'profile' => null,
            ]);
        }

        $derivedAccounts = $this->buildDerivedAccounts($userId);

        return response()->json([
            'profile' => $profile,
            'derived_accounts' => $derivedAccounts,
        ]);
    }

    /**
     * Build the derived_accounts block from facts (Fix 2).
     *
     * Sources checked (in priority order):
     *   1. finance.has_hsa — written when user marks hsa_statement as N/A
     *   2. employer.hsa_deduction_ytd — payroll deduction detected from paystub
     *   3. retirement.hsa_ytd_cents — HSA from retirement statement
     *   4. ira.traditional_ytd_contribution_cents — Traditional IRA from interview/paystub
     *   5. ira.roth_ytd_contribution_cents — Roth IRA from interview/paystub
     *   6. retirement.has_ira_balance — IRA balance confirmed by retirement statement
     *
     * Returns only additive annotation data. Never overwrites the profile columns.
     *
     * @return array<string, mixed>
     */
    protected function buildDerivedAccounts(int $userId): array
    {
        $taxYear = now()->year;

        // ── HSA ──────────────────────────────────────────────────────────────
        $hsaFact = UserTaxFact::currentFact($userId, 'finance.has_hsa', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'finance.has_hsa');

        // Secondary signal: if HSA deduction was detected from payroll docs
        $hsaDeductionFact = UserTaxFact::currentFact($userId, 'employer.hsa_deduction_ytd', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'employer.hsa_deduction_ytd');

        // Tertiary: retirement statement HSA line
        $hsaRetirementFact = UserTaxFact::currentFact($userId, 'retirement.hsa_ytd_cents', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'retirement.hsa_ytd_cents');

        $hsaDerived = null;
        $hsaSource = null;

        if ($hsaFact !== null) {
            $hsaDerived = strtolower((string) $hsaFact->value) === 'yes' ? 'yes' : 'no';
            $hsaSource = match ($hsaFact->source_type) {
                'user_edit' => 'your answers',
                'interview_answer' => 'interview',
                'document_extraction' => 'documents',
                'profile_field' => 'profile',
                default => 'documents',
            };
        } elseif ($hsaDeductionFact !== null && (int) $hsaDeductionFact->value > 0) {
            $hsaDerived = 'yes';
            $hsaSource = 'documents';
        } elseif ($hsaRetirementFact !== null && (int) $hsaRetirementFact->value > 0) {
            $hsaDerived = 'yes';
            $hsaSource = 'documents';
        }

        // ── IRA ──────────────────────────────────────────────────────────────
        $tradFact = UserTaxFact::currentFact($userId, 'ira.traditional_ytd_contribution_cents', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'ira.traditional_ytd_contribution_cents');

        $rothFact = UserTaxFact::currentFact($userId, 'ira.roth_ytd_contribution_cents', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'ira.roth_ytd_contribution_cents');

        $hasIraBalanceFact = UserTaxFact::currentFact($userId, 'retirement.has_ira_balance', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'retirement.has_ira_balance');

        $tradDerived = $tradFact !== null && (int) $tradFact->value > 0;
        $rothDerived = $rothFact !== null && (int) $rothFact->value > 0;
        $hasIraDerived = $tradDerived || $rothDerived
            || ($hasIraBalanceFact !== null
                && in_array(strtolower((string) $hasIraBalanceFact->value), ['true', '1', 'yes'], true));

        $iraSource = ($tradFact !== null || $rothFact !== null) ? 'documents' : null;
        if (! $iraSource && $hasIraBalanceFact !== null) {
            $iraSource = 'documents';
        }

        $iraDerived = $hasIraDerived ? 'yes' : null;

        // finance.has_ira user_edit fact (if present, always wins for the IRA block)
        $hasIraFact = UserTaxFact::currentFact($userId, 'finance.has_ira', null, $taxYear)
            ?? UserTaxFact::currentFact($userId, 'finance.has_ira');
        if ($hasIraFact !== null) {
            $iraDerived = strtolower((string) $hasIraFact->value) === 'yes' ? 'yes' : 'no';
            $iraSource = match ($hasIraFact->source_type) {
                'user_edit' => 'your answers',
                'interview_answer' => 'interview',
                default => 'documents',
            };
        }

        return [
            'hsa' => [
                'value' => $hsaDerived,            // 'yes'|'no'|null
                'source' => $hsaSource,            // string|null
            ],
            'ira' => [
                'value' => $iraDerived,            // 'yes'|'no'|null
                'source' => $iraSource,            // string|null
            ],
            'ira_types' => [
                'traditional' => [
                    'value' => $tradDerived,       // bool
                    'source' => $tradFact !== null ? 'documents' : null,
                ],
                'roth' => [
                    'value' => $rothDerived,       // bool
                    'source' => $rothFact !== null ? 'documents' : null,
                ],
            ],
        ];
    }

    /**
     * Update the user's timezone.
     */
    public function updateTimezone(Request $request): JsonResponse
    {
        $request->validate([
            'timezone' => 'required|string|max:64|timezone:all',
        ]);

        $request->user()->update([
            'timezone' => $request->timezone,
        ]);

        return response()->json([
            'message' => 'Timezone updated',
            'timezone' => $request->user()->timezone,
        ]);
    }

    /**
     * Delete the user's account and all associated data.
     * GDPR/CCPA compliance.
     *
     * Requires password confirmation for security.
     * Revokes all Plaid access tokens before cascading data deletion.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $user = auth()->user();

        // 1. Disconnect all bank connections (revokes Plaid access tokens)
        $plaidService = app(PlaidService::class);
        $user->bankConnections->each(function ($connection) use ($plaidService) {
            try {
                $plaidService->disconnect($connection);
            } catch (\Exception $e) {
                // Log but continue -- don't block account deletion if Plaid fails
                Log::warning('Failed to disconnect bank during account deletion', [
                    'user_id' => $connection->user_id,
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // 2. Revoke all API tokens
        $user->tokens()->delete();

        // 3. Delete the user (cascade deletes handle all related data via foreign keys)
        $user->delete();

        return response()->json(null, 204);
    }
}
