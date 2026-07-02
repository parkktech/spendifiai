<?php

use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\AI\TaxDocumentExtractorService;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeExtractorDocument(int $userId, string $mimeType = 'application/pdf', string $content = 'fake-content'): TaxDocument
{
    $ext = match (true) {
        str_starts_with($mimeType, 'image/jpeg') => 'jpg',
        str_starts_with($mimeType, 'image/png') => 'png',
        default => 'pdf',
    };

    $path = "tax-vault/{$userId}/2025/test.{$ext}";
    Storage::disk('local')->put($path, $content);

    return TaxDocument::create([
        'user_id' => $userId,
        'original_filename' => "test.{$ext}",
        'stored_path' => $path,
        'disk' => 'local',
        'mime_type' => $mimeType,
        'file_size' => strlen($content),
        'file_hash' => hash('sha256', $content),
        'tax_year' => 2025,
        'status' => 'upload',
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->service = app(TaxDocumentExtractorService::class);
});

// ─── DOC-02: buildDocumentContent() image branch ─────────────────────────────

it('buildDocumentContent() returns type:image for JPEG mime_type', function () {
    $doc = makeExtractorDocument($this->user->id, 'image/jpeg');
    $content = $this->service->buildDocumentContent($doc);

    expect($content)->toHaveCount(1);
    expect($content[0]['type'])->toBe('image');
    expect($content[0]['source']['type'])->toBe('base64');
    expect($content[0]['source']['media_type'])->toBe('image/jpeg');
    expect($content[0]['source']['data'])->not->toBeEmpty();
});

it('buildDocumentContent() returns type:image for PNG mime_type', function () {
    $doc = makeExtractorDocument($this->user->id, 'image/png');
    $content = $this->service->buildDocumentContent($doc);

    expect($content)->toHaveCount(1);
    expect($content[0]['type'])->toBe('image');
    expect($content[0]['source']['type'])->toBe('base64');
    expect($content[0]['source']['media_type'])->toBe('image/png');
    expect($content[0]['source']['data'])->not->toBeEmpty();
});

it('buildDocumentContent() returns type:document for PDF mime_type', function () {
    $doc = makeExtractorDocument($this->user->id, 'application/pdf');
    $content = $this->service->buildDocumentContent($doc);

    expect($content)->toHaveCount(1);
    expect($content[0]['type'])->toBe('document');
    expect($content[0]['source']['media_type'])->toBe('application/pdf');
});

// ─── DOC-01 getFieldSchema() — new financial cases return non-empty arrays ───

it('getFieldSchema() returns a non-empty array for PayStub', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::PayStub);
    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema)->toContain('employer_name');
    expect($schema)->toContain('gross_pay');
    expect($schema)->toContain('traditional_401k_deduction');
    expect($schema)->toContain('hsa_deduction');
});

it('getFieldSchema() returns a non-empty array for BenefitsGuide', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::BenefitsGuide);
    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema)->toContain('employer_name');
    expect($schema)->toContain('has_401k');
    expect($schema)->toContain('employer_match_formula');
    expect($schema)->toContain('hdhp_hsa_available');
});

it('getFieldSchema() returns a non-empty array for OfferLetter', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::OfferLetter);
    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema)->toContain('employer_name');
    expect($schema)->toContain('annual_salary');
});

it('getFieldSchema() returns a non-empty array for RetirementStatement', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::RetirementStatement);
    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema)->toContain('institution_name');
    expect($schema)->toContain('account_balance');
    expect($schema)->toContain('ytd_contributions');
});

it('getFieldSchema() returns a non-empty array for StockStatement', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::StockStatement);
    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema)->toContain('institution_name');
    expect($schema)->toContain('total_value');
    expect($schema)->toContain('realized_gains_ytd');
});

it('getFieldSchema() returns a non-empty array for InsuranceDoc', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::InsuranceDoc);
    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema)->toContain('provider_name');
    expect($schema)->toContain('annual_premium');
});

// ─── DOC-06 substantiation cases fall through to TIER2_FIELDS ────────────────

it('getFieldSchema() returns TIER2_FIELDS for DOC-06 substantiation cases', function () {
    $substantiationCases = [
        TaxDocumentCategory::SponsorshipAgreement,
        TaxDocumentCategory::MarketCompMemo,
        TaxDocumentCategory::PhysicianLetter,
        TaxDocumentCategory::Appraisal,
        TaxDocumentCategory::GallonsLog,
        TaxDocumentCategory::RescueOrgLetter,
        TaxDocumentCategory::SecurityMemo,
        TaxDocumentCategory::LoanDoc,
        TaxDocumentCategory::ContractorInvoice,
        TaxDocumentCategory::MileageLog,
        TaxDocumentCategory::DaycareLicense,
        TaxDocumentCategory::SponsorshipVendorEvidence,
    ];

    foreach ($substantiationCases as $case) {
        $schema = $this->service->getFieldSchema($case);
        expect($schema)
            ->toBeArray()
            ->not->toBeEmpty("getFieldSchema() returned empty for substantiation case {$case->value}")
            ->toBe(TaxDocumentExtractorService::TIER2_FIELDS);
    }
});

// ─── Existing cases are unaffected (regression) ───────────────────────────────

it('getFieldSchema() still returns W2_FIELDS for W2 (regression)', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::W2);
    expect($schema)->toBe(TaxDocumentExtractorService::W2_FIELDS);
    expect($schema)->toContain('wages');
    expect($schema)->toContain('employer_ein');
});

it('getFieldSchema() still returns NEC_1099_FIELDS for NEC_1099 (regression)', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::NEC_1099);
    expect($schema)->toBe(TaxDocumentExtractorService::NEC_1099_FIELDS);
    expect($schema)->toContain('nonemployee_compensation');
});

it('getFieldSchema() still returns TIER2_FIELDS for Other (regression)', function () {
    $schema = $this->service->getFieldSchema(TaxDocumentCategory::Other);
    expect($schema)->toBe(TaxDocumentExtractorService::TIER2_FIELDS);
});

// ─── getFieldSchema() covers all 43 cases without error ──────────────────────

it('getFieldSchema() returns a non-empty array for every case in TaxDocumentCategory::cases()', function () {
    foreach (TaxDocumentCategory::cases() as $case) {
        $schema = $this->service->getFieldSchema($case);
        expect($schema)
            ->toBeArray()
            ->not->toBeEmpty("getFieldSchema() returned empty for case {$case->value}");
    }
});
