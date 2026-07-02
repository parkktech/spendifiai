<?php

namespace App\Services\Detectors;

use App\Enums\AccountPurpose;
use App\Enums\ExpenseType;
use App\Models\Transaction;
use App\Services\RedFlagDetectorService;

/**
 * ComminglingMonitor — FLAG-14
 *
 * Flags personal-type spend (expense_type=personal) inside AccountPurpose::Business
 * bank accounts during the tax year. Emits an educational finding with locked wording.
 *
 * LOCKED WORDING (INTEGRATION-MAP FLAG-14, D10 — verbatim):
 *   "Business owners commonly keep a separate account for business activity;
 *    it is the single most effective record in a hobby-loss review."
 *
 * LIABILITY BOUNDARY:
 *   - Warn-and-educate ONLY. NEVER "you qualify as a business."
 *   - No dollar amounts in the finding (SAFE-03).
 *   - Findings are informational; no action is taken on transactions.
 *
 * SAFE-03: No estimated_value_cents is assigned by this class.
 */
class ComminglingMonitor
{
    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[]  Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // Detect personal-type transactions on business-purpose accounts during the tax year
        $hasCommingling = Transaction::where('user_id', $userId)
            ->where('account_purpose', AccountPurpose::Business)
            ->where('expense_type', ExpenseType::Personal)
            ->whereYear('transaction_date', $taxYear)
            ->exists();

        if (! $hasCommingling) {
            return $emitted;
        }

        // Commingling detected — emit finding with LOCKED wording (FLAG-14)
        $key = $service->registerFinding(
            userId: $userId,
            taxYear: $taxYear,
            findingKey: 'commingling_detected',
            findingType: 'commingling',
            band: 'conditional',
            // Locked treatment wording — verbatim from INTEGRATION-MAP FLAG-14
            treatment: 'Business owners commonly keep a separate account for business activity; '
                . 'it is the single most effective record in a hobby-loss review. '
                . 'Consider moving personal transactions to a personal account to keep '
                . 'your business records clean and defensible.',
            legalBasis: 'IRS hobby-loss 9-factor test; IRC §183 (activities not engaged in for profit)',
            ruleId: 'commingling_detected',
            electionFacts: $electionFacts,
        );

        if ($key !== null) {
            $emitted[] = $key;
        }

        return $emitted;
    }
}
