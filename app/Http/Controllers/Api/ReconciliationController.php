<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReconciliationController extends Controller
{
    /**
     * List pending reconciliation candidates for the authenticated user.
     */
    public function candidates(Request $request): JsonResponse
    {
        $candidates = ReconciliationCandidate::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->with(['transaction:id,merchant_name,merchant_normalized,amount,transaction_date', 'order:id,merchant,merchant_normalized,total,order_date'])
            ->orderByDesc('confidence')
            ->get();

        return response()->json(['candidates' => $candidates]);
    }

    /**
     * Confirm a reconciliation candidate — link the transaction to the order.
     */
    public function confirm(Request $request, ReconciliationCandidate $candidate): JsonResponse
    {
        if ($candidate->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($candidate->status !== 'pending') {
            return response()->json(['message' => 'This candidate has already been reviewed.'], 422);
        }

        DB::transaction(function () use ($candidate) {
            $candidate->transaction->update([
                'matched_order_id' => $candidate->order_id,
                'is_reconciled' => true,
            ]);

            $candidate->order->update([
                'matched_transaction_id' => $candidate->transaction_id,
                'is_reconciled' => true,
            ]);

            $candidate->update([
                'status' => 'confirmed',
                'reviewed_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Match confirmed successfully.']);
    }

    /**
     * Reject a reconciliation candidate — mark as rejected so it won't appear again.
     */
    public function reject(Request $request, ReconciliationCandidate $candidate): JsonResponse
    {
        if ($candidate->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($candidate->status !== 'pending') {
            return response()->json(['message' => 'This candidate has already been reviewed.'], 422);
        }

        $candidate->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
        ]);

        return response()->json(['message' => 'Match rejected.']);
    }
}
