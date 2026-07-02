<?php

namespace App\Services;

use App\Models\UserTaxFact;
use Illuminate\Support\Collection;

/**
 * HsaShoeboxService — STORE-03.
 *
 * Records out-of-pocket medical receipts as durable UserTaxFact rows with a
 * namespaced key `hsa_shoebox.<vault_document_id>`. These facts accumulate in the
 * append-only store, forming the user's "HSA shoebox" — a list of medical expenses
 * that may be reimbursable from their HSA at any future date.
 *
 * EDUCATION COPY RULE (non-negotiable per STORE-03 / D2):
 *   Education copy is provided as a public constant. It MUST:
 *   (a) Only apply to expenses incurred AFTER the HSA was opened
 *   (b) NEVER state that pre-establishment expenses qualify
 *   (c) Use "may be reimbursable" / conditional framing only
 *
 * SECURITY:
 *   - fact value ($hidden) stores amount-cents-as-string (encrypted TEXT column)
 *   - metadata is non-PII context (vault_document_id, incurred_on, description)
 *   - volatility='permanent' — receipts don't expire; IRS rule has no time limit
 *     on when HSA-eligible expenses can be reimbursed after establishment
 *
 * FACT KEY PATTERN: `hsa_shoebox.<vault_document_id>`
 *   e.g. `hsa_shoebox.4271` for vault document ID 4271
 *   This namespacing ensures each receipt is a separate fact row and the prefix
 *   can be used for prefix-filtered listing via GET /api/v1/optimizer/facts.
 */
class HsaShoeboxService
{
    /**
     * Education copy — approved wording for STORE-03.
     *
     * DISPLAY RULE: only show this text AFTER the user has established an HSA.
     * Never imply that expenses incurred before the HSA was opened qualify.
     *
     * This constant is the ONLY approved education copy for the shoebox feature.
     * Do not paraphrase or modify without owner review.
     */
    public const EDUCATION_COPY =
        'Medical expenses you incurred after opening your HSA may be reimbursable tax-free '
        .'in any future year, as long as you keep your receipts. '
        .'The IRS sets no deadline for reimbursement after the HSA was established. '
        .'Consider discussing your specific situation with a tax professional.';

    /**
     * Record an out-of-pocket medical receipt into the HSA shoebox.
     *
     * Stores a permanent, append-only UserTaxFact with:
     *   fact_key   = 'hsa_shoebox.<vault_document_id>'
     *   value      = amount-cents-as-string (encrypted, $hidden)
     *   volatility = 'permanent'
     *   metadata   = { vault_document_id, incurred_on (Y-m-d), description }
     *
     * @param  int  $userId  Authenticated user (scopeForUser)
     * @param  int  $vaultDocumentId  TaxDocument.id of the uploaded receipt
     * @param  int  $amountCents  Expense amount in integer cents
     * @param  string  $incurredOn  Date expense was incurred (Y-m-d format)
     * @param  string  $description  Brief description (e.g. "Urgent care co-pay")
     * @param  int  $taxYear  Tax year the expense belongs to
     */
    public function addReceipt(
        int $userId,
        int $vaultDocumentId,
        int $amountCents,
        string $incurredOn,
        string $description,
        int $taxYear,
    ): UserTaxFact {
        // Namespaced key ensures one fact per vault document — idempotent on resubmit
        $factKey = "hsa_shoebox.{$vaultDocumentId}";

        return UserTaxFact::recordFact(
            userId: $userId,
            factKey: $factKey,
            value: (string) $amountCents,   // integer cents as string, encrypted
            sourceType: 'user_edit',             // user-initiated upload = current immediately
            label: "HSA shoebox receipt: {$description}",
            volatility: 'permanent',             // no expiry — IRS has no reimbursement deadline
            taxYear: $taxYear,
            sourceId: (string) $vaultDocumentId,
            metadata: [
                'vault_document_id' => $vaultDocumentId,
                'incurred_on' => $incurredOn,
                'description' => $description,
            ],
        );
    }

    /**
     * List all HSA shoebox receipts for a user.
     *
     * Returns current (not superseded) shoebox facts for the user,
     * ordered by tax_year DESC then asserted_at DESC.
     *
     * The encrypted fact value is excluded via UserTaxFact::$hidden.
     * Metadata carries vault_document_id, incurred_on, description for display.
     *
     * ACCESS PATTERN: Callers should filter via GET /api/v1/optimizer/facts
     * using fact_key prefix 'hsa_shoebox.' — this method supports direct
     * service-layer access (e.g. for assembler or reporting).
     *
     * @return Collection<int, UserTaxFact>
     */
    public function listByUser(int $userId): Collection
    {
        return UserTaxFact::forUser($userId)
            ->where('fact_key', 'like', 'hsa_shoebox.%')
            ->where('is_current', true)
            ->orderByDesc('tax_year')
            ->orderByDesc('asserted_at')
            ->get();
    }

    /**
     * Calculate the total amount in the shoebox for a given tax year.
     *
     * Returns total in integer cents. Value column is encrypted — fetched via
     * model accessor and summed.
     *
     * @return int Total cents
     */
    public function totalForYear(int $userId, int $taxYear): int
    {
        return UserTaxFact::forUser($userId)
            ->where('fact_key', 'like', 'hsa_shoebox.%')
            ->where('tax_year', $taxYear)
            ->where('is_current', true)
            ->get()
            ->sum(fn (UserTaxFact $fact) => (int) ($fact->getRawOriginal('value')
                ? decrypt($fact->getRawOriginal('value'))
                : 0));
    }
}
