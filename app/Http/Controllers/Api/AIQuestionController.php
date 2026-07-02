<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuestionType;
use App\Events\UserAnsweredQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerQuestionRequest;
use App\Http\Requests\BulkAnswerRequest;
use App\Http\Requests\ChatQuestionRequest;
use App\Http\Resources\AIQuestionResource;
use App\Http\Resources\TransactionResource;
use App\Jobs\SearchTransactionEmails;
use App\Models\AIQuestion;
use App\Models\EmailConnection;
use App\Services\AI\TransactionCategorizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIQuestionController extends Controller
{
    public function __construct(
        private readonly TransactionCategorizerService $categorizer,
    ) {}

    /**
     * List pending AI questions with their associated transactions.
     */
    public function index(): JsonResponse
    {
        $userIds = auth()->user()->householdUserIds();

        // Clean up stale questions whose transactions were already resolved.
        // FEED-04 / Pitfall 6: exclude optimization questions because they have
        // no transaction (transaction_id = null). The whereHas clause would never
        // match them, but the explicit exclusion makes the intent unambiguous and
        // prevents future regressions if the query is modified.
        AIQuestion::whereIn('user_id', $userIds)
            ->where('status', 'pending')
            ->where('question_type', '!=', QuestionType::Optimization->value)
            ->whereHas('transaction', function ($q) {
                $q->whereIn('review_status', ['user_confirmed', 'auto_categorized']);
            })
            ->update([
                'status' => 'answered',
                'user_answer' => 'Auto-resolved',
                'answered_at' => now(),
            ]);

        $questions = AIQuestion::whereIn('user_id', $userIds)
            ->where('status', 'pending')
            ->with('transaction:id,merchant_name,merchant_normalized,amount,transaction_date,description,ai_category,user_category,ai_confidence,review_status,expense_type,account_purpose,tax_deductible,is_subscription')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(AIQuestionResource::collection($questions));
    }

    /**
     * Answer a single AI question.
     */
    public function answer(AnswerQuestionRequest $request, AIQuestion $question): JsonResponse
    {
        $this->categorizer->handleUserAnswer($question, $request->validated('answer'));

        UserAnsweredQuestion::dispatch($question->fresh(), $request->user());

        // FEED-04 / D7: optimization questions have no transaction; return null
        // for the transaction field rather than crashing on ->fresh() of null.
        $transaction = $question->transaction_id
            ? new TransactionResource($question->transaction->fresh())
            : null;

        return response()->json([
            'message' => 'Answer recorded',
            'transaction' => $transaction,
        ]);
    }

    /**
     * Chat with AI about a question — get a suggested category from free-text context.
     */
    public function chat(ChatQuestionRequest $request, AIQuestion $question): JsonResponse
    {
        $this->authorize('update', $question);

        $suggestion = $this->categorizer->interpretUserResponse(
            $question,
            $request->validated('message')
        );

        return response()->json($suggestion);
    }

    /**
     * Search connected email accounts for receipts matching this question's transaction.
     */
    public function searchEmails(Request $request, AIQuestion $question): JsonResponse
    {
        $this->authorize('update', $question);

        $userIds = $request->user()->householdUserIds();
        $connections = EmailConnection::whereIn('user_id', $userIds)
            ->where('status', 'active')
            ->count();

        if ($connections === 0) {
            return response()->json([
                'message' => 'No email accounts connected. Connect one on the Connect page first.',
            ], 422);
        }

        $question->update(['email_search_status' => 'searching']);

        SearchTransactionEmails::dispatch($question->transaction);

        return response()->json([
            'message' => 'Email search started. We\'ll check your connected accounts for matching receipts.',
        ], 202);
    }

    /**
     * Bulk answer multiple AI questions at once.
     */
    public function bulkAnswer(BulkAnswerRequest $request): JsonResponse
    {
        $processed = 0;

        foreach ($request->validated('answers') as $item) {
            $question = AIQuestion::where('id', $item['question_id'])
                ->whereIn('user_id', auth()->user()->householdUserIds())
                ->first();

            if ($question && $question->status->value === 'pending') {
                $this->categorizer->handleUserAnswer($question, $item['answer']);
                UserAnsweredQuestion::dispatch($question->fresh(), $request->user());
                $processed++;
            }
        }

        return response()->json(['processed' => $processed]);
    }
}
