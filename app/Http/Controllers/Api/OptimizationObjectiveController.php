<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ObjectiveReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OptimizationObjectiveController — objective readiness + gap enqueue (SCN-01 / §E).
 *
 * ROUTES (all under /api/v1/optimizer/objectives, auth:sanctum, NO bank.connected):
 *   GET  /{year}                     — readiness projection (keys/labels/states only)
 *   POST /{year}/{objective}/enqueue — front-insert templated gap questions (§A.4.1)
 *
 * SECURITY:
 *   - show() body carries NO money values (§E, T-14-05-03) — safe to log.
 *   - {objective} validated against the three readiness objectives → 404 otherwise
 *     (M4 / Pitfall 2; scenario domains like bonus_election are NOT valid here).
 *   - All operations are scoped to auth()->id().
 */
class OptimizationObjectiveController extends Controller
{
    public function __construct(
        private readonly ObjectiveReadinessService $readiness,
    ) {}

    /**
     * GET /optimizer/objectives/{year} — §E projection: keys, labels, states only.
     */
    public function show(Request $request, int $year): JsonResponse
    {
        $readiness = $this->readiness->readiness($request->user(), $year);

        $objectives = [];
        foreach ($readiness as $key => $data) {
            $objectives[$key] = [
                'label' => $data['label'],
                'ready' => $data['ready'],
                'completeness_pct' => $data['completeness_pct'],
                'questions_to_unlock' => $data['questions_to_unlock'],
                'blocking' => $data['blocking'],                 // {fact_key,label,state,source}
                'confirm_needed' => $data['confirm_needed'],     // {fact_key,label}
                'optional_missing' => $data['optional_missing'], // {fact_key,label,default_note}
            ];
        }

        return response()->json([
            'tax_year' => $year,
            'objectives' => $objectives,
        ]);
    }

    /**
     * POST /optimizer/objectives/{year}/{objective}/enqueue — front-insert gaps (§A.4.1).
     */
    public function enqueue(Request $request, int $year, string $objective): JsonResponse
    {
        // Validate {objective} against the readiness objectives only (M4). Scenario
        // domains (is_scenario_domain) are excluded — they are never enqueued.
        $objectives = (array) config('optimization-objectives.objectives', []);
        $spec = $objectives[$objective] ?? null;
        if ($spec === null || ! empty($spec['is_scenario_domain'])) {
            abort(404, 'Unknown optimization objective.');
        }

        $result = $this->readiness->enqueueGaps($request->user(), $year, $objective);

        return response()->json($result);
    }
}
