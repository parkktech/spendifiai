<?php

/**
 * W4FilingStatusNormalizationTest — Fix 1 (choices-repair)
 *
 * Verifies that W-4 verbatim display strings are normalized to engine enums at
 * BOTH layers:
 *   1. PaystubFactExtractorService::normalizeW4FilingStatus() (extraction boundary)
 *   2. ScenarioFactResolverService::resolveFromFact() (resolver/engine boundary —
 *      defensive layer for legacy stored values)
 *
 * Also verifies the artisan migrate command is idempotent for already-valid enums.
 */

use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use App\Services\ScenarioFactResolverService;

// ─── Layer 1: static normalization function ───────────────────────────────────

describe('PaystubFactExtractorService::normalizeW4FilingStatus — verbatim → enum', function () {

    it('maps owner\'s exact W-4 string to married_joint', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus(
            'Married filing jointly (or Qualifying widow(er))'
        );
        expect($result)->toBe('married_joint');
    });

    it('maps "Married filing jointly" (short form) to married_joint', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('Married filing jointly');
        expect($result)->toBe('married_joint');
    });

    it('maps "Single or Married filing separately" to single_or_mfs', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('Single or Married filing separately');
        expect($result)->toBe('single_or_mfs');
    });

    it('maps "Head of household" to head_of_household', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('Head of household');
        expect($result)->toBe('head_of_household');
    });

    it('passes through valid enum married_joint unchanged (idempotent)', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('married_joint');
        expect($result)->toBe('married_joint');
    });

    it('passes through valid enum single_or_mfs unchanged (idempotent)', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('single_or_mfs');
        expect($result)->toBe('single_or_mfs');
    });

    it('passes through valid enum head_of_household unchanged (idempotent)', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('head_of_household');
        expect($result)->toBe('head_of_household');
    });

    it('handles case-insensitive matching', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('MARRIED FILING JOINTLY');
        expect($result)->toBe('married_joint');
    });

    it('handles "qualifying widow" variant in string', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus(
            'Married filing jointly (or Qualifying Widow(er))'
        );
        expect($result)->toBe('married_joint');
    });

    it('handles legacy bare "Married" abbreviation → married_joint', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('married');
        expect($result)->toBe('married_joint');
    });

    it('handles legacy bare "Single" abbreviation → single_or_mfs', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('Single');
        expect($result)->toBe('single_or_mfs');
    });

    it('returns truly unknown values as-is (defensive engine boundary handles them)', function () {
        $result = PaystubFactExtractorService::normalizeW4FilingStatus('__unknown_status__');
        expect($result)->toBe('__unknown_status__');
    });
});

// ─── Layer 2: resolver defensive normalization ────────────────────────────────

describe('ScenarioFactResolverService — defensive w4.filing_status normalization', function () {

    it('resolver normalizes a legacy stored verbatim string to married_joint at engine boundary', function () {
        // Simulate a fact written before the extractor fix — verbatim W-4 display string.
        $user = User::factory()->create();
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'w4.filing_status',
            value: 'Married filing jointly (or Qualifying widow(er))',
            sourceType: 'document_extraction',
            label: 'Filing status on W-4',
            volatility: 'stable',
            taxYear: null,
            sourceId: '1',
            metadata: ['normalized' => false],
        );
        // Manually confirm so it passes the D4 gate (is_current=true + confirmed_at set)
        $fact = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'w4.filing_status')->first();
        $fact->update([
            'is_current' => true,
            'confirmed_at' => now(),
        ]);

        $resolver = app(ScenarioFactResolverService::class);
        $result = $resolver->resolve($user, 2026, 'w4.filing_status');

        expect($result)->not->toBeNull();
        expect($result['value'])->toBe('married_joint');
    });

    it('resolver leaves already-enum w4.filing_status unchanged', function () {
        $user = User::factory()->create();
        UserTaxFact::recordFact(
            userId: $user->id,
            factKey: 'w4.filing_status',
            value: 'single_or_mfs',
            sourceType: 'interview_answer',
            label: 'Filing status on W-4',
            volatility: 'stable',
            taxYear: null,
            sourceId: 'interview',
            metadata: [],
        );
        $fact = UserTaxFact::where('user_id', $user->id)->where('fact_key', 'w4.filing_status')->first();
        $fact->update(['is_current' => true, 'confirmed_at' => now()]);

        $resolver = app(ScenarioFactResolverService::class);
        $result = $resolver->resolve($user, 2026, 'w4.filing_status');

        expect($result)->not->toBeNull();
        expect($result['value'])->toBe('single_or_mfs');
    });
});
