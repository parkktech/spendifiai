<?php

namespace App\Services\Detectors;

use App\Models\TaxProfileEntity;
use App\Models\UserFinancialProfile;
use App\Models\UserTaxFact;
use App\Services\RedFlagDetectorService;

/**
 * DeductionProbeDetector — FLAG-05
 *
 * Implements the five prerequisite-gated deduction probes (home office, vehicle,
 * electronics, pet, meals). Each probe fires ONLY when its data prerequisite is
 * verified from real user data — never from assumptions.
 *
 * SCOPE BOUNDARY (TD-v1 §0.2, PLAN 11-06):
 *   Merchant-pattern enrichment (vehicle auto parts, pet supply, etc.) lands in
 *   FLAG-10 (Plan 11-07). This detector reads profile/entity/fact prerequisites only.
 *
 * LIABILITY BOUNDARIES (D10, locked):
 *   - Every probe emits a question-only finding with a docs checklist.
 *   - "May / could / consider" framing throughout — never assert deductibility.
 *   - Pet probe is specialist band: question + pro routing ONLY. Never "your dog IS deductible."
 *   - Vehicle probe defers to method-election guard: standard-mileage election suppresses
 *     vehicle_actual_expense (flag handled by harness checkMethodConflictGuards).
 *
 * SAFE-03: No estimated_value_cents is assigned by this class.
 */
class DeductionProbeDetector
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

        $profile = UserFinancialProfile::where('user_id', $userId)->first();

        // ── Probe 1: Home Office ──────────────────────────────────────────────
        // Prerequisite: UserFinancialProfile.has_home_office === true
        // OR durable fact 'probe.has_home_office' === 'true'
        if ($this->homeOfficePrerequisiteMet($userId, $profile)) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'deduction_home_office',
                findingType: 'deduction_probe',
                band: 'conditional',
                treatment: 'You may be eligible to deduct a portion of your home expenses '
                    . 'if you use part of it exclusively and regularly for business. '
                    . 'Consider measuring the space and choosing between the simplified '
                    . '($5/sq ft, up to $1,500) or regular method with a tax professional.',
                legalBasis: 'IRC §280A; Rev. Proc. 2013-13 (simplified method)',
                ruleId: 'deduction_home_office',
                docsMissing: ['home_office_measurement'],
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 2: Vehicle ──────────────────────────────────────────────────
        // Prerequisite: a vehicle TaxProfileEntity exists for this user
        // Note: standard-mileage method-conflict guard runs in the harness after emit
        if ($this->vehiclePrerequisiteMet($userId)) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'deduction_vehicle',
                findingType: 'deduction_probe',
                band: 'conditional',
                treatment: 'If you use a vehicle for business, you may be able to deduct '
                    . 'mileage (at the standard rate) or actual vehicle expenses, depending '
                    . 'on which method you elect. Consider keeping a contemporaneous mileage log '
                    . 'and consulting a professional to compare methods.',
                legalBasis: 'IRC §179; IRC §274; Rev. Proc. 2025-33 (72.5¢/mi 2026)',
                ruleId: 'deduction_vehicle',
                docsMissing: ['mileage_log', 'vehicle_purchase_date'],
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 3: Electronics (§179 eligible) ─────────────────────────────
        // Prerequisite: durable fact 'probe.has_business_electronics' === 'true'
        // OR UserFinancialProfile employment indicates self-employment / business
        if ($this->electronicsPrerequisiteMet($userId, $profile)) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'deduction_electronics',
                findingType: 'deduction_probe',
                band: 'conditional',
                treatment: 'Business computers, tablets, phones, and other listed property '
                    . 'used more than 50% for business may be deductible under §179 or '
                    . 'bonus depreciation. You may want to document the business-use percentage '
                    . 'and keep purchase records.',
                legalBasis: 'IRC §179; IRC §168(k) (bonus depreciation); listed-property rules',
                ruleId: 'deduction_electronics',
                docsMissing: ['business_use_log', 'section_179_election'],
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 4: Pet (guard dog / working animal) ─────────────────────────
        // Prerequisite: durable fact 'probe.pet_business_use' === 'true'
        // Specialist band: question + pro routing ONLY. "Your dog IS deductible" is locked
        // out-of-scope (gray-area constraint; INTEGRATION-MAP Blocked section note).
        if ($this->petPrerequisiteMet($userId)) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'deduction_pet',
                findingType: 'deduction_probe',
                band: 'specialist',
                treatment: 'If an animal serves a genuine business purpose (guarding a business '
                    . 'premises, working on a farm, fostering for a rescue organization), '
                    . 'certain costs may be deductible. This is a gray-area that requires '
                    . 'professional review — please consult a CPA with your specific situation.',
                legalBasis: 'IRC §162 (guard-dog / working-animal); IRC §170 (*Van Dusen* foster flow)',
                ruleId: 'deduction_pet',
                docsMissing: ['donation_receipts'],
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        // ── Probe 5: Business Meals ───────────────────────────────────────────
        // Prerequisite: durable fact 'probe.has_business_meals' === 'true'
        // OR employment type indicates self-employment / business
        if ($this->mealsPrerequisiteMet($userId, $profile)) {
            $key = $service->registerFinding(
                userId: $userId,
                taxYear: $taxYear,
                findingKey: 'deduction_meals',
                findingType: 'deduction_probe',
                band: 'conditional',
                treatment: 'Ordinary and necessary business meals with clients or colleagues '
                    . 'may be 50% deductible. Consider keeping a log with the business purpose, '
                    . 'attendees, and amount for each meal to support a deduction.',
                legalBasis: 'IRC §274 (50% business-meal limit); skills-maintenance vs new-trade distinction',
                ruleId: 'deduction_meals',
                docsMissing: ['business_use_log'],
                electionFacts: $electionFacts,
            );
            if ($key !== null) {
                $emitted[] = $key;
            }
        }

        return $emitted;
    }

    // ── Prerequisite checks ───────────────────────────────────────────────────

    private function homeOfficePrerequisiteMet(int $userId, ?UserFinancialProfile $profile): bool
    {
        if ($profile && $profile->has_home_office === true) {
            return true;
        }
        $fact = UserTaxFact::currentFact($userId, 'probe.has_home_office');

        return $fact && in_array(strtolower((string) $fact->value), ['true', '1', 'yes'], true);
    }

    private function vehiclePrerequisiteMet(int $userId): bool
    {
        return TaxProfileEntity::where('user_id', $userId)
            ->where('entity_type', 'vehicle')
            ->where('is_current', true)
            ->exists();
    }

    private function electronicsPrerequisiteMet(int $userId, ?UserFinancialProfile $profile): bool
    {
        $fact = UserTaxFact::currentFact($userId, 'probe.has_business_electronics');
        if ($fact && in_array(strtolower((string) $fact->value), ['true', '1', 'yes'], true)) {
            return true;
        }

        // Self-employed or business-type users are likely to have business electronics
        if ($profile && in_array($profile->employment_type, ['self_employed', 'freelancer'], true)) {
            return true;
        }

        return false;
    }

    private function petPrerequisiteMet(int $userId): bool
    {
        $fact = UserTaxFact::currentFact($userId, 'probe.pet_business_use');

        return $fact && in_array(strtolower((string) $fact->value), ['true', '1', 'yes'], true);
    }

    private function mealsPrerequisiteMet(int $userId, ?UserFinancialProfile $profile): bool
    {
        $fact = UserTaxFact::currentFact($userId, 'probe.has_business_meals');
        if ($fact && in_array(strtolower((string) $fact->value), ['true', '1', 'yes'], true)) {
            return true;
        }

        // Self-employed users with business type are likely to have business meals
        if ($profile && in_array($profile->employment_type, ['self_employed', 'freelancer'], true)
            && ! empty($profile->business_type)) {
            return true;
        }

        return false;
    }
}
