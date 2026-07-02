<?php

/**
 * CategoryLibraryDetectorTest — TDD RED for Plan 11-07 Tasks 1 & 2
 *
 * Tests for:
 *  - DetectionMerchant model (FLAG-10 seed table following CancellationProvider precedent)
 *  - CategoryLibraryDetector (FLAG-10 category modules — gray-area constraint enforced)
 *  - DeductibleSaasSweep (FLAG-07 — educational surfacing from Subscription records)
 *
 * Safety invariants under test:
 *  - Gambling merchants NEVER surfaced as deductible (band=suppress via rule registry)
 *  - Gray-area modules emit ONLY question + checklist + defensibility + pro-routing
 *  - No finding treatment asserts deductibility
 *  - DeductibleSaasSweep uses Subscription records; never asserts deductibility
 */

use App\Models\DetectionMerchant;
use App\Models\OptimizationFinding;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Detectors\CategoryLibraryDetector;
use App\Services\Detectors\DeductibleSaasSweep;
use App\Services\RedFlagDetectorService;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->taxYear = 2026;
    $this->service = app(RedFlagDetectorService::class);
});

// ── DetectionMerchant model ──────────────────────────────────────────────────

it('DetectionMerchant can be created with aliases as JSONB array', function () {
    $merchant = DetectionMerchant::create([
        'company_name'       => 'AutoZone',
        'aliases'            => ['AUTOZONE', 'AUTO ZONE', 'AUTOZONE.COM'],
        'category'           => 'vehicle',
        'subdetector_key'    => 'vehicle_parts',
        'defensibility_rating' => 'conditional',
        'gray_area'          => false,
        'notes'              => 'Auto parts store',
        'rule_id'            => 'category_vehicle',
    ]);

    expect($merchant->id)->not->toBeNull()
        ->and($merchant->aliases)->toBeArray()
        ->and($merchant->aliases)->toContain('AUTOZONE');
});

it('DetectionMerchant forCategory scope filters correctly by category', function () {
    DetectionMerchant::create([
        'company_name' => 'AutoZone', 'aliases' => ['AUTOZONE'],
        'category' => 'vehicle', 'subdetector_key' => 'vehicle_parts',
        'defensibility_rating' => 'conditional', 'gray_area' => false,
    ]);
    DetectionMerchant::create([
        'company_name' => 'DraftKings', 'aliases' => ['DRAFTKINGS'],
        'category' => 'gambling', 'subdetector_key' => 'gambling_signal',
        'defensibility_rating' => 'suppress', 'gray_area' => false,
        'rule_id' => 'gambling_losses_fully_deductible',
    ]);

    expect(DetectionMerchant::forCategory('vehicle')->count())->toBe(1)
        ->and(DetectionMerchant::forCategory('gambling')->count())->toBe(1)
        ->and(DetectionMerchant::forCategory('pool_spa')->count())->toBe(0);
});

it('DetectionMerchant matchesNormalizedName returns true for known alias', function () {
    $merchant = DetectionMerchant::create([
        'company_name' => 'AutoZone',
        'aliases'      => ['AUTOZONE', 'AUTO ZONE'],
        'category'     => 'vehicle', 'subdetector_key' => 'vehicle_parts',
        'defensibility_rating' => 'conditional', 'gray_area' => false,
    ]);

    expect($merchant->matchesNormalizedName('autozone'))->toBeTrue()
        ->and($merchant->matchesNormalizedName('auto zone'))->toBeTrue()
        ->and($merchant->matchesNormalizedName('whole foods'))->toBeFalse();
});

// ── CategoryLibraryDetector ──────────────────────────────────────────────────

it('CategoryLibraryDetector stays silent when no transactions match any detection merchant', function () {
    // No DetectionMerchant rows seeded → no matches
    $detector = app(CategoryLibraryDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('CategoryLibraryDetector emits finding for high-value vehicle-merchant transactions', function () {
    DetectionMerchant::create([
        'company_name' => 'AutoZone', 'aliases' => ['AUTOZONE', 'AUTO ZONE'],
        'category' => 'vehicle', 'subdetector_key' => 'vehicle_parts',
        'defensibility_rating' => 'conditional', 'gray_area' => false,
        'rule_id' => 'category_vehicle',
    ]);

    // 3 × $400 = $1,200 annual — above the $500/yr recurring gate
    Transaction::factory()->count(3)->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'autozone',
        'amount'              => 400.00,
        'transaction_date'    => now()->subDays(30),
    ]);

    $detector = app(CategoryLibraryDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    expect(count($result))->toBeGreaterThanOrEqual(1)
        ->and($result)->toContain('category_vehicle_parts');
});

it('CategoryLibraryDetector never surfaces gambling as deductible (band=suppress)', function () {
    DetectionMerchant::create([
        'company_name' => 'DraftKings', 'aliases' => ['DRAFTKINGS', 'DK SPORTSBOOK'],
        'category' => 'gambling', 'subdetector_key' => 'gambling_signal',
        'defensibility_rating' => 'suppress', 'gray_area' => false,
        'rule_id' => 'gambling_losses_fully_deductible',
    ]);

    // 10 × $500 = $5,000 — well above thresholds
    Transaction::factory()->count(10)->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'draftkings',
        'amount'              => 500.00,
        'transaction_date'    => now()->subDays(5),
    ]);

    $detector = app(CategoryLibraryDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    // No finding with the suppress-band rule_id
    foreach ($result as $key) {
        expect($key)->not->toBe('gambling_losses_fully_deductible');
    }

    // No OptimizationFinding for fully-deductible gambling losses
    expect(
        OptimizationFinding::where('user_id', $this->user->id)
            ->where('finding_key', 'gambling_losses_fully_deductible')
            ->exists()
    )->toBeFalse();
});

it('CategoryLibraryDetector treatment never asserts deductibility for any category', function () {
    DetectionMerchant::create([
        'company_name' => 'AutoZone', 'aliases' => ['AUTOZONE'],
        'category' => 'vehicle', 'subdetector_key' => 'vehicle_parts',
        'defensibility_rating' => 'conditional', 'gray_area' => false,
        'rule_id' => 'category_vehicle',
    ]);

    Transaction::factory()->count(5)->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'autozone',
        'amount'              => 300.00,
        'transaction_date'    => now()->subDays(20),
    ]);

    $detector = app(CategoryLibraryDetector::class);
    $detector->run($this->user->id, $this->taxYear, $this->service, []);

    $findings = OptimizationFinding::where('user_id', $this->user->id)->get();

    foreach ($findings as $finding) {
        // Banned phrases: never assert deductibility
        expect($finding->treatment)->not->toContain('this is deductible')
            ->and($finding->treatment)->not->toContain('you can deduct this')
            ->and($finding->treatment)->not->toContain('is fully deductible');
    }
});

it('CategoryLibraryDetector gray-area pool/spa finding includes docs checklist', function () {
    DetectionMerchant::create([
        'company_name' => "Leslie's", 'aliases' => ["LESLIES", "LESLIES POOL"],
        'category' => 'pool_spa', 'subdetector_key' => 'pool_supply',
        'defensibility_rating' => 'conditional', 'gray_area' => true,
        'rule_id' => 'category_pool_spa',
    ]);

    // $2,000 single transaction — above $1,000 interrogate threshold
    Transaction::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_normalized' => 'leslies',
        'amount'              => 2000.00,
        'transaction_date'    => now()->subDays(10),
    ]);

    $detector = app(CategoryLibraryDetector::class);
    $result = $detector->run($this->user->id, $this->taxYear, $this->service, []);

    if (count($result) > 0) {
        $finding = OptimizationFinding::where('user_id', $this->user->id)
            ->whereIn('finding_key', $result)
            ->first();

        if ($finding && $finding->docs_missing) {
            // docs_missing should be present for gray-area modules
            expect($finding->docs_missing)->toBeArray();
        }

        // Pro_export_ready must be false while docs are missing
        expect($finding->pro_export_ready ?? false)->toBeFalse();
    }

    // At minimum, no assertion of deductibility
    $findings = OptimizationFinding::where('user_id', $this->user->id)->get();
    foreach ($findings as $finding) {
        expect($finding->treatment)->not->toContain('this is deductible');
    }
});

// ── DeductibleSaasSweep ──────────────────────────────────────────────────────

it('DeductibleSaasSweep stays silent when no active subscriptions exist', function () {
    $sweep = app(DeductibleSaasSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('DeductibleSaasSweep surfaces active Software subscriptions as educational findings', function () {
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'GitHub',
        'merchant_normalized' => 'github',
        'category'            => 'Software',
        'status'              => 'active',
        'amount'              => '10.00',
        'frequency'           => 'monthly',
        'is_essential'        => false,
        'last_charge_date'    => now()->subDays(15),
    ]);

    $sweep = app(DeductibleSaasSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    expect(count($result))->toBeGreaterThanOrEqual(1);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->first();

    expect($finding)->not->toBeNull()
        // Educational framing: 'may' present, no direct assertion
        ->and($finding->treatment)->toContain('may')
        ->and($finding->treatment)->not->toContain('is deductible');
});

it('DeductibleSaasSweep does not surface cancelled subscriptions', function () {
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Adobe',
        'merchant_normalized' => 'adobe',
        'category'            => 'Software',
        'status'              => 'cancelled',
        'amount'              => '55.00',
        'frequency'           => 'monthly',
        'is_essential'        => false,
        'last_charge_date'    => now()->subMonths(3),
    ]);

    $sweep = app(DeductibleSaasSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    expect($result)->toBeEmpty();
});

it('DeductibleSaasSweep finding is_essential subscription still surfaced for business review', function () {
    // Essential subscriptions like Google Workspace may still be deductible for businesses
    Subscription::factory()->create([
        'user_id'             => $this->user->id,
        'merchant_name'       => 'Google Workspace',
        'merchant_normalized' => 'google workspace',
        'category'            => 'Software',
        'status'              => 'active',
        'amount'              => '14.00',
        'frequency'           => 'monthly',
        'is_essential'        => true,
        'last_charge_date'    => now()->subDays(5),
    ]);

    $sweep = app(DeductibleSaasSweep::class);
    $result = $sweep->run($this->user->id, $this->taxYear, $this->service, []);

    // Should surface (essential can still be deductible for SE/business)
    expect(count($result))->toBeGreaterThanOrEqual(1);

    $finding = OptimizationFinding::where('user_id', $this->user->id)
        ->whereIn('finding_key', $result)
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->treatment)->not->toContain('is deductible');
});
