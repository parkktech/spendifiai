<?php

/**
 * QuestionRetirementServiceTest — 14-11 Task 2
 *
 * Tests for QuestionRetirementService (14-11):
 *
 *   countByDocument(): per-document confirmed-fact-key intersection with INTERVIEW_FACT_KEYS
 *   countByUser(): aggregate across all confirmed document_extraction facts
 *   summaryLine(): copy quality check (D18 framing)
 *   Action Center integration: questions_retired field returned in GET /api/v1/optimizer/action-center
 *   D17 gate: no HTTP calls / no Claude calls
 */

use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\QuestionRetirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->service = app(QuestionRetirementService::class);
    $this->taxYear = 2026;
});

// ──────────────────────────────────────────────────────────────────────────────
// INTERVIEW_FACT_KEYS coverage
// ──────────────────────────────────────────────────────────────────────────────

it('INTERVIEW_FACT_KEYS contains core big-rock fact keys', function () {
    $keys = QuestionRetirementService::INTERVIEW_FACT_KEYS;
    expect($keys)->toContain('w4.filing_status');
    expect($keys)->toContain('w4.dependents_claimed');
    expect($keys)->toContain('pay.gross_per_period_cents');
    expect($keys)->toContain('pay.federal_withholding_per_period_cents');
    expect($keys)->toContain('retirement.traditional_401k_ytd_cents');
    expect($keys)->toContain('retirement.roth_401k_ytd_cents');
    expect($keys)->toContain('benefits.fsa_ytd_cents');
    // Identity-plane additions (14-11)
    expect($keys)->toContain('identity.employee_name');
    expect($keys)->toContain('identity.employee_address');
});

// ──────────────────────────────────────────────────────────────────────────────
// countByDocument()
// ──────────────────────────────────────────────────────────────────────────────

it('countByDocument returns 0 when no confirmed extractions exist for the document', function () {
    $result = $this->service->countByDocument($this->user->id, 999);
    expect($result['count'])->toBe(0);
    expect($result['retired_keys'])->toBeEmpty();
});

it('countByDocument counts only confirmed document_extraction facts in INTERVIEW_FACT_KEYS', function () {
    $docId = 42;

    // Fact 1: confirmed document_extraction — in INTERVIEW_FACT_KEYS → counts
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'pay.gross_per_period_cents',
        value: '425000',
        sourceType: 'document_extraction',
        taxYear: $this->taxYear,
        sourceId: (string) $docId,
    );
    UserTaxFact::where('user_id', $this->user->id)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->update(['confirmed_at' => now()]);

    // Fact 2: confirmed document_extraction — NOT in INTERVIEW_FACT_KEYS → doesn't count
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'employer_name_label',
        value: 'ACME Corp',
        sourceType: 'document_extraction',
        taxYear: $this->taxYear,
        sourceId: (string) $docId,
    );
    UserTaxFact::where('user_id', $this->user->id)
        ->where('fact_key', 'employer_name_label')
        ->update(['confirmed_at' => now()]);

    // Fact 3: unconfirmed (proposal, confirmed_at IS NULL) — should NOT count
    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'w4.filing_status',
        value: 'single',
        sourceType: 'document_extraction',
        taxYear: $this->taxYear,
        sourceId: (string) $docId,
    );
    // confirmed_at left null — D4 proposal

    $result = $this->service->countByDocument($this->user->id, $docId);
    expect($result['count'])->toBe(1);
    expect($result['retired_keys'])->toContain('pay.gross_per_period_cents');
    expect($result['retired_keys'])->not->toContain('w4.filing_status');
    expect($result['retired_keys'])->not->toContain('employer_name_label');
});

it('countByDocument only counts facts from the specified document_id (scoped by source_id)', function () {
    $docIdA = 10;
    $docIdB = 20;

    UserTaxFact::recordFact(
        userId: $this->user->id,
        factKey: 'pay.gross_per_period_cents',
        value: '425000',
        sourceType: 'document_extraction',
        taxYear: $this->taxYear,
        sourceId: (string) $docIdA,
    );
    UserTaxFact::where('user_id', $this->user->id)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->update(['confirmed_at' => now()]);

    // countByDocument for docIdB should see nothing
    $result = $this->service->countByDocument($this->user->id, $docIdB);
    expect($result['count'])->toBe(0);

    // countByDocument for docIdA should see 1
    $resultA = $this->service->countByDocument($this->user->id, $docIdA);
    expect($resultA['count'])->toBe(1);
});

it('countByDocument deduplicates fact keys (multiple confirmed rows for same key count once)', function () {
    $docId = 55;

    // Two confirmed facts with the same key (shouldn't happen in practice but counts once)
    foreach (['425000', '430000'] as $value) {
        UserTaxFact::create([
            'user_id' => $this->user->id,
            'fact_key' => 'pay.gross_per_period_cents',
            'value' => $value,
            'source_type' => 'document_extraction',
            'source_id' => (string) $docId,
            'tax_year' => $this->taxYear,
            'is_current' => false,
            'asserted_at' => now(),
            'confirmed_at' => now(),
        ]);
    }

    $result = $this->service->countByDocument($this->user->id, $docId);
    expect($result['count'])->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// countByUser()
// ──────────────────────────────────────────────────────────────────────────────

it('countByUser returns 0 when no confirmed document extractions exist', function () {
    $result = $this->service->countByUser($this->user->id);
    expect($result['count'])->toBe(0);
});

it('countByUser aggregates across multiple documents and tax years', function () {
    // Document 1: two confirmed INTERVIEW_FACT_KEYS facts
    foreach (['pay.gross_per_period_cents', 'w4.filing_status'] as $key) {
        UserTaxFact::create([
            'user_id' => $this->user->id,
            'fact_key' => $key,
            'value' => 'val1',
            'source_type' => 'document_extraction',
            'source_id' => '1',
            'tax_year' => 2025,
            'is_current' => false,
            'asserted_at' => now(),
            'confirmed_at' => now(),
        ]);
    }

    // Document 2: one confirmed INTERVIEW_FACT_KEYS fact (different tax year)
    UserTaxFact::create([
        'user_id' => $this->user->id,
        'fact_key' => 'retirement.traditional_401k_ytd_cents',
        'value' => '600000',
        'source_type' => 'document_extraction',
        'source_id' => '2',
        'tax_year' => 2026,
        'is_current' => false,
        'asserted_at' => now(),
        'confirmed_at' => now(),
    ]);

    $result = $this->service->countByUser($this->user->id);
    expect($result['count'])->toBe(3);
    expect($result['retired_keys'])->toContain('pay.gross_per_period_cents');
    expect($result['retired_keys'])->toContain('w4.filing_status');
    expect($result['retired_keys'])->toContain('retirement.traditional_401k_ytd_cents');
});

it('countByUser is scoped to the given user — no cross-user leakage', function () {
    $otherUser = User::factory()->create();

    // Other user has confirmed extractions
    UserTaxFact::create([
        'user_id' => $otherUser->id,
        'fact_key' => 'pay.gross_per_period_cents',
        'value' => '500000',
        'source_type' => 'document_extraction',
        'source_id' => '99',
        'tax_year' => 2026,
        'is_current' => false,
        'asserted_at' => now(),
        'confirmed_at' => now(),
    ]);

    // This user has none
    $result = $this->service->countByUser($this->user->id);
    expect($result['count'])->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// summaryLine() — D18 copy quality
// ──────────────────────────────────────────────────────────────────────────────

it('summaryLine returns empty string for count=0', function () {
    expect($this->service->summaryLine(0))->toBe('');
});

it('summaryLine returns singular form for count=1', function () {
    $line = $this->service->summaryLine(1);
    expect($line)->toContain('1 question');
    expect($line)->not->toContain('questions'); // not plural
});

it('summaryLine returns plural form for count>1', function () {
    $line = $this->service->summaryLine(4);
    expect($line)->toContain('4 questions');
});

it('summaryLine uses D18 educational framing — no internal key paths or assertive claims', function () {
    $line = $this->service->summaryLine(6);
    // No raw internal key paths (D18 no-internal-key rule)
    expect($line)->not->toMatch('/[a-z]+\.[a-z_]+/');
    // Not assertive
    expect(strtolower($line))->not->toContain('must');
    expect(strtolower($line))->not->toContain('required');
});

// ──────────────────────────────────────────────────────────────────────────────
// Action Center integration — questions_retired in API response
// ──────────────────────────────────────────────────────────────────────────────

it('GET /api/v1/optimizer/action-center includes questions_retired and questions_retired_summary', function () {
    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/optimizer/action-center');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'questions_retired',
        'questions_retired_summary',
    ]);
    expect($response->json('questions_retired'))->toBe(0);
    expect($response->json('questions_retired_summary'))->toBeNull();
});

it('Action Center returns non-zero questions_retired when user has confirmed document extractions', function () {
    // Simulate a confirmed paystub extraction
    UserTaxFact::create([
        'user_id' => $this->user->id,
        'fact_key' => 'pay.gross_per_period_cents',
        'value' => '425000',
        'source_type' => 'document_extraction',
        'source_id' => '7',
        'tax_year' => $this->taxYear,
        'is_current' => false,
        'asserted_at' => now(),
        'confirmed_at' => now(),
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/optimizer/action-center');

    $response->assertStatus(200);
    expect($response->json('questions_retired'))->toBe(1);
    expect($response->json('questions_retired_summary'))->toContain('question');
});

it('QuestionRetirementService makes no HTTP calls (D17)', function () {
    $this->service->countByUser($this->user->id);
    $this->service->countByDocument($this->user->id, 1);
    expect(true)->toBeTrue(); // No Http::fake() needed — Http::preventStrayRequests() in beforeEach
});
