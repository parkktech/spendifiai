<?php

namespace App\Services;

use App\Enums\AccountPurpose;
use App\Enums\ExpenseType;
use App\Models\BankAccount;
use App\Models\OptimizationFinding;
use App\Models\Transaction;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OptimizationProReviewExportService — RPT-05.
 *
 * Generates a professional review packet PDF for a single OptimizationFinding.
 * Mirrors TaxExportService dompdf pattern exactly.
 *
 * Packet contents (all static config or user-asserted data — never Claude output):
 *   1. Fact-pattern narrative (from finding.description)
 *   2. Timestamped user_assertions[] log
 *   3. docs_captured document references
 *   4. STATIC legal_basis + citations from finding (written by TaxRulesEngineService)
 *   5. STATIC defensibility rating from config (band → rating map)
 *   6. The professional question (from finding.details or constructed from finding_type)
 *
 * SECURITY (T-12-04-02):
 *   - Blocked (throws exception) when docs_missing is non-empty OR pro_export_ready=false
 *   - Every PDF carries the persistent educational disclaimer + unverified-facts notice
 *   - legal_basis/citations are from static config/DB fields — never Claude output
 *   - user_assertions are $hidden on the model; decrypted only within this service
 *
 * SAFE-03: This service does NOT read or write estimated_value_cents.
 */
class OptimizationProReviewExportService
{
    /**
     * Generate the professional review packet PDF for a finding.
     *
     * @return string Absolute path to the generated PDF file.
     *
     * @throws \InvalidArgumentException When the finding is not export-ready (docs_missing, pro_export_ready=false).
     * @throws \RuntimeException When PDF generation fails.
     */
    public function generatePacket(OptimizationFinding $finding): string
    {
        // BLOCK export when any docs are missing or pro_export_ready is false (RPT-05 gate)
        $this->assertExportReady($finding);

        ini_set('memory_limit', '512M');

        $user = $finding->user;
        $timestamp = now()->format('Y-m-d_His');
        $dir = "optimization-pro-review/{$finding->user_id}";

        Storage::disk('local')->makeDirectory($dir);

        $outputPath = Storage::disk('local')->path(
            "{$dir}/ProReview_{$finding->finding_key}_{$timestamp}.pdf"
        );

        $data = $this->buildPacketData($finding);

        $pdf = Pdf::loadView('pdf.optimization-pro-review', $data);
        $pdf->setPaper('letter', 'portrait');
        $pdf->save($outputPath);

        if (! file_exists($outputPath)) {
            throw new RuntimeException(
                'Pro-review packet PDF generation failed: file not found at '.$outputPath
            );
        }

        Log::info('OptimizationProReviewExportService: packet generated', [
            'user_id' => $finding->user_id,
            'finding_key' => $finding->finding_key,
            'path' => $outputPath,
        ]);

        return $outputPath;
    }

    /**
     * Assert the finding is ready for pro-review export.
     *
     * Conditions that block export (422 — T-12-04-02 mitigation):
     *   - docs_missing is a non-empty array (required documents not yet uploaded)
     *   - pro_export_ready is explicitly false (review gate not cleared)
     *
     * @throws \InvalidArgumentException
     */
    public function assertExportReady(OptimizationFinding $finding): void
    {
        $docsMissing = $finding->docs_missing ?? [];

        if (! empty($docsMissing)) {
            throw new \InvalidArgumentException(
                'Pro-review export blocked: required documents are missing — '.
                implode(', ', $docsMissing)
            );
        }

        if ($finding->pro_export_ready === false) {
            throw new \InvalidArgumentException(
                'Pro-review export blocked: finding is not marked pro_export_ready.'
            );
        }
    }

    /**
     * Build the view data array for the Blade template.
     *
     * All monetary columns (estimated_value_cents, net_cash_cost, etc.) are
     * deliberately absent from this payload (SAFE-03).
     */
    protected function buildPacketData(OptimizationFinding $finding): array
    {
        $band = $finding->band ?? 'conditional';
        $defensibilityRating = $this->resolveDefensibilityRating($band);
        $defensibilityDescription = $this->getDefensibilityDescription($defensibilityRating);

        $userAssertions = $this->decryptUserAssertions($finding);
        $professionalQuestion = $this->buildProfessionalQuestion($finding);

        $disclaimer = config(
            'optimization-report.pro_review_disclaimer',
            'EDUCATIONAL MATERIAL ONLY — NOT TAX ADVICE. This packet was prepared from information self-asserted by the user and has not been independently verified.'
        );

        // D22 — cross-account business-activity map + commingling picture
        // SAFE-03: no dollar amounts — counts and categories only
        $user = $finding->user;
        $taxYear = $finding->tax_year ?? (int) date('Y');
        $crossAccountMap = $user
            ? $this->buildCrossAccountBusinessMap($user, $taxYear)
            : [];
        $comminglingPicture = $user
            ? $this->buildComminglingPicture($user, $taxYear)
            : [];
        $documentationStatus = $this->buildDocumentationStatus($finding);

        return [
            'user_name' => $finding->user?->name ?? 'User',
            'user_email' => $finding->user?->email ?? '',
            'tax_year' => $taxYear,
            'generated_at' => now()->format('F j, Y'),
            'finding_type_label' => $this->humanizeFindingType($finding->finding_type),
            'severity' => $finding->severity ?? 'medium',
            'band' => $band,
            'description' => $finding->description ?? 'No description available for this finding.',
            'user_assertions' => $userAssertions,
            'docs_captured' => $finding->docs_captured ?? [],
            'legal_basis' => $finding->legal_basis,
            'assumptions' => $finding->assumptions ?? [],
            'defensibility_rating' => $defensibilityRating,
            'defensibility_description' => $defensibilityDescription,
            'professional_question' => $professionalQuestion,
            'pro_review_disclaimer' => $disclaimer,
            // D22 additive sections (14-11)
            'cross_account_map' => $crossAccountMap,
            'commingling_picture' => $comminglingPicture,
            'documentation_status' => $documentationStatus,
        ];
    }

    /**
     * Resolve the static defensibility rating from the finding's band.
     *
     * Source: config/optimization-report.php defensibility_ratings map.
     * Never computed from user data — always a static config lookup.
     */
    protected function resolveDefensibilityRating(string $band): string
    {
        $map = config('optimization-report.defensibility_ratings', []);

        return $map[$band] ?? 'fact-dependent';
    }

    /**
     * Get the human-readable description for a defensibility rating.
     */
    protected function getDefensibilityDescription(string $rating): string
    {
        return match ($rating) {
            'solid' => 'This area is generally well-established in the tax code. The underlying treatment has broad acceptance and a strong track record when properly documented.',
            'fact-dependent' => 'The correct treatment depends significantly on the specific facts of your situation. Small differences in facts can lead to materially different outcomes. Professional evaluation of your specific circumstances is important.',
            'frequently-abused' => 'This area is subject to frequent IRS scrutiny and has been identified as an area where improper claims are common. Arrangements in this category require careful professional review to ensure they reflect genuine economic substance and comply with applicable rules.',
            default => 'The defensibility of this item depends on professional evaluation of your specific facts.',
        };
    }

    /**
     * Decrypt and format user_assertions for the PDF template.
     *
     * user_assertions is encrypted on the model; we decrypt only here for the
     * PDF generation path (T-12-04-02: facts leave system in educational packet only).
     */
    protected function decryptUserAssertions(OptimizationFinding $finding): array
    {
        try {
            // Access the encrypted field — Laravel's 'encrypted' cast handles decryption
            $raw = $finding->user_assertions;

            if (empty($raw)) {
                return [];
            }

            // If it's a JSON string (when stored as plain string), decode it
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            return is_array($raw) ? $raw : [];
        } catch (\Throwable $e) {
            Log::warning('OptimizationProReviewExportService: could not decrypt user_assertions', [
                'finding_id' => $finding->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the question for the professional from the finding's details or type.
     */
    protected function buildProfessionalQuestion(OptimizationFinding $finding): string
    {
        // Check for an explicit question in details
        $details = $finding->details ?? [];
        if (! empty($details['professional_question'])) {
            return $details['professional_question'];
        }

        // Construct a type-based default question
        return $this->defaultQuestionForType($finding->finding_type ?? '');
    }

    /**
     * Return a default professional question based on finding type.
     */
    protected function defaultQuestionForType(string $findingType): string
    {
        return match (true) {
            str_contains($findingType, 'retirement') => 'Based on the retirement-related information in this packet, are there contribution strategies, plan types, or timing considerations I should evaluate for my situation?',
            str_contains($findingType, 'deduction') || str_contains($findingType, 'saas') || str_contains($findingType, 'business') => 'Based on the deduction-related information in this packet, what documentation or substantiation would I need to properly claim these items?',
            str_contains($findingType, 'withholding') || str_contains($findingType, 'estimated') => 'Based on the information in this packet, what steps should I take to ensure I am not under-withholding or subject to underpayment penalties?',
            str_contains($findingType, 'filing') || str_contains($findingType, 'entity') => 'Based on the filing-related information in this packet, are there structural or filing choices that I should be aware of for my situation?',
            str_contains($findingType, 'hsa') || str_contains($findingType, 'benefit') => 'Based on the benefit-related information in this packet, am I taking full advantage of tax-advantaged accounts available to me?',
            default => 'Given the information in this packet, what are the key tax considerations I should discuss with you for my specific situation?',
        };
    }

    /**
     * D22 — Cross-account business-activity map (14-11 additive section).
     *
     * Aggregates Schedule C (business expense_type) transactions by bank account
     * for the tax year. Grouped by account then category.
     *
     * SAFE-03: Returns COUNTS and CATEGORIES — never dollar amounts.
     *
     * @return array<int, array{account_label: string, account_purpose: string, business_count: int, categories: array}>
     */
    public function buildCrossAccountBusinessMap(User $user, int $taxYear): array
    {
        $accounts = BankAccount::where('user_id', $user->id)->get()->keyBy('id');

        if ($accounts->isEmpty()) {
            return [];
        }

        // Load all business-type transactions for the year, grouped by account + category
        $rows = Transaction::where('user_id', $user->id)
            ->where('expense_type', ExpenseType::Business->value)
            ->whereYear('transaction_date', $taxYear)
            ->whereNotNull('bank_account_id')
            ->selectRaw('bank_account_id, COALESCE(user_category, ai_category, \'Uncategorized\') AS category, COUNT(*) AS cnt')
            ->groupBy('bank_account_id', 'category')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Group by account
        $byAccount = [];
        foreach ($rows as $row) {
            $acctId = $row->bank_account_id;
            $account = $accounts->get($acctId);
            if (! $account) {
                continue;
            }

            $label = $account->nickname
                ?? ($account->name ?? ('Account ending in '.substr((string) $acctId, -4)));
            $purpose = $account->purpose instanceof AccountPurpose
                ? $account->purpose->value
                : (string) $account->purpose;

            if (! isset($byAccount[$acctId])) {
                $byAccount[$acctId] = [
                    'account_label' => $label,
                    'account_purpose' => $purpose,
                    'business_count' => 0,
                    'categories' => [],
                ];
            }

            $byAccount[$acctId]['business_count'] += (int) $row->cnt;
            $byAccount[$acctId]['categories'][] = [
                'category' => (string) $row->category,
                'count' => (int) $row->cnt,
            ];
        }

        // Sort categories by count desc within each account
        foreach ($byAccount as &$entry) {
            usort($entry['categories'], fn ($a, $b) => $b['count'] <=> $a['count']);
        }

        // Sort accounts by total business count desc
        $result = array_values($byAccount);
        usort($result, fn ($a, $b) => $b['business_count'] <=> $a['business_count']);

        return $result;
    }

    /**
     * D22 — Commingling picture (14-11 additive section).
     *
     * Shows which accounts have mixed personal/business activity.
     * "Label ≠ behavior" (D21 addendum): we check observed expense_type values,
     * not the account purpose label.
     *
     * SAFE-03: Returns COUNTS — never dollar amounts.
     *
     * @return array{
     *   mixed_accounts: array,
     *   business_in_personal: int,
     *   personal_in_business: int,
     *   has_commingling: bool,
     * }
     */
    public function buildComminglingPicture(User $user, int $taxYear): array
    {
        $accounts = BankAccount::where('user_id', $user->id)->get();

        if ($accounts->isEmpty()) {
            return ['mixed_accounts' => [], 'business_in_personal' => 0, 'personal_in_business' => 0, 'has_commingling' => false];
        }

        $accountIds = $accounts->pluck('id');
        $accountMap = $accounts->keyBy('id');

        // Count by (account_id, expense_type)
        $counts = Transaction::where('user_id', $user->id)
            ->whereIn('bank_account_id', $accountIds)
            ->whereYear('transaction_date', $taxYear)
            ->whereIn('expense_type', [ExpenseType::Business->value, ExpenseType::Personal->value])
            ->selectRaw('bank_account_id, expense_type, COUNT(*) AS cnt')
            ->groupBy('bank_account_id', 'expense_type')
            ->get();

        // Build per-account summary
        $perAccount = [];
        foreach ($counts as $row) {
            $id = $row->bank_account_id;
            if (! isset($perAccount[$id])) {
                $perAccount[$id] = ['business' => 0, 'personal' => 0];
            }
            if ($row->expense_type === ExpenseType::Business->value) {
                $perAccount[$id]['business'] = (int) $row->cnt;
            } else {
                $perAccount[$id]['personal'] = (int) $row->cnt;
            }
        }

        $mixedAccounts = [];
        $businessInPersonal = 0;
        $personalInBusiness = 0;

        foreach ($perAccount as $acctId => $typeCounts) {
            $account = $accountMap->get($acctId);
            if (! $account) {
                continue;
            }

            $purpose = $account->purpose instanceof AccountPurpose
                ? $account->purpose->value
                : (string) $account->purpose;

            $hasBusinessTxns = $typeCounts['business'] > 0;
            $hasPersonalTxns = $typeCounts['personal'] > 0;

            if ($hasBusinessTxns && $hasPersonalTxns) {
                $label = $account->nickname
                    ?? ($account->name ?? ('Account ending in '.substr((string) $acctId, -4)));
                $mixedAccounts[] = [
                    'account_label' => $label,
                    'account_purpose' => $purpose,
                    'business_count' => $typeCounts['business'],
                    'personal_count' => $typeCounts['personal'],
                ];
            }

            // "business in personal" = business txns on personal/mixed accounts
            if (in_array($purpose, [AccountPurpose::Personal->value, AccountPurpose::Mixed->value], true)) {
                $businessInPersonal += $typeCounts['business'];
            }
            // "personal in business" = personal txns on business accounts
            if ($purpose === AccountPurpose::Business->value) {
                $personalInBusiness += $typeCounts['personal'];
            }
        }

        return [
            'mixed_accounts' => $mixedAccounts,
            'business_in_personal' => $businessInPersonal,
            'personal_in_business' => $personalInBusiness,
            'has_commingling' => $businessInPersonal > 0 || $personalInBusiness > 0,
        ];
    }

    /**
     * D22 — Documentation status per deduction (14-11 additive section).
     *
     * Returns a map of docs_captured vs docs_required for the finding.
     * All determinations are static: required doc types come from config;
     * captured doc types come from finding.docs_captured.
     *
     * @return array{required: string[], captured: string[], missing: string[], complete: bool}
     */
    public function buildDocumentationStatus(OptimizationFinding $finding): array
    {
        $required = config(
            "optimization-report.required_docs.{$finding->finding_type}",
            config('optimization-report.required_docs.default', [])
        );

        $captured = $finding->docs_captured ?? [];
        $capturedLower = array_map('strtolower', $captured);

        $missing = array_values(array_filter(
            $required,
            fn ($req) => ! in_array(strtolower($req), $capturedLower, true)
        ));

        return [
            'required' => $required,
            'captured' => $captured,
            'missing' => $missing,
            'complete' => empty($missing),
        ];
    }

    /**
     * Convert a finding_type snake_case key to a human-readable label.
     */
    protected function humanizeFindingType(string $findingType): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $findingType));
    }
}
