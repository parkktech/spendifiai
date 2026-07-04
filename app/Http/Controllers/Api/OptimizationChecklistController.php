<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OptimizationChecklistItem;
use App\Models\UserTaxFact;
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
            ->map(fn ($item) => $this->formatItem($item, $user, $year))
            ->values();

        // Header aggregate from first header row (position=0, kind='header')
        $headerItem = OptimizationChecklistItem::where('user_id', $user->id)
            ->where('tax_year', $year)
            ->where('kind', 'header')
            ->first();

        $headerAggregate = $headerItem?->benefit_line_params ?? null;

        // Detect "already optimal" state: the user has a chosen scenario but
        // materialize() returned zero items (all knobs already match baseline).
        // The UI uses this flag to render a celebration state instead of a dead-end.
        $hasChosenScenario = UserTaxFact::where('user_id', $user->id)
            ->where('fact_key', 'scenario.chosen_option')
            ->where(fn ($q) => $q->whereNull('tax_year')->orWhere('tax_year', $year))
            ->whereNull('superseded_by_id')
            ->where('is_current', true)
            ->exists();

        $actionItemCount = $items->filter(fn ($item) => ($item['knob'] ?? '') !== 'header')->count();
        $alreadyOptimal = $hasChosenScenario && $actionItemCount === 0;

        // Chosen option label (Addition 6): expose the display label stored at choose time.
        // Fallback: elections made before the label fact existed derive it from the
        // option key — the header must never silently hide for a chosen plan.
        $chosenLabelFact = UserTaxFact::currentFact($user->id, 'scenario.chosen_option_label', null, $year);
        $chosenOptionLabel = $chosenLabelFact?->value;
        if ($chosenOptionLabel === null) {
            $chosenKey = UserTaxFact::currentFact($user->id, 'scenario.chosen_option', null, $year)?->value;
            $chosenOptionLabel = match ($chosenKey) {
                'take_home' => 'Maximize take-home pay',
                'tax_burden' => 'Reduce tax burden',
                'retirement' => 'Build retirement',
                'balanced' => 'Balanced',
                default => $chosenKey,
            };
        }

        return response()->json([
            'tax_year' => $year,
            'header_aggregate' => $headerAggregate,
            'already_optimal' => $alreadyOptimal,
            'chosen_option_label' => $chosenOptionLabel,
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

        return response()->json($this->formatItem($item, $request->user(), $item->tax_year));
    }

    /**
     * Format a checklist item for the API response.
     * No cents-field names in the public response (SAFE-03 alignment for the narrative path).
     * benefit_line_params is an internal field with integer cents — included here because
     * it is in a user-scoped authenticated row and the frontend needs it for checklist rendering.
     *
     * Morning polish Item 3: confirm_ask items include gated_facts (blocking unconfirmed
     * anchor facts with labels + display values) so the UI can render inline activation.
     */
    private function formatItem(OptimizationChecklistItem $item, ?\App\Models\User $user = null, ?int $year = null): array
    {
        // Morning polish Item 3: resolve gated facts for confirm_ask items only.
        // Owner-scoped: $user is always request->user() (ownership verified by policy).
        $gatedFacts = null;
        if ($item->kind === 'confirm_ask' && $user !== null) {
            $resolvedYear = $year ?? (int) ($item->tax_year ?? 0);
            $gatedFacts = $this->checklist->resolveGatedFacts(
                $user,
                $item->knob,
                $resolvedYear,
            );
        }

        return [
            'id' => $item->id,
            'knob' => $item->knob,
            'step_key' => $item->step_key,
            'kind' => $item->kind,
            'benefit_line_params' => $item->benefit_line_params,
            'position' => $item->position,
            'done' => ! is_null($item->done_at),
            'done_at' => $item->done_at?->toIso8601String(),
            'gated_facts' => $gatedFacts, // null for directive, array for confirm_ask
        ];
    }
}
