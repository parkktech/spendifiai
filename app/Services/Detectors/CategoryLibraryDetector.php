<?php

namespace App\Services\Detectors;

use App\Models\DetectionMerchant;
use App\Models\Transaction;
use App\Services\RedFlagDetectorService;
use Carbon\Carbon;

/**
 * CategoryLibraryDetector — FLAG-10
 *
 * Implements the transaction-plane category library (TD-v1 §§1–8):
 *  vehicle / powersports, solar/energy, pool/spa, landscaping/hardscape,
 *  home-improvement, animals/security, medical HSA-first, travel cluster.
 *
 * DESIGN RULES (non-negotiable per 11-CONTEXT.md D10):
 *  1. Every gray-area module surfaces ONLY:
 *     (a) a question — treatment text uses "may / could / consider" framing
 *     (b) a documentation checklist — docs_missing array
 *     (c) a static config-sourced defensibility rating — from DetectionMerchant.defensibility_rating
 *     (d) pro-review routing — band=specialist for highest-stakes gray areas
 *     NEVER a deduction assertion.
 *  2. Gambling merchants are registered signals only (band=suppress via rule_id);
 *     gambling_losses_fully_deductible NEVER emits a positive finding.
 *  3. Fuel-credit pathway: pro_export_ready stays false until gallons log captured
 *     (docs_missing blocks it — top fraud flag when unsupported, per TD-v1 §1).
 *  4. Batching by pattern (INT-06): one merchant pattern = ONE finding, applied
 *     retroactively to all matched transaction_ids[].
 *  5. No dollar arithmetic here — all numbers come from config/engine (SAFE-03).
 *
 * SCOPE BOUNDARY: This detector enriches the merchant-pattern layer.
 * Profile/entity prerequisites are handled by DeductionProbeDetector (Plan 11-06).
 */
class CategoryLibraryDetector
{
    /**
     * @param  array<string, string>  $electionFacts  Preloaded method-election facts
     * @return string[] Finding keys emitted
     */
    public function run(
        int $userId,
        int $taxYear,
        RedFlagDetectorService $service,
        array $electionFacts
    ): array {
        $emitted = [];

        // ── Load all detection merchants from the knowledge table ────────────
        $allMerchants = DetectionMerchant::all();
        if ($allMerchants->isEmpty()) {
            return [];
        }

        // ── Load user transactions for the tax year (+ 2 prior years for retro) ─
        $startDate = Carbon::create($taxYear - 1, 1, 1);
        $endDate = Carbon::create($taxYear, 12, 31);

        $transactions = Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->where('amount', '>', 0)  // debits only
            ->get(['id', 'merchant_normalized', 'amount', 'transaction_date', 'is_subscription']);

        if ($transactions->isEmpty()) {
            return [];
        }

        // ── Group user transactions by normalized merchant name ──────────────
        $txByMerchant = $transactions->groupBy('merchant_normalized');

        // ── Match against detection merchant knowledge table ─────────────────
        // For each merchant group, find matching DetectionMerchant rows
        $matchedGroups = []; // subdetector_key → ['merchant' => DM, 'txs' => Collection]

        foreach ($txByMerchant as $normalizedName => $txs) {
            foreach ($allMerchants as $dm) {
                if ($dm->matchesNormalizedName($normalizedName)) {
                    $key = $dm->subdetector_key;
                    if (! isset($matchedGroups[$key])) {
                        $matchedGroups[$key] = ['merchant' => $dm, 'txs' => collect()];
                    }
                    $matchedGroups[$key]['txs'] = $matchedGroups[$key]['txs']->merge($txs);
                    break; // one match per normalized name per group
                }
            }
        }

        if (empty($matchedGroups)) {
            return [];
        }

        // ── Emit one finding per matched subdetector group ───────────────────
        foreach ($matchedGroups as $subdetectorKey => $group) {
            /** @var DetectionMerchant $dm */
            $dm = $group['merchant'];
            $txs = $group['txs'];

            $txIds = $txs->pluck('id')->toArray();
            $annualTotal = (int) round($txs->sum('amount') * 100); // cents
            $maxSingle = (int) round($txs->max('amount') * 100); // cents
            $isRecurring = $txs->where('is_subscription', true)->isNotEmpty()
                || $txs->count() >= 3;

            // ── Gambling signals: suppress band — registerFinding will suppress ─
            // (gambling_losses_fully_deductible rule has band=suppress)
            if ($dm->category === 'gambling') {
                // Pass to registerFinding with the suppress-band rule_id; it will suppress.
                // This is the correct mechanism — we don't short-circuit here so the
                // harness's validateRule() enforcement is the single suppression point.
                $service->registerFinding(
                    userId: $userId,
                    taxYear: $taxYear,
                    findingKey: $dm->rule_id ?? 'gambling_losses_fully_deductible',
                    findingType: 'category_library',
                    band: 'suppress',
                    treatment: '',
                    legalBasis: '',
                    ruleId: 'gambling_losses_fully_deductible',
                    amountCents: $maxSingle,
                    isRecurring: $isRecurring,
                    annualTotalCents: $annualTotal,
                    transactionIds: $txIds,
                    electionFacts: $electionFacts,
                );

                continue; // never appended to $emitted (registerFinding returns null)
            }

            // ── Build finding parameters from the merchant knowledge row ─────
            [$findingKey, $findingType, $band, $treatment, $legalBasis, $docsMissing]
                = $this->buildFindingParams($dm, $txs);

            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: $findingKey,
                findingType: $findingType,
                band: $band,
                treatment: $treatment,
                legalBasis: $legalBasis,
                ruleId: $dm->rule_id,
                amountCents: $maxSingle,
                isRecurring: $isRecurring,
                annualTotalCents: $annualTotal,
                transactionIds: $txIds,
                docsMissing: $docsMissing,
                electionFacts: $electionFacts,
            );

            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }

    // ── Finding parameter builders by category ────────────────────────────────

    /**
     * Map a DetectionMerchant row to finding parameters.
     *
     * Returns [$findingKey, $findingType, $band, $treatment, $legalBasis, $docsMissing[]].
     * All treatment strings use "may / could / consider" framing — never assertive.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $txs
     * @return array{string, string, string, string, string, string[]}
     */
    protected function buildFindingParams(DetectionMerchant $dm, $txs): array
    {
        $band = $dm->gray_area ? 'specialist' : ($dm->defensibility_rating === 'conditional' ? 'conditional' : 'specialist');

        return match ($dm->category) {
            'vehicle' => $this->vehicleParams($dm),
            'solar_energy' => $this->solarParams($dm),
            'pool_spa' => $this->poolSpaParams($dm),
            'landscaping' => $this->landscapingParams($dm),
            'home_improvement' => $this->homeImprovementParams($dm),
            'animals_security' => $this->animalsParams($dm),
            'medical' => $this->medicalParams($dm),
            default => $this->genericParams($dm),
        };
    }

    private function vehicleParams(DetectionMerchant $dm): array
    {
        $isOffRoad = $dm->subdetector_key === 'offroad_vehicle';

        $treatment = 'Purchases at '.$dm->company_name.' may relate to a business vehicle. '
            .'If you use a vehicle for business, you may be able to deduct vehicle expenses '
            .'using the standard mileage method or actual expense method. '
            .'A contemporaneous mileage log and vehicle records could support either approach. '
            .'A tax professional could help you compare methods and determine eligibility.';

        if ($isOffRoad) {
            $treatment .= ' If equipment is used off-road for business (shop, ranch, construction), '
                .'a fuel tax credit may apply — a gallons log is required and is a top fraud flag '
                .'when unsupported.';
        }

        $docsMissing = ['mileage_log', 'vehicle_purchase_date', 'business_use_log'];
        if ($isOffRoad) {
            $docsMissing[] = 'gallons_log';
        }

        return [
            'category_vehicle_parts',
            'category_library',
            'conditional',
            $treatment,
            'IRC §179; IRC §274; Rev. Proc. 2025-33 (standard mileage); §162 vehicle operating costs',
            $docsMissing,
        ];
    }

    private function solarParams(DetectionMerchant $dm): array
    {
        return [
            'category_solar',
            'category_library',
            'conditional',
            'Payments to '.$dm->company_name.' may indicate a solar energy system installation or loan. '
                .'If the system was paid for or turned on before December 31, 2025 on a primary or '
                .'second home, you may be eligible for the §25D residential clean energy credit. '
                .'If the system is on a rental property, it may qualify for 5-year MACRS depreciation. '
                .'A tax professional could review your situation and identify any credits that may apply '
                .'to prior returns (3-year amended-return window).',
            'IRC §25D (expired 2025-12-31 for primary homes); MACRS 5-year for rental solar',
            ['solar_invoice'],
        ];
    }

    private function poolSpaParams(DetectionMerchant $dm): array
    {
        return [
            'category_pool_spa',
            'category_library',
            'specialist', // gray_area=true → always specialist for medical module
            'Purchases at '.$dm->company_name.' may relate to a pool or spa. '
                .'If the pool is at a rental property, it may qualify as a 15-year land improvement '
                .'eligible for bonus depreciation. If at your primary home, it may add to your home basis. '
                .'If a licensed physician recommended this for a diagnosed medical condition, '
                .'there may be a medical deduction pathway — a tax professional could evaluate '
                .'your specific situation and whether the cost exceeds the 7.5% AGI floor. '
                .'This is a gray-area that requires professional review.',
            'IRC §168 (15-yr land improvement); IRC §213 (medical, 7.5% AGI floor); IRC §121 (basis)',
            ['rx_letter', 'contractor_invoices', 'loan_statements'],
        ];
    }

    private function landscapingParams(DetectionMerchant $dm): array
    {
        return [
            'category_landscaping',
            'category_library',
            'conditional',
            'Purchases at '.$dm->company_name.' may relate to landscaping or hardscaping. '
                .'If the work is at a rental property, repairs may be deductible now while '
                .'improvements may qualify as 15-year land improvements eligible for bonus depreciation. '
                .'If at your primary home, capital improvements (walls, turf installs, irrigation) '
                .'may add to your home basis. A tax professional could help determine the treatment.',
            'IRC §168 (15-yr land improvement); IRC §121 (basis); IRC §162 (business grounds)',
            ['contractor_invoices'],
        ];
    }

    private function homeImprovementParams(DetectionMerchant $dm): array
    {
        $isBusinessSkew = $dm->subdetector_key === 'home_improvement_business';

        $treatment = 'Purchases at '.$dm->company_name.' could apply to your home, rental property, '
            .'home office, or business — the destination determines the tax treatment. '
            .'"Was this for: my home / my shop or business / a rental property / my home office?" '
            .'could determine whether these expenses are deductible, add to property basis, '
            .'or qualify for business depreciation.';

        if ($isBusinessSkew) {
            $treatment .= ' Purchases at '.$dm->company_name.' often skew heavily toward '
                .'business/fabrication use — if coinciding with a development project, '
                .'supply costs may be research and development credit candidates.';
        }

        return [
            'category_home_improvement_destination',
            'category_library',
            'conditional',
            $treatment,
            'IRC §179 (business improvement); IRC §168 (depreciation); IRC §121 (home basis)',
            ['contractor_invoices', 'business_use_log'],
        ];
    }

    private function animalsParams(DetectionMerchant $dm): array
    {
        return [
            'category_pet_animals',
            'category_library',
            'specialist',
            'Purchases at '.$dm->company_name.' may relate to a pet or animal. '
                .'If an animal serves a genuine business purpose — guarding a business '
                .'premises or working on a farm — certain costs may be deductible. '
                .'If you foster animals for a rescue organization, foster costs may qualify '
                .'as a charitable deduction under the *Van Dusen* line of cases (org letter required for $250+). '
                .'These are gray-area situations that require professional review — '
                .'please consult a CPA with your specific situation.',
            'IRC §162 (guard-dog / working-animal §162); IRC §170 (*Van Dusen* foster flow)',
            ['donation_receipts'],
        ];
    }

    private function medicalParams(DetectionMerchant $dm): array
    {
        return [
            'category_medical_hsa_first',
            'category_library',
            'conditional',
            'Payments to '.$dm->company_name.' may be for medical expenses. '
                .'If you have an HSA, out-of-pocket medical costs can be reimbursed tax-free '
                .'in any future year with receipts (HSA shoebox). '
                .'If you do not use an HSA, medical expenses exceeding 7.5% of your AGI '
                .'may be deductible. Tracking these expenses now preserves your options.',
            'IRC §223 (HSA); IRC §213 (medical deduction, 7.5% AGI floor)',
            [],
        ];
    }

    private function genericParams(DetectionMerchant $dm): array
    {
        return [
            'category_'.$dm->subdetector_key,
            'category_library',
            'conditional',
            'Purchases at '.$dm->company_name.' may have business or investment tax implications. '
                .'A tax professional could review whether these expenses qualify for any deduction '
                .'or credit in your situation.',
            'IRC §162 (ordinary and necessary business expenses)',
            [],
        ];
    }
}
