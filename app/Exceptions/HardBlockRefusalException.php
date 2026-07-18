<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SAFE-06 Hard-Block Refusal Exception.
 *
 * Thrown when HardBlockRefusalService::check() detects an abusive-scheme signal
 * in the user's escape-hatch free text (InterviewOrchestratorService::recordAnswer).
 *
 * The render() method returns the structured refusal as a 200 JSON response —
 * so the interview path self-renders the refusal without any controller-flow change
 * (additive, scope-safe per CLAUDE.md Rule 9).
 *
 * Response shape (D18/D19 compliant):
 *   {
 *     "refused": true,
 *     "category": "<cluster label>",
 *     "education": "<what/why copy — never how>",
 *     "best_effort_disclaimer": "<verbatim config copy>",
 *     "blocked_reason": "hard_block_safe06"
 *   }
 */
class HardBlockRefusalException extends Exception
{
    /**
     * @param  array{refused: bool, category: string, education: string, best_effort_disclaimer: string, blocked_reason: string}  $refusal
     */
    public function __construct(private readonly array $refusal)
    {
        parent::__construct('SAFE-06 hard-block refusal: '.$refusal['category']);
    }

    /**
     * Render the exception as a JSON response.
     *
     * HTTP 200 is intentional — the client receives a structured "what/why" educational
     * payload rather than an error. A 4xx would suggest the request was malformed;
     * this response is a deliberate policy decision, not a client error.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json($this->refusal, 200);
    }
}
