<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccountPurposeRequest;
use App\Http\Resources\BankAccountResource;
use App\Jobs\CategorizePendingTransactions;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

class BankAccountController extends Controller
{
    /**
     * List all linked accounts with purpose labels and summary.
     */
    public function index(): JsonResponse
    {
        $accounts = BankAccount::where('user_id', auth()->id())
            ->where('is_active', true)
            ->with('bankConnection:id,institution_name,status,last_synced_at')
            ->get();

        // Summary by purpose
        $personal = $accounts->where('purpose', 'personal');
        $business = $accounts->where('purpose', 'business');
        $mixed    = $accounts->where('purpose', 'mixed');

        return response()->json([
            'accounts' => BankAccountResource::collection($accounts),
            'summary'  => [
                'personal_accounts' => $personal->count(),
                'business_accounts' => $business->count(),
                'mixed_accounts'    => $mixed->count(),
                'personal_balance'  => $personal->sum('current_balance'),
                'business_balance'  => $business->sum('current_balance'),
            ],
        ]);
    }

    /**
     * Set or update an account's purpose (personal/business/mixed).
     * Optionally re-categorize all its transactions.
     */
    public function updatePurpose(UpdateAccountPurposeRequest $request, BankAccount $account): JsonResponse
    {
        $validated = $request->validated();

        $oldPurpose = $account->purpose;
        $account->update($validated);

        // If purpose changed, update all transactions from this account
        if ($oldPurpose !== $validated['purpose']) {
            $newPurpose = $validated['purpose'];

            // Bulk update the denormalized field on transactions
            $updated = Transaction::where('bank_account_id', $account->id)
                ->update(['account_purpose' => $newPurpose]);

            // Bulk update expense_type defaults for transactions that
            // haven't been manually categorized by the user yet
            Transaction::where('bank_account_id', $account->id)
                ->whereNull('user_category')
                ->update([
                    'expense_type'   => match ($newPurpose) {
                        'business' => 'business',
                        'mixed'    => 'mixed',
                        default    => 'personal',
                    },
                    'tax_deductible' => $newPurpose === 'business',
                    'review_status'  => 'pending_ai', // Queue for re-categorization
                ]);

            // Re-run AI categorization with the new account context
            CategorizePendingTransactions::dispatch(auth()->id());

            return response()->json([
                'message'              => "Account updated to '{$newPurpose}'. {$updated} transactions being re-categorized.",
                'account'              => new BankAccountResource($account->fresh()),
                'transactions_updated' => $updated,
            ]);
        }

        return response()->json([
            'message' => 'Account updated.',
            'account' => new BankAccountResource($account->fresh()),
        ]);
    }
}
