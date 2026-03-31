<?php

namespace App\Services\AI;

use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TaxDocumentExtractorService
{
    protected ?string $apiKey;

    protected string $model;

    const CONFIDENCE_AUTO = 0.85;

    const CONFIDENCE_REVIEW = 0.60;

    const CLASSIFICATION_GATE = 0.70;

    const W2_FIELDS = [
        'employer_name', 'employer_ein', 'employee_name', 'ssn_last4',
        'wages', 'federal_tax_withheld', 'social_security_wages',
        'social_security_tax', 'medicare_wages', 'medicare_tax',
        'state', 'state_wages', 'state_tax_withheld',
    ];

    const NEC_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'nonemployee_compensation', 'federal_tax_withheld', 'state_tax_withheld', 'state',
    ];

    const INT_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'interest_income', 'early_withdrawal_penalty', 'federal_tax_withheld',
        'state_tax_withheld', 'state',
    ];

    const MISC_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'rents', 'royalties', 'other_income', 'total_amount',
        'federal_tax_withheld', 'state_tax_withheld', 'state',
    ];

    const DIV_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'ordinary_dividends', 'qualified_dividends', 'capital_gain_distributions',
        'nondividend_distributions', 'federal_tax_withheld',
        'foreign_tax_paid', 'state_tax_withheld', 'state',
    ];

    const K_1099_FIELDS = [
        'pse_name', 'pse_tin', 'payee_name', 'ssn_last4',
        'gross_amount', 'card_not_present_transactions', 'payment_card_transactions',
        'federal_tax_withheld', 'state_tax_withheld', 'state',
        'jan', 'feb', 'mar', 'apr', 'may', 'jun',
        'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    ];

    const S_1099_FIELDS = [
        'filer_name', 'filer_tin', 'transferor_name', 'ssn_last4',
        'gross_proceeds', 'closing_date', 'property_address',
        'buyers_part_of_real_estate_tax', 'assessed_value',
    ];

    const R_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'gross_distribution', 'taxable_amount', 'taxable_amount_not_determined',
        'capital_gain', 'federal_tax_withheld', 'distribution_code',
        'employee_contributions', 'ira_sep_simple',
        'state_tax_withheld', 'state',
    ];

    const G_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'unemployment_compensation', 'state_tax_refund',
        'federal_tax_withheld', 'state',
    ];

    const B_1099_FIELDS = [
        'payer_name', 'payer_tin', 'recipient_name', 'ssn_last4',
        'proceeds', 'cost_basis', 'accrued_market_discount',
        'wash_sale_loss_disallowed', 'gain_loss_type',
        'federal_tax_withheld', 'date_acquired', 'date_sold',
    ];

    const MORTGAGE_1098_FIELDS = [
        'lender_name', 'lender_tin', 'borrower_name', 'ssn_last4',
        'mortgage_interest', 'outstanding_principal', 'points_paid',
        'property_tax', 'mortgage_insurance_premiums', 'property_address',
    ];

    const TIER2_FIELDS = [
        'form_title', 'issuer_name', 'issuer_tin', 'recipient_name',
        'ssn_last4', 'total_amount', 'tax_year',
        'federal_tax_withheld', 'state_tax_withheld',
    ];

    public function __construct()
    {
        $this->apiKey = config('spendifiai.ai.api_key') ?? '';
        $this->model = config('spendifiai.ai.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Classify a tax document into one of 25 form types and detect the tax year.
     *
     * @return array{category: string, confidence: float, reasoning: string, detected_tax_year: int|null}
     */
    public function classify(TaxDocument $document): array
    {
        $categories = collect(TaxDocumentCategory::cases())
            ->map(fn (TaxDocumentCategory $c) => "- \"{$c->value}\" ({$c->label()})")
            ->implode("\n");

        $systemPrompt = <<<PROMPT
You are a tax document classifier. Analyze the uploaded document and determine which tax form type it is, and identify the tax year.

Return ONLY valid JSON with these fields:
{
  "category": "the enum value from the list below",
  "confidence": 0.0 to 1.0,
  "reasoning": "brief explanation of why this classification",
  "detected_tax_year": 2025,
  "is_multi_document": false
}

VALID CATEGORIES (use the exact value string):
{$categories}

If the document does not clearly match any specific form type, use "other".
If it appears to be a receipt or collection of receipts, use "receipts".

MULTI-DOCUMENT DETECTION:
- Set "is_multi_document" to true if this PDF contains MULTIPLE DIFFERENT tax forms (e.g., a W-2 AND a 1099 AND a 1098 all in one file)
- If it's a single form that spans multiple pages (e.g., a 2-page W-2), that is NOT multi-document — set false
- If it contains multiple copies of the same form (e.g., employee copy + employer copy of a W-2), that is NOT multi-document — set false
- Only set true when there are genuinely different tax form types combined in one PDF

TAX YEAR DETECTION:
- Look for the tax year printed on the form (e.g., "2025" on a W-2 header, "Tax Year 2025", "For calendar year 2025")
- For W-2 forms, the year is typically in the top-right area or header
- For 1099 forms, the year is typically in the header (e.g., "2025 Form 1099-NEC")
- For 1098 forms, the year is in the header
- For receipts, use the transaction/purchase date year
- If no year is visible, set detected_tax_year to null

CONFIDENCE SCORING:
- 0.90-1.00: Clear form title visible, standard IRS form layout
- 0.70-0.89: Form type identifiable but some ambiguity
- 0.50-0.69: Partially readable or non-standard format
- Below 0.50: Cannot reliably determine form type

Return ONLY valid JSON. No markdown fences. No explanation outside the JSON.
PROMPT;

        $content = $this->buildDocumentContent($document);
        $result = $this->callClaude($systemPrompt, $content);

        // Propagate API errors (e.g., "prompt is too long")
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        $detectedYear = isset($result['detected_tax_year']) ? (int) $result['detected_tax_year'] : null;

        // Validate detected year is reasonable (2000 to next year)
        if ($detectedYear !== null && ($detectedYear < 2000 || $detectedYear > (int) date('Y') + 1)) {
            $detectedYear = null;
        }

        return [
            'category' => $result['category'] ?? 'other',
            'confidence' => (float) ($result['confidence'] ?? 0.0),
            'reasoning' => $result['reasoning'] ?? '',
            'detected_tax_year' => $detectedYear,
            'is_multi_document' => $result['is_multi_document'] ?? false,
        ];
    }

    /**
     * Detect document boundaries in a multi-page PDF.
     * Returns page ranges for each individual tax document found.
     *
     * @return array{documents: array<array{category: string, tax_year: int|null, page_start: int, page_end: int, description: string}>}
     */
    public function detectDocumentBoundaries(TaxDocument $document): array
    {
        $categories = collect(TaxDocumentCategory::cases())
            ->map(fn (TaxDocumentCategory $c) => "- \"{$c->value}\" ({$c->label()})")
            ->implode("\n");

        $systemPrompt = <<<PROMPT
You are a tax document analyzer. This PDF contains MULTIPLE tax documents combined into one file.

Analyze the entire PDF and identify each individual tax document, its page range, category, and tax year.

Return ONLY valid JSON with this structure:
{
  "documents": [
    {
      "category": "w2",
      "tax_year": 2025,
      "page_start": 1,
      "page_end": 2,
      "description": "W-2 from Employer Name"
    },
    {
      "category": "1099_int",
      "tax_year": 2025,
      "page_start": 3,
      "page_end": 3,
      "description": "1099-INT from Bank Name"
    }
  ]
}

VALID CATEGORIES (use the exact value string):
{$categories}

RULES:
- Page numbers are 1-based
- Each document may span one or more pages
- Look for form headers, titles, and page breaks to identify boundaries
- A single W-2 is typically 1-2 pages, a 1099 is typically 1 page
- Include ALL documents found — do not skip any pages
- If a page is a continuation of the previous document (e.g., page 2 of a W-2), include it in the same range
- For the description, include the employer/payer/institution name if visible
- Detect the tax year from each document's header

Return ONLY valid JSON. No markdown fences. No explanation outside the JSON.
PROMPT;

        $content = $this->buildDocumentContent($document);
        $result = $this->callClaude($systemPrompt, $content);

        // If PDF is too large for vision, fall back to text-based boundary detection
        if (isset($result['error']) && str_contains($result['error'], 'too long')) {
            Log::info('PDF too large for vision boundary detection, falling back to text', [
                'document_id' => $document->id,
            ]);
            $result = $this->detectBoundariesFromText($document, $systemPrompt);
        }

        if (isset($result['error'])) {
            return ['documents' => [], 'error' => $result['error']];
        }

        $documents = $result['documents'] ?? [];

        // Validate and sanitize each boundary
        $sanitized = [];
        foreach ($documents as $doc) {
            $start = (int) ($doc['page_start'] ?? 0);
            $end = (int) ($doc['page_end'] ?? 0);

            if ($start < 1 || $end < $start) {
                continue;
            }

            $year = isset($doc['tax_year']) ? (int) $doc['tax_year'] : null;
            if ($year !== null && ($year < 2000 || $year > (int) date('Y') + 1)) {
                $year = null;
            }

            $sanitized[] = [
                'category' => $doc['category'] ?? 'other',
                'tax_year' => $year,
                'page_start' => $start,
                'page_end' => $end,
                'description' => $doc['description'] ?? '',
            ];
        }

        return ['documents' => $sanitized];
    }

    /**
     * Extract structured fields from a classified tax document.
     *
     * @return array{fields: array, overall_confidence: float}
     */
    public function extract(TaxDocument $document): array
    {
        $schema = $this->getFieldSchema($document->category);
        $fieldList = implode(', ', $schema);

        $systemPrompt = <<<PROMPT
You are a tax document data extractor. Extract the following fields from the uploaded document.

FIELDS TO EXTRACT: {$fieldList}

CRITICAL SSN RULE: Return ONLY the last 4 digits of any SSN or Social Security Number. Never return a full SSN. For the "ssn_last4" field, return exactly 4 digits.

For EACH field, return a JSON object with this structure:
{
  "fields": {
    "field_name": {
      "value": "the extracted value",
      "confidence": 0.0 to 1.0
    }
  },
  "overall_confidence": 0.0 to 1.0
}

RULES:
- For monetary amounts, return as numbers without dollar signs or commas (e.g., 52000.00)
- For EINs/TINs, return the full number with hyphen (e.g., "12-3456789")
- For SSN, return ONLY last 4 digits (e.g., "1234")
- If a field is not found in the document, omit it from the response
- Set confidence based on readability: 0.95+ for clearly printed, 0.70-0.94 for partially visible, below 0.70 for guessed

Return ONLY valid JSON. No markdown fences. No explanation outside the JSON.
PROMPT;

        $content = $this->buildDocumentContent($document);
        $result = $this->callClaude($systemPrompt, $content);

        $sanitized = $this->sanitizeExtraction($result, $document->category);

        return [
            'fields' => $sanitized['fields'] ?? [],
            'overall_confidence' => (float) ($sanitized['overall_confidence'] ?? 0.0),
        ];
    }

    /**
     * Defense-in-depth SSN stripping and field normalization.
     */
    public function sanitizeExtraction(array $data, TaxDocumentCategory $category): array
    {
        $fields = $data['fields'] ?? [];
        $sanitized = [];

        // SSN-related field names to intercept and rename
        $ssnFieldNames = ['ssn', 'employee_ssn', 'social_security_number', 'recipient_ssn'];

        foreach ($fields as $fieldName => $fieldData) {
            $value = $fieldData['value'] ?? $fieldData ?? '';
            $confidence = $fieldData['confidence'] ?? 0.5;

            // Normalize to array structure if flat value
            if (! is_array($fieldData)) {
                $value = $fieldData;
                $confidence = 0.5;
            }

            // Intercept full SSN field names and rename to ssn_last4
            if (in_array($fieldName, $ssnFieldNames, true)) {
                $fieldName = 'ssn_last4';
            }

            // Strip any SSN-like value to last 4 digits for ssn/ssn_last4 fields
            if (str_contains(strtolower($fieldName), 'ssn') || str_contains(strtolower($fieldName), 'social_security')) {
                $digits = preg_replace('/\D/', '', (string) $value);
                if (strlen($digits) > 4) {
                    $value = substr($digits, -4);
                }
            }

            $sanitized[$fieldName] = [
                'value' => $value,
                'confidence' => (float) $confidence,
            ];
        }

        return [
            'fields' => $sanitized,
            'overall_confidence' => (float) ($data['overall_confidence'] ?? 0.0),
        ];
    }

    /**
     * Get the field schema for a given document category.
     */
    public function getFieldSchema(TaxDocumentCategory $category): array
    {
        return match ($category) {
            TaxDocumentCategory::W2 => self::W2_FIELDS,
            TaxDocumentCategory::NEC_1099 => self::NEC_1099_FIELDS,
            TaxDocumentCategory::INT_1099 => self::INT_1099_FIELDS,
            TaxDocumentCategory::MISC_1099 => self::MISC_1099_FIELDS,
            TaxDocumentCategory::DIV_1099 => self::DIV_1099_FIELDS,
            TaxDocumentCategory::K_1099 => self::K_1099_FIELDS,
            TaxDocumentCategory::S_1099 => self::S_1099_FIELDS,
            TaxDocumentCategory::R_1099 => self::R_1099_FIELDS,
            TaxDocumentCategory::G_1099 => self::G_1099_FIELDS,
            TaxDocumentCategory::B_1099 => self::B_1099_FIELDS,
            TaxDocumentCategory::Mortgage_1098 => self::MORTGAGE_1098_FIELDS,
            default => self::TIER2_FIELDS,
        };
    }

    /**
     * Build the multimodal content array for Claude API.
     */
    public function buildDocumentContent(TaxDocument $document): array
    {
        $disk = Storage::disk($document->disk);
        $fileContents = $disk->get($document->stored_path);

        if (! $fileContents) {
            throw new \RuntimeException("Could not read document file: {$document->stored_path}");
        }

        $base64 = base64_encode($fileContents);

        if ($document->mime_type === 'application/pdf') {
            return [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $base64,
                    ],
                ],
            ];
        }

        // Images (JPEG, PNG)
        return [
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $document->mime_type,
                    'data' => $base64,
                ],
            ],
        ];
    }

    /**
     * Fallback: detect document boundaries using extracted text instead of vision.
     * Used for oversized PDFs that exceed the API token limit.
     */
    protected function detectBoundariesFromText(TaxDocument $document, string $systemPrompt): array
    {
        $disk = Storage::disk($document->disk);
        $fileContents = $disk->get($document->stored_path);
        $tempPath = sys_get_temp_dir().'/tax_text_'.uniqid().'.pdf';
        file_put_contents($tempPath, $fileContents);

        try {
            // Extract text page-by-page using pdftotext
            $pageCount = (new \setasign\Fpdi\Fpdi)->setSourceFile($tempPath);
            $pageTexts = [];

            for ($page = 1; $page <= $pageCount; $page++) {
                try {
                    $text = (new \Spatie\PdfToText\Pdf)
                        ->setPdf($tempPath)
                        ->setOptions(["f {$page}", "l {$page}"])
                        ->text();
                    // Truncate each page to first 500 chars to stay within limits
                    $pageTexts[] = "=== PAGE {$page} ===\n".mb_substr(trim($text), 0, 500);
                } catch (\Throwable $e) {
                    $pageTexts[] = "=== PAGE {$page} ===\n[could not extract text]";
                }
            }

            $combinedText = implode("\n\n", $pageTexts);

            // Send as text-only request
            $content = [
                ['type' => 'text', 'text' => "Here is the text content extracted from a {$pageCount}-page PDF, page by page:\n\n{$combinedText}"],
            ];

            return $this->callClaude($systemPrompt, $content);

        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Call Claude API with multimodal content and retry logic.
     */
    protected function callClaude(string $systemPrompt, array $content): array
    {
        $maxRetries = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'system' => $systemPrompt,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => array_merge($content, [
                                ['type' => 'text', 'text' => 'Please analyze this document and respond with JSON only.'],
                            ]),
                        ],
                    ],
                ]);

                if (! $response->successful()) {
                    Log::warning('Claude API error during tax extraction', [
                        'status' => $response->status(),
                        'attempt' => $attempt + 1,
                        'body' => substr($response->body(), 0, 500),
                    ]);

                    // For "prompt too long" errors, don't retry — fail fast
                    $bodyText = $response->body();
                    if (str_contains($bodyText, 'too long')) {
                        return ['error' => "prompt is too long: {$bodyText}"];
                    }

                    if ($attempt < $maxRetries) {
                        sleep(2);

                        continue;
                    }

                    return ['error' => "API error: {$response->status()} — {$bodyText}"];
                }

                $text = $response->json('content.0.text');

                return $this->parseJsonResponse($text);

            } catch (\Exception $e) {
                Log::warning('Claude API exception during tax extraction', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < $maxRetries) {
                    sleep(2);

                    continue;
                }

                return ['error' => $e->getMessage()];
            }
        }

        return ['error' => 'Max retries exceeded'];
    }

    /**
     * Parse JSON response from Claude, handling markdown fences and repair.
     */
    protected function parseJsonResponse(string $text): array
    {
        // Strip markdown fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', $text);
        $text = trim($text);

        // Remove control characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Tax extraction JSON parse failed, attempting repair', [
                'error' => json_last_error_msg(),
                'text_length' => strlen($text),
                'first_100' => substr($text, 0, 100),
            ]);

            // Try to extract JSON object from response
            if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
                $extracted = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $matches[0]);
                $decoded = json_decode($extracted, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            Log::error('Tax extraction JSON parse failed completely', [
                'error' => json_last_error_msg(),
                'text_preview' => substr($text, 0, 500),
            ]);

            return ['error' => 'Invalid JSON response'];
        }

        return $decoded;
    }
}
