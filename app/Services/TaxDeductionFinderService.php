<?php

namespace App\Services;

use App\Models\TaxDeduction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxDeduction;
use Illuminate\Support\Facades\DB;

class TaxDeductionFinderService
{
    /**
     * Run all three detection modes and return a summary.
     */
    public function findDeductions(User $user, int $taxYear): array
    {
        $transactionResults = $this->scanTransactions($user, $taxYear);
        $profileResults = $this->matchProfile($user, $taxYear);

        $userIds = $user->householdUserIds();

        $questionnaire = TaxDeduction::active()
            ->questionnaire()
            ->whereDoesntHave('userDeductions', function ($q) use ($userIds, $taxYear) {
                $q->whereIn('user_id', $userIds)
                    ->where('tax_year', $taxYear);
            })
            ->orderBy('sort_order')
            ->get();

        $discovered = UserTaxDeduction::whereIn('user_id', $userIds)
            ->where('tax_year', $taxYear)
            ->with('deduction')
            ->get();

        $grouped = [
            'auto_detected' => $discovered->where('detected_from', 'ai_scan')->values(),
            'profile_matched' => $discovered->where('detected_from', 'questionnaire')
                ->merge($discovered->where('detected_from', 'profile_match'))
                ->values(),
            'claimed' => $discovered->where('status', 'claimed')->values(),
        ];

        $totalEstimated = (float) $discovered
            ->whereIn('status', ['eligible', 'claimed'])
            ->sum('estimated_amount');

        return [
            'deductions' => $grouped,
            'questionnaire_remaining' => $questionnaire->count(),
            'total_discovered' => $discovered->count(),
            'total_estimated_savings' => round($totalEstimated, 2),
            'scan_results' => $transactionResults,
            'profile_results' => $profileResults,
        ];
    }

    /**
     * Mode 1: Scan transactions for keywords matching deductions.
     */
    public function scanTransactions(User $user, int $taxYear): array
    {
        $deductions = TaxDeduction::active()->detectable()->get();
        $found = 0;
        $updated = 0;

        foreach ($deductions as $deduction) {
            $keywords = $deduction->transaction_keywords ?? [];
            if (empty($keywords)) {
                continue;
            }

            // Build ILIKE query for transaction matching (household-scoped)
            $query = Transaction::whereIn('user_id', $user->householdUserIds())
                ->whereYear('transaction_date', $taxYear)
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $q->orWhere('merchant_name', 'ILIKE', '%'.$keyword.'%')
                            ->orWhere('description', 'ILIKE', '%'.$keyword.'%');
                    }
                });

            $matchCount = $query->count();
            $totalAmount = (float) $query->sum(DB::raw('ABS(amount)'));

            if ($matchCount === 0) {
                continue;
            }

            // Calculate estimated deduction value
            $estimatedAmount = $totalAmount;
            if ($deduction->max_amount && $estimatedAmount > (float) $deduction->max_amount) {
                $estimatedAmount = (float) $deduction->max_amount;
            }

            // For credits that are a % of expenses, use the actual amount
            // For deductions like business meals at 50%, apply the rate
            if ($deduction->slug === 'business-meals') {
                $estimatedAmount = $totalAmount * 0.5;
            }

            $record = UserTaxDeduction::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'tax_deduction_id' => $deduction->id,
                    'tax_year' => $taxYear,
                ],
                [
                    'status' => 'eligible',
                    'estimated_amount' => round($estimatedAmount, 2),
                    'detected_from' => 'ai_scan',
                    'detection_confidence' => min(0.95, 0.5 + ($matchCount * 0.05)),
                    'notes' => "{$matchCount} matching transactions found totaling \$".number_format($totalAmount, 2),
                ]
            );

            $record->wasRecentlyCreated ? $found++ : $updated++;
        }

        return [
            'new_deductions_found' => $found,
            'existing_updated' => $updated,
        ];
    }

    /**
     * Mode 2: Match deductions based on user's financial profile.
     */
    public function matchProfile(User $user, int $taxYear): array
    {
        $profile = UserFinancialProfile::where('user_id', $user->id)->first();
        if (! $profile) {
            return ['matched' => 0, 'reason' => 'No financial profile found'];
        }

        $deductions = TaxDeduction::active()->get();
        $matched = 0;

        foreach ($deductions as $deduction) {
            $rules = $deduction->eligibility_rules ?? [];
            if (empty($rules)) {
                continue;
            }

            // Check if already processed (by any household member)
            $existing = UserTaxDeduction::whereIn('user_id', $user->householdUserIds())
                ->where('tax_deduction_id', $deduction->id)
                ->where('tax_year', $taxYear)
                ->first();

            if ($existing && $existing->detected_from === 'ai_scan') {
                continue; // Don't override transaction scan results
            }

            if ($existing && in_array($existing->status, ['claimed', 'not_eligible', 'skipped'])) {
                continue; // Don't override user decisions
            }

            $isEligible = $this->checkEligibility($profile, $rules);

            if ($isEligible) {
                UserTaxDeduction::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'tax_deduction_id' => $deduction->id,
                        'tax_year' => $taxYear,
                    ],
                    [
                        'status' => 'eligible',
                        'estimated_amount' => $deduction->max_amount ? (float) $deduction->max_amount : null,
                        'detected_from' => $existing ? $existing->detected_from : 'profile_match',
                        'detection_confidence' => 0.7,
                        'notes' => 'Eligible based on your financial profile',
                    ]
                );
                $matched++;
            }
        }

        return ['matched' => $matched];
    }

    /**
     * Check if a user's profile matches eligibility rules.
     */
    protected function checkEligibility(UserFinancialProfile $profile, array $rules): bool
    {
        // Employment type check
        if (! empty($rules['employment_type'])) {
            $employmentType = $profile->employment_type;
            if (! in_array($employmentType, (array) $rules['employment_type'])) {
                return false;
            }
        }

        // Requires self-employed
        if (! empty($rules['requires_self_employed'])) {
            $selfEmployedTypes = ['self_employed', '1099_contractor', 'business_owner'];
            if (! in_array($profile->employment_type, $selfEmployedTypes)) {
                return false;
            }
        }

        // Requires home office
        if (! empty($rules['requires_home_office'])) {
            if (! $profile->has_home_office) {
                return false;
            }
        }

        // Filing status check
        if (! empty($rules['filing_status'])) {
            if (! in_array($profile->tax_filing_status, (array) $rules['filing_status'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the next batch of questionnaire questions for a user.
     */
    public function getQuestionnaire(User $user, int $taxYear, int $limit = 5): array
    {
        $answeredIds = UserTaxDeduction::whereIn('user_id', $user->householdUserIds())
            ->where('tax_year', $taxYear)
            ->pluck('tax_deduction_id');

        $questions = TaxDeduction::active()
            ->questionnaire()
            ->whereNotIn('id', $answeredIds)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn (TaxDeduction $d) => [
                'id' => $d->id,
                'slug' => $d->slug,
                'name' => $d->name,
                'category' => $d->category,
                'question_text' => $d->question_text,
                'question_options' => $d->question_options,
                'help_text' => $d->help_text,
                'is_credit' => $d->is_credit,
                'max_amount' => $d->max_amount ? (float) $d->max_amount : null,
                'irs_form' => $d->irs_form,
            ]);

        $totalRemaining = TaxDeduction::active()
            ->questionnaire()
            ->whereNotIn('id', $answeredIds)
            ->count();

        return [
            'questions' => $questions,
            'total_remaining' => $totalRemaining,
        ];
    }

    /**
     * Process a user's answer to a questionnaire question.
     */
    public function answerQuestion(User $user, TaxDeduction $deduction, int $taxYear, array $answer): UserTaxDeduction
    {
        $isEligible = $this->evaluateAnswer($answer);

        return UserTaxDeduction::updateOrCreate(
            [
                'user_id' => $user->id,
                'tax_deduction_id' => $deduction->id,
                'tax_year' => $taxYear,
            ],
            [
                'status' => $isEligible ? 'eligible' : 'not_eligible',
                'answer' => $answer,
                'estimated_amount' => $isEligible ? ($answer['amount'] ?? $deduction->max_amount) : null,
                'detected_from' => 'questionnaire',
                'detection_confidence' => 0.9,
            ]
        );
    }

    /**
     * Evaluate if an answer indicates eligibility.
     */
    protected function evaluateAnswer(array $answer): bool
    {
        $response = $answer['response'] ?? $answer['eligible'] ?? null;

        if (is_bool($response)) {
            return $response;
        }

        if (is_string($response)) {
            return in_array(strtolower($response), ['yes', 'true', '1']);
        }

        return false;
    }

    /**
     * Claim a deduction with an actual amount.
     */
    public function claimDeduction(User $user, TaxDeduction $deduction, int $taxYear, float $amount, ?string $notes = null): UserTaxDeduction
    {
        return UserTaxDeduction::updateOrCreate(
            [
                'user_id' => $user->id,
                'tax_deduction_id' => $deduction->id,
                'tax_year' => $taxYear,
            ],
            [
                'status' => 'claimed',
                'actual_amount' => round($amount, 2),
                'notes' => $notes,
            ]
        );
    }
}
