<?php

namespace App\Services\Detectors;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\RedFlagDetectorService;

/**
 * DeductibleSaasSweep — FLAG-07 (promoted into Phase 11 per D5/11-CONTEXT.md)
 *
 * Sweeps existing Subscription records for likely-deductible SaaS and surfaces
 * them as PURE EDUCATIONAL findings. Reuses the Subscription table — no new
 * merchant-grouping logic is implemented here.
 *
 * DESIGN RULES:
 *  1. Surfaces from Subscription records only (FLAG-07 rides FLAG-11 infra).
 *  2. Educational framing: "may be deductible" — never asserts deductibility.
 *  3. Cancelled subscriptions are excluded (not currently active → no action needed).
 *  4. Zero new Claude calls; zero HTTP; zero dollar computation.
 *  5. SAFE-03: estimated_value_cents never assigned.
 */
class DeductibleSaasSweep
{
    /** Subscription categories that typically indicate SaaS / software subscriptions */
    protected const SAAS_CATEGORIES = [
        'Software',
        'Productivity',
        'Development',
        'Cloud Services',
        'Business Software',
        'Design & Creative',
        'Communication',
        'Analytics',
        'Project Management',
    ];

    /**
     * @param  array<string, string>  $electionFacts
     * @return string[] Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // Query active Software-category subscriptions for this user
        $saasSubs = Subscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::Active)
            ->where(function ($q) {
                $q->whereIn('category', self::SAAS_CATEGORIES)
                    ->orWhere('category', 'LIKE', '%Software%')
                    ->orWhere('category', 'LIKE', '%SaaS%');
            })
            ->get(['id', 'merchant_name', 'merchant_normalized', 'category', 'amount', 'frequency']);

        if ($saasSubs->isEmpty()) {
            return [];
        }

        foreach ($saasSubs as $sub) {
            $monthlyAmount = $this->toMonthlyCents($sub);
            if ($monthlyAmount === 0) {
                continue;
            }

            // Finding key per-subscription (normalized merchant slug)
            $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($sub->merchant_normalized ?? $sub->merchant_name));
            $findingKey = 'deductible_saas_'.$slug;

            $treatment = trim($sub->merchant_name).' is an active software subscription '
                .'that may be deductible as an ordinary and necessary business expense '
                .'if used in your business or self-employment activity. '
                .'Consider reviewing whether this subscription is used for business purposes '
                .'and keeping records to support a deduction on Schedule C or as a business expense.';

            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: $findingKey,
                findingType: 'deductible_saas',
                band: 'conditional',
                treatment: $treatment,
                legalBasis: 'IRC §162 (ordinary and necessary business expenses)',
                ruleId: 'deductible_saas_sweep',
                // SaaS subscriptions already passed the SubscriptionDetectorService threshold.
                // Skip the general materiality gate here (pass amountCents=0, isRecurring=false)
                // to avoid double-gating. The subscription detector is the right filter.
                amountCents: 0,
                isRecurring: false,
                annualTotalCents: $monthlyAmount * 12,
                electionFacts: $electionFacts,
            );

            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }

    /**
     * Convert subscription amount to monthly cents.
     */
    protected function toMonthlyCents(Subscription $sub): int
    {
        $amount = (float) $sub->amount;
        if ($amount <= 0) {
            return 0;
        }

        return match ($sub->frequency) {
            'weekly' => (int) round($amount * 4.33 * 100),
            'monthly' => (int) round($amount * 100),
            'quarterly' => (int) round(($amount / 3) * 100),
            'annual' => (int) round(($amount / 12) * 100),
            default => (int) round($amount * 100),
        };
    }
}
