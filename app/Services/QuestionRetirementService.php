<?php

namespace App\Services;

use App\Models\UserTaxFact;

/**
 * QuestionRetirementService — 14-11 (Stage B + C, P5: "stored forever").
 *
 * Measures how many interview questions a document extraction retired by
 * proposing confirmed facts whose fact keys correspond to interview question
 * templates. This powers the "This upload answered 6 questions for you" payoff
 * in the Action Center and post-upload follow-up cards.
 *
 * DESIGN (deterministic, zero Claude):
 *   - "Retired" = a fact key proposed by a document (source_type=document_extraction,
 *     confirmed_at IS NOT NULL) that maps to a question in INTERVIEW_FACT_KEYS.
 *   - INTERVIEW_FACT_KEYS mirrors InterviewOrchestratorService::FACT_TIER_MAP plus
 *     the identity-plane keys added in 14-11 (w4.filing_status, w4.dependents_claimed,
 *     identity.employee_name).
 *   - Counting is per-user (aggregate) or per-document (granular for follow-up cards).
 *
 * GATE (D17): No Claude calls. No new packages. Pure Eloquent aggregation.
 *
 * LIABILITY BOUNDARY: The count is a PRESENTATION metric, not a legal claim.
 *   Copy must say "questions skipped" or "answered for you" — not "resolved your tax issues."
 */
class QuestionRetirementService
{
    /**
     * Fact keys that correspond to interview question templates (FACT_TIER_MAP union).
     * A confirmed document_extraction fact for any of these keys counts as "retired."
     *
     * Mirrored from InterviewOrchestratorService::FACT_TIER_MAP plus identity-plane
     * additions from 14-11 (w4.filing_status, w4.dependents_claimed, identity.employee_name).
     *
     * @var string[]
     */
    public const INTERVIEW_FACT_KEYS = [
        // Tier 1 — identity / reconciliation
        'profile.filing_status',
        'person.birth_year',
        'family.dependents_count',
        'family.qualifying_children_under_17',
        // Tier 2 — big-dollar retirement & benefits
        'employer.has_401k',
        'employer.match_pct',
        'employer.match_threshold_pct',
        'employer.contribution_pct',
        'retirement.traditional_401k_ytd_cents',
        'retirement.roth_401k_ytd_cents',
        'retirement.statement_balance_cents',
        'health.hsa_eligible',
        'hsa.coverage_type',
        'hsa.ytd_contribution_cents',
        'ira.traditional_ytd_contribution_cents',
        'ira.roth_ytd_contribution_cents',
        'benefits.fsa_ytd_cents',
        // Tier 3 — income & withholding
        'pay.frequency',
        'pay.gross_per_period_cents',
        'pay.federal_withholding_per_period_cents',
        'income.annual_gross_cents',
        'w4.filing_status',
        'w4.dependents_claimed',
        'w4.extra_withholding_per_period_cents',
        'has_self_employment',
        'profile.estimated_magi_cents',
        'prior_year.federal_liability_cents',
        'prior_year.agi_cents',
        'finance.is_cash_constrained',
        // Tier 4 — bonus & micro-probes
        'vehicle.usage_log_status',
        'bonus.expected_month',
        'bonus.expected_amount_cents',
        'retirement.target_age',
        'spouse.annual_income_cents',
        'spouse.covered_by_retirement_plan',
        // Identity-plane (14-11 Stage C)
        'identity.employee_name',
        'identity.employee_address',
    ];

    /**
     * Count how many interview questions a specific document's confirmed extractions retired.
     *
     * A fact "retires" a question when:
     *   1. It was proposed by this document (source_type='document_extraction', source_id=document ID)
     *   2. It has been confirmed by the user (confirmed_at IS NOT NULL — D4 gate)
     *   3. Its fact_key is in INTERVIEW_FACT_KEYS
     *
     * @param  int  $userId  Security: scoped to this user (T-14-11)
     * @param  int  $documentId  TaxDocument.id whose extractions to examine
     * @return array{count: int, retired_keys: string[]}
     */
    public function countByDocument(int $userId, int $documentId): array
    {
        $retiredKeys = UserTaxFact::where('user_id', $userId)
            ->where('source_type', 'document_extraction')
            ->where('source_id', (string) $documentId)
            ->whereNotNull('confirmed_at')
            ->whereIn('fact_key', self::INTERVIEW_FACT_KEYS)
            ->pluck('fact_key')
            ->unique()
            ->values()
            ->toArray();

        return [
            'count' => count($retiredKeys),
            'retired_keys' => $retiredKeys,
        ];
    }

    /**
     * Count how many interview questions ALL confirmed document extractions have retired
     * for this user (aggregate across all documents and tax years).
     *
     * Used by the Action Center as a "documents answered X questions for you" headline.
     *
     * @return array{count: int, retired_keys: string[]}
     */
    public function countByUser(int $userId): array
    {
        $retiredKeys = UserTaxFact::where('user_id', $userId)
            ->where('source_type', 'document_extraction')
            ->whereNotNull('confirmed_at')
            ->whereIn('fact_key', self::INTERVIEW_FACT_KEYS)
            ->pluck('fact_key')
            ->unique()
            ->values()
            ->toArray();

        return [
            'count' => count($retiredKeys),
            'retired_keys' => $retiredKeys,
        ];
    }

    /**
     * Human-readable summary line for Action Center / follow-up cards.
     *
     * Copy follows D18 quality bar: specific, data-lead, benefit-forward.
     * "N" is always a real engine-computed count (SAFE-03 analogue: no invented numbers).
     *
     * @param  int  $count  From countByDocument() or countByUser()
     * @return string Empty string when count=0 (nothing to surface)
     */
    public function summaryLine(int $count): string
    {
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return 'This document answered 1 question the interview would have asked.';
        }

        return "This document answered {$count} questions the interview would have asked.";
    }
}
