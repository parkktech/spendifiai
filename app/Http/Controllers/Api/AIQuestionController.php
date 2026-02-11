<?php

namespace App\Http\Controllers\Api;

use App\Events\UserAnsweredQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerQuestionRequest;
use App\Http\Requests\BulkAnswerRequest;
use App\Http\Resources\AIQuestionResource;
use App\Http\Resources\TransactionResource;
use App\Models\AIQuestion;
use App\Services\AI\TransactionCategorizerService;
use Illuminate\Http\JsonResponse;

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
        $questions = AIQuestion::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->with('transaction:id,merchant_name,amount,transaction_date,description')
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

        return response()->json([
            'message'     => 'Answer recorded',
            'transaction' => new TransactionResource($question->transaction->fresh()),
        ]);
    }

    /**
     * Bulk answer multiple AI questions at once.
     */
    public function bulkAnswer(BulkAnswerRequest $request): JsonResponse
    {
        $processed = 0;

        foreach ($request->validated('answers') as $item) {
            $question = AIQuestion::where('id', $item['question_id'])
                ->where('user_id', auth()->id())
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
