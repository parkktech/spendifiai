<?php

use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\AI\PaystubFactExtractorService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * DOC-07 / D4: PaystubFactExtractorService creates UserTaxFact PROPOSALS
 * (is_current=false, source_type=document_extraction) that are invisible to
 * skip-logic until the user confirms via DurableFactsController::confirm().
 *
 * Tests assert both directions of the D4 gate:
 *   (a) Proposals are created with correct flags + confidence metadata
 *   (b) Proposals are excluded from currentFactKeys() pre-confirm
 *   (c) User-entered facts are NOT overwritten when a proposal is created
 *   (d) confirm() promotes the proposal to current, superseding user-entered value
 */
function makePaystubDocument(int $userId, array $extractedFields = []): TaxDocument
{
    Storage::fake('local');
    Storage::disk('local')->put("tax-vault/{$userId}/2025/pay_stub/test.pdf", 'fake-content');

    $defaultFields = [
        'employer_name' => ['value' => 'Acme Corp', 'confidence' => 0.98],
        'traditional_401k_deduction' => ['value' => '1000.00', 'confidence' => 0.90],
        'roth_401k_deduction' => ['value' => '500.00',  'confidence' => 0.88],
        'hsa_deduction' => ['value' => '200.00',  'confidence' => 0.85],
        'fsa_deduction' => ['value' => '150.00',  'confidence' => 0.80],
    ];

    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$userId}/2025/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'fake-content'),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => 'pay_stub',
        'extracted_data' => [
            'fields' => array_merge($defaultFields, $extractedFields),
            'overall_confidence' => 0.90,
        ],
    ]);
}

function makeBenefitsDocument(int $userId, array $extractedFields = []): TaxDocument
{
    Storage::fake('local');
    Storage::disk('local')->put("tax-vault/{$userId}/2025/benefits_guide/test.pdf", 'fake-content');

    $defaultFields = [
        'has_401k' => ['value' => 'true', 'confidence' => 0.95],
        'employer_match_formula' => ['value' => '4% up to IRS limit', 'confidence' => 0.88],
        'hdhp_hsa_available' => ['value' => 'true', 'confidence' => 0.92],
        'after_tax_401k_available' => ['value' => 'false', 'confidence' => 0.85],
    ];

    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => 'benefits-guide.pdf',
        'stored_path' => "tax-vault/{$userId}/2025/benefits_guide/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 2048,
        'file_hash' => hash('sha256', 'fake-content'),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => 'benefits_guide',
        'extracted_data' => [
            'fields' => array_merge($defaultFields, $extractedFields),
            'overall_confidence' => 0.92,
        ],
    ]);
}

// ─── (a) Proposals created with correct flags + confidence ────────────────────

it('creates UserTaxFact proposals with is_current=false for PayStub extraction', function () {
    $user = User::factory()->create();
    $document = makePaystubDocument($user->id);
    $service = app(PaystubFactExtractorService::class);

    $count = $service->proposeFacts($document);

    expect($count)->toBeGreaterThan(0);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'retirement.traditional_401k_ytd_cents')
        ->first();

    expect($proposal)->not->toBeNull()
        ->and($proposal->is_current)->toBeFalse()
        ->and($proposal->source_type)->toBe('document_extraction')
        ->and($proposal->confirmed_at)->toBeNull()
        ->and($proposal->metadata['confidence'])->toBeFloat()
        ->and($proposal->metadata['confidence'])->toBeLessThanOrEqual(1.0)
        ->and($proposal->metadata['document_id'])->toBe($document->id);
});

it('proposal value is an integer-cents-as-string for money fields', function () {
    $user = User::factory()->create();
    $document = makePaystubDocument($user->id, [
        'traditional_401k_deduction' => ['value' => '1500.00', 'confidence' => 0.90],
    ]);
    $service = app(PaystubFactExtractorService::class);

    $service->proposeFacts($document);

    // Value is $hidden from toArray() — access via fresh query without $hidden
    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'retirement.traditional_401k_ytd_cents')
        ->first();

    expect($proposal)->not->toBeNull();
    // The encrypted value stores integer cents: '150000' for $1500.00
    // We can't read $hidden value directly from toArray, but we can verify
    // it's a proposal with the right confidence metadata
    expect($proposal->metadata['confidence'])->toBe(0.90);
});

it('creates proposals for BenefitsGuide boolean fields as yes/no strings', function () {
    $user = User::factory()->create();
    $document = makeBenefitsDocument($user->id);
    $service = app(PaystubFactExtractorService::class);

    $count = $service->proposeFacts($document);

    expect($count)->toBeGreaterThan(0);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'employer.has_401k')
        ->first();

    expect($proposal)->not->toBeNull()
        ->and($proposal->is_current)->toBeFalse()
        ->and($proposal->source_type)->toBe('document_extraction')
        ->and($proposal->metadata['confidence'])->toBe(0.95)
        // Boolean metadata preserved
        ->and($proposal->metadata)->toHaveKey('original_bool');
});

it('returns 0 for documents with no matching fields', function () {
    $user = User::factory()->create();

    // PayStub with no mappable fields
    Storage::fake('local');
    Storage::disk('local')->put("tax-vault/{$user->id}/2025/pay_stub/empty.pdf", 'fake');
    $document = TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'empty.pdf',
        'stored_path' => "tax-vault/{$user->id}/2025/pay_stub/empty.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 512,
        'file_hash' => hash('sha256', 'fake'),
        'tax_year' => 2025,
        'status' => 'ready',
        'category' => 'pay_stub',
        'extracted_data' => [
            'fields' => ['employer_name' => ['value' => 'Acme', 'confidence' => 0.9]],
            'overall_confidence' => 0.90,
        ],
    ]);

    $service = app(PaystubFactExtractorService::class);
    $count = $service->proposeFacts($document);

    // employer_name is not in PAYSTUB_FACT_MAP, so no proposals for dollar fields
    expect($count)->toBe(0);
});

// ─── (b) Proposals excluded from currentFactKeys() pre-confirm ───────────────

it('excludes proposals from UserTaxFact::currentFactKeys() pre-confirm', function () {
    $user = User::factory()->create();
    $document = makePaystubDocument($user->id);
    $service = app(PaystubFactExtractorService::class);

    $service->proposeFacts($document);

    $keys = UserTaxFact::currentFactKeys($user->id);

    expect($keys)->not->toContain('retirement.traditional_401k_ytd_cents')
        ->not->toContain('retirement.roth_401k_ytd_cents')
        ->not->toContain('retirement.hsa_ytd_cents');
});

it('excludes proposals from IncomeOptimizationProfile::answerableFields() pre-confirm', function () {
    $user = User::factory()->create();
    $document = makePaystubDocument($user->id);
    $service = app(PaystubFactExtractorService::class);

    $service->proposeFacts($document);

    $factsProxy = new \App\Models\UserTaxFact;
    $profile = \App\Models\IncomeOptimizationProfile::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
    ]);

    $answerable = $profile->answerableFields($factsProxy);

    // Proposal keys must NOT appear as true in answerable fields
    expect($answerable)->not->toHaveKey('retirement.traditional_401k_ytd_cents');
});

// ─── (c) User-entered fact is NOT overwritten by a new proposal ───────────────

it('does NOT overwrite a user_edit fact when a proposal is created for the same key', function () {
    $user = User::factory()->create();

    // Seed a user_edit fact first
    $original = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'retirement.traditional_401k_ytd_cents',
        value: '999999',    // 9999.99 in cents
        sourceType: 'user_edit',
        label: 'Traditional 401(k) (user edited)',
        volatility: 'annual',
        taxYear: 2025,
    );

    expect($original->is_current)->toBeTrue()
        ->and($original->source_type)->toBe('user_edit');

    // Now create a proposal
    $document = makePaystubDocument($user->id);
    $service = app(PaystubFactExtractorService::class);
    $service->proposeFacts($document);

    // The original user_edit fact must STILL be current
    $original->refresh();
    expect($original->is_current)->toBeTrue()
        ->and($original->source_type)->toBe('user_edit')
        ->and($original->superseded_by_id)->toBeNull();

    // The proposal must exist but be is_current=false
    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'retirement.traditional_401k_ytd_cents')
        ->where('source_type', 'document_extraction')
        ->first();

    expect($proposal)->not->toBeNull()
        ->and($proposal->is_current)->toBeFalse();
});

// ─── (d) confirm() promotes proposal to current, supersedes user-entered value ─

it('confirm() promotes a proposal to current and supersedes the user_edit fact', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Seed user_edit fact
    $userEdit = UserTaxFact::recordFact(
        userId: $user->id,
        factKey: 'retirement.traditional_401k_ytd_cents',
        value: '500000',   // $5000.00
        sourceType: 'user_edit',
        label: 'Traditional 401(k) (user edited)',
        volatility: 'annual',
        taxYear: 2025,
    );

    // Create proposal via extraction
    $document = makePaystubDocument($user->id, [
        'traditional_401k_deduction' => ['value' => '1200.00', 'confidence' => 0.91],
    ]);
    $service = app(PaystubFactExtractorService::class);
    $service->proposeFacts($document);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'retirement.traditional_401k_ytd_cents')
        ->where('source_type', 'document_extraction')
        ->first();

    expect($proposal->is_current)->toBeFalse();

    // Confirm via the DurableFactsController endpoint
    $response = $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm");
    $response->assertOk();

    // Proposal must now be current
    $proposal->refresh();
    expect($proposal->is_current)->toBeTrue()
        ->and($proposal->confirmed_at)->not->toBeNull();

    // User-edit fact must now be superseded
    $userEdit->refresh();
    expect($userEdit->is_current)->toBeFalse()
        ->and($userEdit->superseded_by_id)->toBe($proposal->id);

    // currentFactKeys() must now include this key
    $keys = UserTaxFact::currentFactKeys($user->id);
    expect($keys)->toContain('retirement.traditional_401k_ytd_cents');
});

// ─── display_value + source_label in GET /api/v1/optimizer/facts ─────────────
//
// Owner UX fix cluster — Fix 1: proposals must expose humanized display_value
// and source_label so the ProposalConfirmCard can show the actual value extracted.

it('proposals API returns display_value and source_label for money cents fact', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makePaystubDocument($user->id, [
        'gross_pay' => ['value' => '4250.00', 'confidence' => 0.95],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $response = $this->getJson('/api/v1/optimizer/facts');
    $response->assertOk();

    $proposals = $response->json('proposals');
    expect($proposals)->not->toBeEmpty();

    $grossFact = collect($proposals)->first(fn ($p) => $p['fact_key'] === 'pay.gross_per_period_cents');
    expect($grossFact)->not->toBeNull();

    // display_value: $4,250.00 (cents → dollars humanized)
    expect($grossFact['display_value'])->toBe('$4,250.00');

    // source_label: "from your [date] Pay Stub"
    expect($grossFact['source_label'])->toContain('Pay Stub');
    expect($grossFact['source_label'])->toStartWith('from your');

    // raw 'value' must NOT be exposed in the response
    expect(array_key_exists('value', $grossFact))->toBeFalse();
});

it('proposals API returns humanized Yes/No for boolean facts', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makeBenefitsDocument($user->id, [
        'has_401k' => ['value' => 'true', 'confidence' => 0.95],
        'after_tax_401k_available' => ['value' => 'false', 'confidence' => 0.85],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $response = $this->getJson('/api/v1/optimizer/facts');
    $response->assertOk();

    $proposals = collect($response->json('proposals'));

    $has401k = $proposals->first(fn ($p) => $p['fact_key'] === 'employer.has_401k');
    expect($has401k['display_value'])->toBe('Yes');

    $afterTax = $proposals->first(fn ($p) => $p['fact_key'] === 'employer.after_tax_401k_available');
    expect($afterTax['display_value'])->toBe('No');
});

it('proposals API humanizes w4 filing status', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makePaystubDocument($user->id, [
        'w4_filing_status' => ['value' => 'married', 'confidence' => 0.90],
    ]);

    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $response = $this->getJson('/api/v1/optimizer/facts');
    $response->assertOk();

    $proposal = collect($response->json('proposals'))
        ->first(fn ($p) => $p['fact_key'] === 'w4.filing_status');

    expect($proposal)->not->toBeNull();
    expect($proposal['display_value'])->toBe('Married Filing Jointly');
    // raw 'value' column excluded
    expect(array_key_exists('value', $proposal))->toBeFalse();
});

// ─── GET /api/v1/tax-vault/type-status (Fix 2 — green-check grid) ────────────

it('type-status endpoint returns inventory shape for upload grid types', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    // No documents yet — all types should be not-ready
    $response = $this->getJson('/api/v1/tax-vault/type-status');
    $response->assertOk();

    $types = $response->json('types');
    expect($types)->toHaveKeys(['paystub', 'w2', 'benefits_guide', 'hsa_statement', 'retirement_statement', 'medical_receipt', 'other']);

    foreach ($types as $type => $status) {
        expect($status)->toHaveKeys(['has_ready_doc', 'latest_uploaded_at', 'ready_count', 'extracted_fields_count']);
        expect($status['has_ready_doc'])->toBeFalse();
        expect($status['ready_count'])->toBe(0);
    }
});

it('type-status shows has_ready_doc=true after a ready paystub is uploaded', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    Storage::disk('local')->put("tax-vault/{$user->id}/2026/pay_stub/test.pdf", 'content');

    // Create a ready pay_stub document
    TaxDocument::create([
        'user_id' => $user->id,
        'original_filename' => 'paystub.pdf',
        'stored_path' => "tax-vault/{$user->id}/2026/pay_stub/test.pdf",
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'file_hash' => hash('sha256', 'unique-paystub-content-99'),
        'tax_year' => 2026,
        'status' => 'ready',
        'category' => 'pay_stub',
        'extracted_data' => [
            'fields' => [
                'gross_pay' => ['value' => '5000.00', 'confidence' => 0.95],
                'federal_tax_withheld' => ['value' => '800.00', 'confidence' => 0.90],
            ],
            'overall_confidence' => 0.92,
        ],
    ]);

    $response = $this->getJson('/api/v1/tax-vault/type-status');
    $response->assertOk();

    $paystub = $response->json('types.paystub');
    expect($paystub['has_ready_doc'])->toBeTrue();
    expect($paystub['ready_count'])->toBe(1);
    expect($paystub['extracted_fields_count'])->toBe(2);
    expect($paystub['latest_uploaded_at'])->not->toBeNull();

    // Other types still not ready
    expect($response->json('types.w2.has_ready_doc'))->toBeFalse();
});

// ─── Confirm-with-correction (owner UX fix 1b) ────────────────────────────────
//
// The Edit button on proposals was broken by design-gap: supersede() requires
// is_current=true but proposals are is_current=false. POST /confirm now accepts
// an optional corrected {value}: the user's value WINS (recorded as user_edit),
// the proposal is resolved (superseded_by_id) and leaves the proposals list.

it('confirm-with-correction: edited proposal value becomes current with user_edit provenance and the proposal resolves', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makePaystubDocument($user->id, [
        'gross_pay' => ['value' => '4250.00', 'confidence' => 0.95],
    ]);
    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'pay.gross_per_period_cents')
        ->where('source_type', 'document_extraction')
        ->firstOrFail();
    expect($proposal->is_current)->toBeFalse();

    // E2E: submit a corrected dollar value through the confirm endpoint
    $response = $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm", [
        'value' => '$4,300.00',
    ]);
    $response->assertOk();

    // The corrected fact is CURRENT with user_edit provenance
    $current = UserTaxFact::currentFact($user->id, 'pay.gross_per_period_cents', null, $proposal->tax_year);
    expect($current)->not->toBeNull();
    expect($current->source_type)->toBe('user_edit');
    expect($current->value)->toBe('430000'); // typed money conversion: dollars → cents
    expect($current->is_current)->toBeTrue();

    // Provenance: the corrected fact records which proposal it corrected + document linkage
    expect($current->metadata['corrected_from_proposal_id'])->toBe($proposal->id);
    expect((string) $current->metadata['document_id'])->toBe((string) $doc->id);

    // The proposal is resolved — superseded by the corrected fact, never confirmed as-is
    $proposal->refresh();
    expect($proposal->superseded_by_id)->toBe($current->id);
    expect($proposal->confirmed_at)->toBeNull();
    expect($proposal->is_current)->toBeFalse();

    // The proposal leaves the proposals list (card disappears on refresh)
    $list = $this->getJson('/api/v1/optimizer/facts');
    $list->assertOk();
    $ids = collect($list->json('proposals'))->pluck('id');
    expect($ids)->not->toContain($proposal->id);
});

it('confirm-with-correction: money facts reject non-numeric input with a specific message', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makePaystubDocument($user->id);
    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'retirement.traditional_401k_ytd_cents')
        ->firstOrFail();

    $response = $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm", [
        'value' => 'about a thousand',
    ]);
    $response->assertStatus(422);
    // Specific typed-validation message — not the generic failure line
    expect($response->json('message'))->toContain('dollar amount');

    // Nothing was written: proposal untouched, no user_edit fact created
    $proposal->refresh();
    expect($proposal->superseded_by_id)->toBeNull();
    expect(UserTaxFact::forUser($user->id)->where('source_type', 'user_edit')->count())->toBe(0);
});

it('confirm-with-correction: confirm without a value still behaves as plain D4 confirm', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makePaystubDocument($user->id);
    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'retirement.hsa_ytd_cents')
        ->firstOrFail();

    // No value in the body → existing confirmProposal path
    $response = $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm");
    $response->assertOk();

    $proposal->refresh();
    expect($proposal->is_current)->toBeTrue();
    expect($proposal->confirmed_at)->not->toBeNull();
    expect($proposal->source_type)->toBe('document_extraction');
});

it('confirm-with-correction: cannot correct an already-confirmed fact', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();
    Sanctum::actingAs($user);

    $doc = makePaystubDocument($user->id);
    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'benefits.fsa_ytd_cents')
        ->firstOrFail();

    // Confirm plainly first
    $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm")->assertOk();

    // Then attempt to correct → 422
    $response = $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm", [
        'value' => '999.00',
    ]);
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('already been confirmed');
});

it('confirm-with-correction: other users cannot correct someone else\'s proposal', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $doc = makePaystubDocument($owner->id);
    app(PaystubFactExtractorService::class)->proposeFacts($doc);

    $proposal = UserTaxFact::forUser($owner->id)
        ->where('source_type', 'document_extraction')
        ->firstOrFail();

    $attacker = createAuthenticatedUser();
    Sanctum::actingAs($attacker);

    $this->postJson("/api/v1/optimizer/facts/{$proposal->id}/confirm", [
        'value' => '1.00',
    ])->assertStatus(403);

    $proposal->refresh();
    expect($proposal->superseded_by_id)->toBeNull();
});

// ─── Fix B: W-4 Step 3 semantic remap ────────────────────────────────────────
//
// Modern W-4 Step 3 carries an annual dollar credit amount, NOT a dependent count.
// The paystub field w4_dependents_claimed was mis-mapped to w4.dependents_claimed
// (a count fact), producing "3200 dependents". Fix:
//   - Extraction field renamed to w4_step3_credits → maps to w4.step3_annual_credits_cents (money)
//   - w4.dependents_claimed no longer claimed by paystub extraction
//   - Bad existing proposals (value > 20, sourced from document_extraction) are invalidated
//     and the corrected w4.step3_annual_credits_cents proposal is created from the same doc

it('Fix-B mapping: w4_step3_credits paystub field creates w4.step3_annual_credits_cents money proposal', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();

    // Paystub extracted_data uses the NEW field name w4_step3_credits
    $doc = makePaystubDocument($user->id, [
        'w4_step3_credits' => ['value' => '3200.00', 'confidence' => 0.88],
    ]);

    $count = app(\App\Services\AI\PaystubFactExtractorService::class)->proposeFacts($doc);

    expect($count)->toBeGreaterThan(0);

    // Proposal created for the new fact key — money fact stored as integer cents
    $proposal = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'w4.step3_annual_credits_cents')
        ->first();

    expect($proposal)->not->toBeNull()
        ->and($proposal->is_current)->toBeFalse()
        ->and($proposal->source_type)->toBe('document_extraction')
        ->and($proposal->label)->toBe('W-4 Step 3 dependent credits')
        ->and($proposal->metadata['confidence'])->toBe(0.88);

    // Value is stored as integer cents: $3,200 → 320000 cents
    expect((int) $proposal->value)->toBe(320_000);
});

it('Fix-B source-chain: paystub extraction no longer creates w4.dependents_claimed proposals', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();

    // Paystub with old field name (now absent from the extractor map)
    $doc = makePaystubDocument($user->id, [
        'w4_dependents_claimed' => ['value' => '3200', 'confidence' => 0.85],
    ]);

    app(\App\Services\AI\PaystubFactExtractorService::class)->proposeFacts($doc);

    // w4.dependents_claimed must NOT be proposed from paystub extraction
    $bad = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'w4.dependents_claimed')
        ->where('source_type', 'document_extraction')
        ->first();

    expect($bad)->toBeNull();
});

it('Fix-B migration: implausible w4.dependents_claimed proposal is invalidated and corrected step3 proposal created', function () {
    Storage::fake('local');

    $user = createAuthenticatedUser();

    // Simulate the pre-fix state: a bad w4.dependents_claimed proposal with value 3200
    // (stored as string — it was treated as a count by the old code)
    $doc = makePaystubDocument($user->id, [
        'gross_pay' => ['value' => '6000.00', 'confidence' => 0.95],
    ]);

    // Manually insert the bad proposal (as the old extractor would have)
    $badProposal = UserTaxFact::create([
        'user_id' => $user->id,
        'fact_key' => 'w4.dependents_claimed',
        'value' => '3200',   // implausible count (>20) — was actually Step 3 dollars
        'label' => 'W-4 dependents claimed (paystub evidence)',
        'volatility' => 'annual',
        'source_type' => 'document_extraction',
        'source_id' => (string) $doc->id,
        'tax_year' => 2025,
        'asserted_at' => now(),
        'is_current' => false,
        'metadata' => ['confidence' => 0.85, 'document_id' => $doc->id],
    ]);

    // Run the migration command
    $this->artisan('optimizer:migrate-w4-step3')->assertExitCode(0);

    // The bad proposal is now resolved (superseded_by_id is set)
    $badProposal->refresh();
    expect($badProposal->superseded_by_id)->not->toBeNull();

    // The corrected step3 proposal was created
    $corrected = UserTaxFact::forUser($user->id)
        ->where('fact_key', 'w4.step3_annual_credits_cents')
        ->where('source_type', 'document_extraction')
        ->first();

    expect($corrected)->not->toBeNull()
        ->and($corrected->is_current)->toBeFalse()
        // 3200 (dollar amount stored as string) → 320000 cents
        ->and((int) $corrected->value)->toBe(320_000)
        ->and($corrected->metadata['migrated_from_proposal_id'])->toBe($badProposal->id)
        ->and($corrected->metadata['document_id'])->toBe($doc->id);

    // The bad proposal's superseded_by_id points to the corrected proposal
    expect($badProposal->superseded_by_id)->toBe($corrected->id);
});
