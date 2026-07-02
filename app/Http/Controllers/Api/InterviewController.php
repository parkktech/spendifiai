<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerOptimizationQuestionRequest;
use App\Models\AIQuestion;
use App\Models\InterviewSession;
use App\Services\InterviewOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interview controller — manages the one-question-at-a-time guided interview (INT-01..07).
 *
 * ROUTES (all under /api/v1/optimizer/interview):
 *   GET   /               - list sessions for current user
 *   POST  /start          - start or resume session for tax year
 *   GET   /{interview}/next - get next question
 *   POST  /{interview}/answer - submit answer to current question
 *
 * SECURITY (T-11-04-03): all endpoints are scoped to auth user via policy.
 * InterviewSessionPolicy ensures cross-user access is blocked at the policy level.
 */
class InterviewController extends Controller
{
    public function __construct(
        private readonly InterviewOrchestratorService $orchestrator,
    ) {}

    /**
     * List all interview sessions for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = InterviewSession::forUser($request->user()->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'tax_year', 'status', 'initial_cap', 'created_at', 'updated_at']);

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Start or resume an interview session for a tax year.
     * Idempotent: returns the existing in-progress session if one exists.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'tax_year' => 'required|integer|min:2020|max:2030',
        ]);

        $session = $this->orchestrator->startOrResume(
            $request->user()->id,
            (int) $request->input('tax_year')
        );

        return response()->json([
            'session' => $session->only(['id', 'tax_year', 'status', 'initial_cap', 'created_at']),
            'queue_size' => count($session->queue ?? []),
            'asked_count' => count($session->asked ?? []),
        ]);
    }

    /**
     * Get the next question in the session queue.
     * Returns null if the queue is exhausted (session completes).
     */
    public function next(Request $request, InterviewSession $interview): JsonResponse
    {
        $this->authorize('update', $interview);

        $question = $this->orchestrator->nextQuestion($interview);

        if ($question === null) {
            return response()->json([
                'question' => null,
                'session_status' => $interview->fresh()->status,
                'message' => 'Interview complete — no more questions in this session.',
            ]);
        }

        return response()->json([
            'question' => [
                'id' => $question->id,
                'question' => $question->question,
                'question_type' => $question->question_type,
                'options' => $question->options,
                'ai_confidence' => $question->ai_confidence,
                'ai_best_guess' => $question->ai_best_guess,
                'band' => $question->options['band'] ?? null,
                'suggested_treatment' => $question->options['suggested_treatment'] ?? null,
                'transaction_count' => count($question->options['transaction_ids'] ?? []),
            ],
            'session_status' => $interview->fresh()->status,
        ]);
    }

    /**
     * Submit an answer to the current interview question.
     *
     * Writes to UserTaxFact via InterviewOrchestratorService::recordAnswer().
     * SAFE-03: never writes estimated_value_cents.
     */
    public function answer(
        AnswerOptimizationQuestionRequest $request,
        InterviewSession $interview,
        AIQuestion $question
    ): JsonResponse {
        $this->authorize('update', $interview);

        $factKey = $question->options['fact_key'] ?? $question->ai_best_guess;
        $answerValue = $request->validated('answer');

        // Record the answer — writes UserTaxFact + transcript (FEED-03 / INT-05)
        $this->orchestrator->recordAnswer(
            session: $interview,
            factKey: $factKey,
            value: $answerValue,
            questionText: $question->question,
            questionId: $question->id,
        );

        // Mark the AIQuestion as answered
        $question->update([
            'user_answer' => $answerValue,
            'status' => 'answered',
            'answered_at' => now(),
        ]);

        return response()->json([
            'message' => 'Answer recorded.',
            'fact_key' => $factKey,
            'session_status' => $interview->fresh()->status,
        ]);
    }
}
