<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OptimizationChecklistItem;
use App\Services\ScenarioChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OptimizationChecklistController — GET and PATCH for the user's action checklist (SCN-08 / §E).
 *
 * ROUTES (under /api/v1/optimizer/checklist):
 *   GET  /{year}                     — list all checklist items for the year
 *   PATCH /items/{checklistItem}     — toggle done (route model binding: 'checklistItem')
 *
 * ZERO Claude / ZERO HTTP.
 *
 * Authorization: OptimizationChecklistItemPolicy (user_id ownership — T-14-08-02).
 * Route model binding key: 'checklistItem' (not 'item' — collision with OrderItem, AppServiceProvider).
 */
class OptimizationChecklistController extends Controller
{
    public function __construct(
        private readonly ScenarioChecklistService $checklist,
    ) {}

    /**
     * GET /optimizer/checklist/{year} — list checklist items for a tax year.
     */
    public function show(Request $request, int $year): JsonResponse
    {
        $user = $request->user();

        $items = OptimizationChecklistItem::where('user_id', $user->id)
            ->where('tax_year', $year)
            ->orderBy('position')
            ->get()
            ->map(fn ($item) => $this->formatItem($item))
            ->values();

        // Header aggregate from first header row (position=0, kind='header')
        $headerItem = OptimizationChecklistItem::where('user_id', $user->id)
            ->where('tax_year', $year)
            ->where('kind', 'header')
            ->first();

        $headerAggregate = $headerItem?->benefit_line_params ?? null;

        return response()->json([
            'tax_year' => $year,
            'header_aggregate' => $headerAggregate,
            'items' => $items,
        ]);
    }

    /**
     * PATCH /optimizer/checklist/items/{checklistItem} — toggle done on a checklist item.
     *
     * Body: { "done": true|false }
     *
     * Authorization: OptimizationChecklistItemPolicy::update — user_id ownership.
     */
    public function update(Request $request, OptimizationChecklistItem $checklistItem): JsonResponse
    {
        $this->authorize('update', $checklistItem);

        $done = (bool) $request->input('done', false);

        $item = $this->checklist->toggleDone($request->user(), $checklistItem, $done);

        return response()->json($this->formatItem($item));
    }

    /**
     * Format a checklist item for the API response.
     * No cents-field names in the public response (SAFE-03 alignment for the narrative path).
     * benefit_line_params is an internal field with integer cents — included here because
     * it is in a user-scoped authenticated row and the frontend needs it for checklist rendering.
     */
    private function formatItem(OptimizationChecklistItem $item): array
    {
        return [
            'id' => $item->id,
            'knob' => $item->knob,
            'step_key' => $item->step_key,
            'kind' => $item->kind,
            'benefit_line_params' => $item->benefit_line_params,
            'position' => $item->position,
            'done' => ! is_null($item->done_at),
            'done_at' => $item->done_at?->toIso8601String(),
        ];
    }
}
