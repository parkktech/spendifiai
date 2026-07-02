<?php

use App\Enums\TaxDocumentCategory;

/**
 * Enum-additivity grep-gate for TaxDocumentCategory (DOC-01 / DOC-06).
 *
 * Assertions:
 *  1. All 25 pre-existing case values resolve via tryFrom() byte-identical to their original strings.
 *  2. All 18 new Phase 12 values resolve via tryFrom().
 *  3. label() returns a non-empty string for every case in self::cases() (no UnhandledMatchError).
 *  4. Total case count is now 43.
 */

// ─── Pre-existing cases (byte-identity regression) ───────────────────────────

it('resolves all 25 pre-existing enum cases via tryFrom() with unchanged string values', function () {
    $original = [
        'w2'                 => TaxDocumentCategory::W2,
        '1099_nec'           => TaxDocumentCategory::NEC_1099,
        '1099_int'           => TaxDocumentCategory::INT_1099,
        '1099_misc'          => TaxDocumentCategory::MISC_1099,
        '1099_div'           => TaxDocumentCategory::DIV_1099,
        '1098'               => TaxDocumentCategory::Mortgage_1098,
        'receipts'           => TaxDocumentCategory::Receipts,
        'other'              => TaxDocumentCategory::Other,
        '1099_b'             => TaxDocumentCategory::B_1099,
        '1099_r'             => TaxDocumentCategory::R_1099,
        '1099_g'             => TaxDocumentCategory::G_1099,
        '1099_k'             => TaxDocumentCategory::K_1099,
        '1099_s'             => TaxDocumentCategory::S_1099,
        '1099_sa'            => TaxDocumentCategory::SA_1099,
        '1099_c'             => TaxDocumentCategory::C_1099,
        '1098_e'             => TaxDocumentCategory::E_1098,
        '1098_t'             => TaxDocumentCategory::T_1098,
        'w2g'                => TaxDocumentCategory::W2G,
        'k1'                 => TaxDocumentCategory::K1,
        'ssa_1099'           => TaxDocumentCategory::SSA_1099,
        'rrb_1099'           => TaxDocumentCategory::RRB_1099,
        '5498_sa'            => TaxDocumentCategory::HSA_5498,
        '5498'               => TaxDocumentCategory::IRA_5498,
        'property_tax'       => TaxDocumentCategory::PropertyTax,
        'charitable_donation' => TaxDocumentCategory::CharitableDonation,
    ];

    foreach ($original as $value => $expectedCase) {
        $resolved = TaxDocumentCategory::tryFrom($value);
        expect($resolved)
            ->not->toBeNull("tryFrom('{$value}') returned null — existing case was removed or renamed")
            ->toBe($expectedCase, "tryFrom('{$value}') resolved to the wrong case");
    }

    expect(count($original))->toBe(25);
});

// ─── New Phase 12 financial cases (DOC-01 + DOC-07) ──────────────────────────

it('resolves all 6 new DOC-01 financial cases via tryFrom()', function () {
    $financial = [
        'pay_stub'             => TaxDocumentCategory::PayStub,
        'offer_letter'         => TaxDocumentCategory::OfferLetter,
        'retirement_statement' => TaxDocumentCategory::RetirementStatement,
        'stock_statement'      => TaxDocumentCategory::StockStatement,
        'insurance_doc'        => TaxDocumentCategory::InsuranceDoc,
    ];

    foreach ($financial as $value => $expectedCase) {
        $resolved = TaxDocumentCategory::tryFrom($value);
        expect($resolved)
            ->not->toBeNull("tryFrom('{$value}') returned null")
            ->toBe($expectedCase);
    }
});

it('resolves the DOC-07 benefits_guide case via tryFrom()', function () {
    expect(TaxDocumentCategory::tryFrom('benefits_guide'))
        ->not->toBeNull()
        ->toBe(TaxDocumentCategory::BenefitsGuide);
});

// ─── New Phase 12 substantiation cases (DOC-06) ───────────────────────────────

it('resolves all 12 new DOC-06 substantiation cases via tryFrom()', function () {
    $substantiation = [
        'sponsorship_agreement'     => TaxDocumentCategory::SponsorshipAgreement,
        'market_comp_memo'          => TaxDocumentCategory::MarketCompMemo,
        'physician_letter'          => TaxDocumentCategory::PhysicianLetter,
        'appraisal'                 => TaxDocumentCategory::Appraisal,
        'gallons_log'               => TaxDocumentCategory::GallonsLog,
        'rescue_org_letter'         => TaxDocumentCategory::RescueOrgLetter,
        'security_memo'             => TaxDocumentCategory::SecurityMemo,
        'loan_doc'                  => TaxDocumentCategory::LoanDoc,
        'contractor_invoice'        => TaxDocumentCategory::ContractorInvoice,
        'mileage_log'               => TaxDocumentCategory::MileageLog,
        'daycare_license'           => TaxDocumentCategory::DaycareLicense,
        'sponsorship_vendor_evidence' => TaxDocumentCategory::SponsorshipVendorEvidence,
    ];

    foreach ($substantiation as $value => $expectedCase) {
        $resolved = TaxDocumentCategory::tryFrom($value);
        expect($resolved)
            ->not->toBeNull("tryFrom('{$value}') returned null")
            ->toBe($expectedCase);
    }

    expect(count($substantiation))->toBe(12);
});

// ─── label() completeness (no UnhandledMatchError for any case) ───────────────

it('returns a non-empty string from label() for every case in self::cases()', function () {
    $cases = TaxDocumentCategory::cases();

    foreach ($cases as $case) {
        $label = $case->label();
        expect($label)
            ->toBeString()
            ->not->toBeEmpty("label() returned empty string for case {$case->value}");
    }
});

// ─── Total case count guard ───────────────────────────────────────────────────

it('has exactly 43 enum cases (25 pre-existing + 18 new Phase 12)', function () {
    expect(count(TaxDocumentCategory::cases()))->toBe(43);
});

// ─── forGrid() iterates all cases without error ───────────────────────────────

it('forGrid() returns 43 entries with non-empty value and label', function () {
    $grid = TaxDocumentCategory::forGrid();

    expect(count($grid))->toBe(43);

    foreach ($grid as $entry) {
        expect($entry['value'])->toBeString()->not->toBeEmpty();
        expect($entry['label'])->toBeString()->not->toBeEmpty();
    }
});
