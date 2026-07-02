<?php

namespace App\Services\Sweeps;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\RedFlagDetectorService;

/**
 * RecurringPayeeSweep — FLAG-11
 *
 * Sweeps recurring payees (via Subscription records — reuses SubscriptionDetectorService
 * normalized-merchant grouping) and routes them into modules:
 *
 *  ┌───────────────────────────────────────────────────────────────────┐
 *  │ Sweep module         │ Trigger category       │ Routed treatment   │
 *  ├───────────────────────────────────────────────────────────────────┤
 *  │ worker_classification│ Business Services /    │ warn-and-educate   │
 *  │                      │ Payroll / 1099         │ ONLY (never        │
 *  │                      │                        │ "reclassify them") │
 *  ├───────────────────────────────────────────────────────────────────┤
 *  │ childcare            │ Childcare / Education  │ dependent-care FSA │
 *  │                      │                        │ day-camp YES,      │
 *  │                      │                        │ overnight NO       │
 *  ├───────────────────────────────────────────────────────────────────┤
 *  │ tuition_loans        │ Education / Student    │ AOTC/LLC/§127/     │
 *  │                      │ Loan / Tuition         │ scholarship        │
 *  │                      │                        │ narrate-carefully  │
 *  ├───────────────────────────────────────────────────────────────────┤
 *  │ charitable           │ Charitable / Donation  │ mechanics-only,    │
 *  │                      │                        │ NO directive;      │
 *  │                      │                        │ appreciated-asset  │
 *  │                      │                        │ mention is "some   │
 *  │                      │                        │ donors give…"      │
 *  ├───────────────────────────────────────────────────────────────────┤
 *  │ storage_coworking    │ Storage / Coworking    │ business-use %     │
 *  │                      │                        │ allocation         │
 *  ├───────────────────────────────────────────────────────────────────┤
 *  │ insurance            │ Insurance              │ SE health / §105   │
 *  │                      │                        │ HRA spouse-plan    │
 *  └───────────────────────────────────────────────────────────────────┘
 *
 * BINDING REFRAMES (INTEGRATION-MAP §3 / 11-CONTEXT.md D10):
 *  - Charitable appreciated-asset education is MECHANICS-ONLY ("some donors give appreciated
 *    holdings…") with NO directive. Non-cash >$5K always pairs the qualified-appraisal
 *    checklist.
 *  - Scholarship-election carries the narrate-carefully flag.
 *  - Worker-classification is PURE warn-and-educate (never "reclassify them").
 *
 * SAFE-03: estimated_value_cents never assigned by this class.
 */
class RecurringPayeeSweep
{
    /** Subscription category keywords routing to each module */
    protected const MODULE_ROUTES = [
        'worker_classification' => [
            'Business Services', '1099', 'Contractor', 'Payroll', 'Consulting',
        ],
        'childcare' => [
            'Childcare', 'Daycare', 'Child Care', 'Preschool', 'Babysitting',
        ],
        'tuition_loans' => [
            'Education', 'Tuition', 'Student Loan', 'Student Loans', 'University', 'College',
        ],
        'charitable' => [
            'Charitable', 'Donation', 'Nonprofit', 'Non-profit', 'Charity',
        ],
        'storage_coworking' => [
            'Storage', 'Coworking', 'Co-working', 'Self Storage', 'Office Space',
        ],
        'insurance' => [
            'Insurance', 'Health Insurance', 'Life Insurance', 'Dental Insurance',
        ],
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

        $activeSubs = Subscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::Active)
            ->get(['id', 'merchant_name', 'merchant_normalized', 'category', 'amount', 'frequency', 'is_essential']);

        if ($activeSubs->isEmpty()) {
            return [];
        }

        foreach ($activeSubs as $sub) {
            $module = $this->detectModule($sub);
            if ($module === null) {
                continue;
            }

            $monthlyAmount = $this->toMonthlyCents($sub);
            if ($monthlyAmount < 1000) { // < $10/month — not material
                continue;
            }

            [$findingKey, $band, $treatment, $legalBasis, $docsMissing]
                = $this->buildModuleParams($module, $sub);

            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: $findingKey,
                findingType: 'recurring_payee_sweep',
                band: $band,
                treatment: $treatment,
                legalBasis: $legalBasis,
                ruleId: 'recurring_payee_'.$module,
                amountCents: $monthlyAmount,
                isRecurring: true,
                annualTotalCents: $monthlyAmount * 12,
                docsMissing: $docsMissing,
                electionFacts: $electionFacts,
            );

            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }

    // ── Module detection ──────────────────────────────────────────────────────

    protected function detectModule(Subscription $sub): ?string
    {
        $category = $sub->category ?? '';

        foreach (self::MODULE_ROUTES as $module => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($category, $keyword) !== false) {
                    return $module;
                }
            }
        }

        return null;
    }

    // ── Module parameter builders ─────────────────────────────────────────────

    /**
     * @return array{string, string, string, string, string[]}
     *                                                         [$findingKey, $band, $treatment, $legalBasis, $docsMissing]
     */
    protected function buildModuleParams(string $module, Subscription $sub): array
    {
        $name = trim($sub->merchant_name);

        return match ($module) {

            // ── Worker Classification (warn-and-educate ONLY) ─────────────
            'worker_classification' => [
                'recurring_payee_worker_classification',
                'conditional',
                "Recurring payments to {$name} may indicate payments to a contractor or worker. "
                .'If this person is an employee rather than an independent contractor, you may '
                .'have payroll tax obligations. Worker classification is determined by facts and '
                .'circumstances — a tax professional could review the relationship to understand '
                .'the applicable rules. Keeping documentation of the working arrangement is advisable.',
                'IRC §3401 (FICA); Rev. Rul. 87-41 (20-factor test); IRC §1402 (SE income)',
                ['1099_contractor'],
            ],

            // ── Childcare (dependent-care FSA; day camp YES, overnight NO) ──
            'childcare' => [
                'recurring_payee_childcare',
                'conditional',
                "Recurring payments to a dependent care provider like {$name} may qualify "
                .'for the Child and Dependent Care Credit (Form 2441) or for a Dependent Care FSA '
                .'if you have one through your employer. '
                .'Eligible dependent care expenses include childcare for children under 13, '
                .'preschool, before/after-school care, and day programs. '
                .'Overnight programs are not eligible for this credit. '
                .'The credit may offset 20%–35% of qualifying expenses up to the annual limit.',
                'IRC §21 (Child and Dependent Care Credit); IRC §129 (Dependent Care FSA)',
                [],
            ],

            // ── Tuition / Student Loans (narrate-carefully — AOTC/LLC/§127/scholarship) ─
            'tuition_loans' => [
                'recurring_payee_tuition_loans',
                'conditional',
                "Recurring payments to {$name} may qualify for an education tax benefit. "
                .'The American Opportunity Tax Credit (AOTC) may apply for the first four years '
                .'of higher education. The Lifetime Learning Credit (LLC) may apply in other years. '
                .'If your employer offers a §127 education assistance program, up to $5,250 may be '
                .'excluded from income. Student loan interest may be deductible up to $2,500 per year. '
                .'A tax professional could review which benefit applies to your situation.',
                'IRC §25A (AOTC / LLC); IRC §127 (employer education assistance); IRC §221 (student-loan interest $2,500)',
                [],
            ],

            // ── Charitable (mechanics-only, NO directive; appreciated-asset is "some donors…") ─
            'charitable' => [
                'recurring_payee_charitable',
                'conditional',
                "Recurring donations to {$name} may be deductible as charitable contributions "
                .'if the organization is a qualified 501(c)(3). '
                .'Bundling multiple years of donations into a single year (bunching) and using '
                .'a Donor-Advised Fund (DAF) in alternating years can help maximize itemized deductions. '
                .'Some donors give appreciated holdings directly to charities, which can provide '
                .'a deduction at fair market value while avoiding capital gains — a tax professional '
                .'could explain how this might work in your situation. '
                .'Keep written acknowledgment for any donation of $250 or more.',
                'IRC §170 (charitable contributions); Rev. Proc. 2023-34 (DAF); IRC §170(e) (appreciated property)',
                ['donation_receipts'],
            ],

            // ── Storage / Coworking (business-use allocation) ─────────────
            'storage_coworking' => [
                'recurring_payee_storage_coworking',
                'conditional',
                "Recurring payments to {$name} may qualify as a business expense "
                .'if the space is used for business storage or as a workspace. '
                .'You could deduct the business-use percentage of the cost as an ordinary '
                .'and necessary business expense on Schedule C or as a business expense.',
                'IRC §162 (ordinary and necessary business expenses)',
                ['business_use_log'],
            ],

            // ── Insurance (SE health / §105 HRA spouse-plan check) ────────
            'insurance' => [
                'recurring_payee_se_health_insurance',
                'conditional',
                "Recurring insurance premiums to {$name} may be deductible as self-employed "
                .'health insurance if you are self-employed and not eligible to participate '
                .'in an employer-subsidized plan. Self-employed individuals may deduct 100% '
                .'of health, dental, and vision premiums for themselves, their spouse, '
                .'and dependents. A tax professional could confirm your eligibility and '
                .'the correct deduction amount.',
                'IRC §162(l) (SE health insurance); IRC §105 (HRA)',
                [],
            ],

            default => [
                'recurring_payee_'.$module,
                'conditional',
                "Recurring payments to {$name} may have tax implications worth reviewing with a professional.",
                'IRC §162',
                [],
            ],
        };
    }

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
