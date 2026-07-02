<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AccountPurpose;
use App\Enums\DocumentStatus;
use App\Enums\ExpenseType;
use App\Enums\TaxDocumentCategory;
use App\Models\BankAccount;
use App\Models\InterviewSession;
use App\Models\OptimizationCalendarEvent;
use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationFinding;
use App\Models\TaxDocument;
use App\Models\Transaction;
use App\Services\QuestionRetirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ActionCenterController — ACT-02 / ACT-03 endpoint (14-09).
 *
 * GET /api/v1/optimizer/action-center
 *
 * Returns a composed Action Center payload for the authenticated user. All
 * sub-queries are scoped to auth()->id() (security T-14-09-04: no cross-user
 * data leakage).
 *
 * Response shape:
 *   stage0_items    — Onboarding prerequisites in DOCUMENTS-FIRST order (Δ1).
 *                     Each item disappears once its DB condition is met; no
 *                     done-state persistence (ACT-02).
 *   checklist_items — Unchecked optimization actions with benefit lines and
 *                     position ordering (ACT-03).
 *   monitor_prompts — Open change-detected OptimizationFindings emitted by
 *                     ChangeMonitor (MON-01).
 *   calendar_items  — Alert-ready OptimizationCalendarEvents with due dates
 *                     (MON-02 / D15 / D16).
 *   total_open      — Sum of all incomplete items.
 *   is_empty        — true when total_open === 0.
 *
 * Zero Claude (D17): all copy is template-driven inside ChangeMonitor.
 * No new packages (T-14-09-SC).
 */
class ActionCenterController extends Controller
{
    /**
     * GET /api/v1/optimizer/action-center
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $user = $request->user();
        $taxYear = (int) date('Y');

        $stage0Items = $this->deriveStage0Items($user, $userId);
        $checklistItems = $this->getChecklistItems($userId, $taxYear);
        $monitorPrompts = $this->getMonitorPrompts($userId, $taxYear);
        $calendarItems = $this->getCalendarItems($userId, $taxYear);
        $organizationItems = $this->getOrganizationItems($userId, $taxYear);

        // ── D22 / P5 question-retirement counter ──────────────────────────────
        // "This document answered N questions for you" — deterministic, zero Claude.
        $retirementService = app(QuestionRetirementService::class);
        $retirement = $retirementService->countByUser($userId);

        $totalOpen = count($stage0Items)
            + count($checklistItems)
            + count($monitorPrompts)
            + count($calendarItems)
            + count($organizationItems);

        return response()->json([
            'stage0_items' => $stage0Items,
            'checklist_items' => $checklistItems,
            'monitor_prompts' => $monitorPrompts,
            'calendar_items' => $calendarItems,
            'organization_items' => $organizationItems,
            'total_open' => $totalOpen,
            'is_empty' => $totalOpen === 0,
            // D22 / P5 question-retirement payoff
            'questions_retired' => $retirement['count'],
            'questions_retired_summary' => $retirement['count'] > 0
                ? $retirementService->summaryLine($retirement['count'])
                : null,
        ]);
    }

    /**
     * Stage-0 completion checks in DOCUMENTS-FIRST-FUNNEL order (Δ1):
     *   1. upload_paystub   — TaxDocument category=pay_stub + status=ready
     *   2. link_bank        — hasBankConnected()
     *   3. link_credit_cards — BankAccount where user_id + type='credit'
     *   4. link_email       — hasEmailConnected()
     *   5. do_interview     — InterviewSession status='completed'
     *
     * Each item is OMITTED once its DB condition is satisfied (no persistence;
     * endpoint always recomputes from live state, ACT-02).
     *
     * SECURITY (T-14-09-04): all queries scoped to $userId.
     *
     * @param  \App\Models\User  $user
     */
    private function deriveStage0Items(mixed $user, int $userId): array
    {
        $items = [];

        // ── 1. upload_paystub (Δ1: primary CTA — first in the funnel) ────────
        $hasPaystub = TaxDocument::where('user_id', $userId)
            ->where('category', TaxDocumentCategory::PayStub->value)
            ->where('status', DocumentStatus::Ready->value)
            ->exists();

        if (! $hasPaystub) {
            $items[] = [
                'key' => 'upload_paystub',
                'priority' => 1,
                'title' => 'Upload a recent pay stub',
                'description' => 'A pay stub unlocks withholding analysis and retirement contribution calibration.',
                'completed' => false,
                'cta_url' => '/connect',
            ];
        }

        // ── 2. link_bank ──────────────────────────────────────────────────────
        if (! $user->hasBankConnected()) {
            $items[] = [
                'key' => 'link_bank',
                'priority' => 2,
                'title' => 'Link your bank account',
                'description' => 'Bank data powers subscription detection, spending analysis, and income verification.',
                'completed' => false,
                'cta_url' => '/connect',
            ];
        }

        // ── 3. link_credit_cards (DRIFT-08: check via BankAccount type) ──────
        $hasCreditCard = BankAccount::where('user_id', $userId)
            ->where('type', 'credit')
            ->exists();

        if (! $hasCreditCard) {
            $items[] = [
                'key' => 'link_credit_cards',
                'priority' => 3,
                'title' => 'Link your credit cards',
                'description' => 'Credit card data reveals subscription spending and improves deduction tracking.',
                'completed' => false,
                'cta_url' => '/connect',
            ];
        }

        // ── 4. link_email ─────────────────────────────────────────────────────
        if (! $user->hasEmailConnected()) {
            $items[] = [
                'key' => 'link_email',
                'priority' => 4,
                'title' => 'Connect your email',
                'description' => 'Email receipts fill gaps in bank data and automate expense categorization.',
                'completed' => false,
                'cta_url' => '/connect',
            ];
        }

        // ── 5. do_interview (demoted to last per Δ1) ──────────────────────────
        $hasCompletedInterview = InterviewSession::forUser($userId)
            ->where('status', 'completed')
            ->exists();

        if (! $hasCompletedInterview) {
            $items[] = [
                'key' => 'do_interview',
                'priority' => 5,
                'title' => 'Complete the tax interview',
                'description' => 'A 5-minute interview calibrates recommendations to your specific tax situation.',
                'completed' => false,
                'cta_url' => '/optimize',
            ];
        }

        return $items;
    }

    /**
     * Unchecked optimization checklist items with benefit lines (ACT-03).
     *
     * Excludes header rows (knob='header') and items already marked done.
     * Ordered by position for consistent display.
     *
     * SECURITY (T-14-09-04): queried via forUser() scope.
     */
    private function getChecklistItems(int $userId, int $taxYear): array
    {
        return OptimizationChecklistItem::forUser($userId)
            ->where('tax_year', $taxYear)
            ->whereNull('done_at')
            ->where('knob', '!=', 'header')
            ->orderBy('position')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'knob' => $item->knob,
                'step_key' => $item->step_key,
                'kind' => $item->kind,
                'benefit_line_params' => $item->benefit_line_params,
                'position' => $item->position,
                'due_date' => null,
            ])
            ->toArray();
    }

    /**
     * Open change-detected findings from ChangeMonitor (MON-01 / D14).
     *
     * These are findings emitted by detectIncomeShifts(), checkBonusLeadTime(),
     * and checkYearEndItems() with finding_type='change_detected'.
     *
     * SECURITY (T-14-09-04): queried via forUser() scope.
     */
    private function getMonitorPrompts(int $userId, int $taxYear): array
    {
        return OptimizationFinding::forUser($userId)
            ->where('tax_year', $taxYear)
            ->where('finding_type', 'change_detected')
            ->where('status', 'open')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($finding) => [
                'id' => $finding->id,
                'finding_key' => $finding->finding_key,
                'severity' => $finding->severity,
                'description' => $finding->description,
                'details' => $finding->details,
                'deadline' => $finding->deadline?->toDateString(),
                'created_at' => $finding->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Alert-ready calendar events with due dates (MON-02 / D15 / D16).
     *
     * "Alert ready" means the item is within its lead-time window but the alert
     * has not yet been fired (alert_fired_at IS NULL). Once ChangeMonitor fires
     * the alert (sets alert_fired_at), the associated OptimizationFinding appears
     * in monitor_prompts instead.
     *
     * SECURITY (T-14-09-04): queried via forUser() scope.
     */
    private function getCalendarItems(int $userId, int $taxYear): array
    {
        return OptimizationCalendarEvent::forUser($userId)
            ->where('tax_year', $taxYear)
            ->alertReady()
            ->orderBy('expected_at')
            ->get()
            ->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'expected_at' => $event->expected_at?->toDateString(),
                'lead_time_days' => $event->lead_time_days,
                'metadata' => $event->metadata,
                'due_date' => $event->expected_at?->toDateString(),
            ])
            ->toArray();
    }

    /**
     * D22 organization items — structural hygiene guidance (14-11).
     *
     * Evidence-gated: emitted ONLY when commingling evidence exists (business-type
     * transactions in personal/mixed accounts). Template copy is static; no Claude
     * call. Benefit framed as record-cleanliness + audit defensibility (not cost
     * savings — SAFE-03: no dollar estimates).
     *
     * Owner account ground truth (D21 addendum): Personal Checking ...7300 is MIXED
     * with ≈90% business activity; Parkk Technologies ...9111 + AirBnB ...9958 are
     * business-purpose accounts. Suggestions surface for any user matching the pattern.
     *
     * SECURITY (T-14-09-04): all queries scoped to $userId.
     */
    private function getOrganizationItems(int $userId, int $taxYear): array
    {
        $items = [];

        // ── Evidence gate: detect business-classified transactions in personal/mixed accounts ──
        // "Label ≠ behavior" (D21 addendum): account purpose label is a hint; observed
        // transactions are the evidence. We look for business expense_type in non-business accounts.
        $personalAccounts = BankAccount::where('user_id', $userId)
            ->whereIn('purpose', [AccountPurpose::Personal->value, AccountPurpose::Mixed->value])
            ->get();

        if ($personalAccounts->isEmpty()) {
            return $items;
        }

        $personalAccountIds = $personalAccounts->pluck('id');

        // Check for business-type spending on personal/mixed accounts during the tax year
        $hasBusinessInPersonal = Transaction::where('user_id', $userId)
            ->whereIn('bank_account_id', $personalAccountIds)
            ->where('expense_type', ExpenseType::Business->value)
            ->whereYear('transaction_date', $taxYear)
            ->exists();

        if (! $hasBusinessInPersonal) {
            return $items;
        }

        // ── Generate organization items (template-driven, deterministic, zero Claude) ──

        // Find business-purpose accounts the user could route through
        $businessAccounts = BankAccount::where('user_id', $userId)
            ->where('purpose', AccountPurpose::Business->value)
            ->get();

        if ($businessAccounts->isNotEmpty()) {
            // Suggest routing through the primary business account
            $primaryBusiness = $businessAccounts->first();
            $accountLabel = $primaryBusiness->nickname
                ?? ('Account ending in '.substr((string) $primaryBusiness->id, -4));

            $items[] = [
                'type' => 'organization',
                'key' => 'route_business_purchases',
                'priority' => 10,
                'title' => 'Route business purchases through your business account',
                'description' => 'We see business-classified spending in personal accounts this year. '
                    .'Routing business purchases through '.$accountLabel
                    .' keeps your records clean, makes tax prep faster, '
                    .'and strengthens your position in any audit.',
                'benefit_line' => 'Cleaner records · faster tax prep · stronger audit trail',
                'evidence_basis' => 'business_in_personal_account',
                'cta_label' => 'Review your accounts',
                'cta_url' => '/connect',
            ];
        }

        // Count mixed-classified accounts for a consolidation suggestion
        $mixedAccounts = $personalAccounts->where('purpose', AccountPurpose::Mixed->value);
        if ($mixedAccounts->count() > 0) {
            $items[] = [
                'type' => 'organization',
                'key' => 'clarify_mixed_accounts',
                'priority' => 11,
                'title' => 'Clarify which accounts are for business',
                'description' => 'You have '.($mixedAccounts->count() === 1 ? '1 account' : $mixedAccounts->count().' accounts')
                    .' marked "mixed" — a classification that can blur your Schedule C picture. '
                    .'Telling us how each account is really used makes your business deductions '
                    .'more defensible and your records easier to hand to a tax preparer.',
                'benefit_line' => 'Accurate Schedule C · clear deduction evidence · no preparer guesswork',
                'evidence_basis' => 'mixed_purpose_accounts',
                'cta_label' => 'Update account purpose',
                'cta_url' => '/connect',
            ];
        }

        return $items;
    }
}
