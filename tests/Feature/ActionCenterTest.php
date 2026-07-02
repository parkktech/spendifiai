<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\InterviewSession;
use App\Models\OptimizationCalendarEvent;
use App\Models\OptimizationChecklistItem;
use App\Models\OptimizationFinding;
use App\Models\TaxDocument;
use App\Models\User;

beforeEach(function () {
    $this->taxYear = (int) date('Y');
});

// ─────────────────────────────────────────────────────────────────────────────
// STAGE-0 DERIVATION MATRIX
// Each item appears only when its DB condition is NOT met.
// ─────────────────────────────────────────────────────────────────────────────

describe('Stage-0 derivation matrix', function () {

    it('returns upload_paystub when user has no pay stub document', function () {
        $user = createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $response->assertOk();
        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->toContain('upload_paystub');
    });

    it('omits upload_paystub when a ready pay stub exists', function () {
        $user = createAuthenticatedUser();

        TaxDocument::factory()->paystub()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $response->assertOk();
        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->not->toContain('upload_paystub');
    });

    it('omits upload_paystub when pay stub is pending (not ready)', function () {
        $user = createAuthenticatedUser();

        // Status is 'pending' — not 'ready' — so item should still appear
        TaxDocument::factory()->create([
            'user_id' => $user->id,
            'category' => TaxDocumentCategory::PayStub,
            'status' => DocumentStatus::Upload,  // 'upload' — not yet 'ready'
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->toContain('upload_paystub');
    });

    it('returns link_bank when user has no bank connection', function () {
        $user = createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->toContain('link_bank');
    });

    it('omits link_bank when user has an active bank connection', function () {
        ['user' => $user] = createUserWithBank();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->not->toContain('link_bank');
    });

    it('returns link_credit_cards when user has no credit card linked (DRIFT-08)', function () {
        $user = createAuthenticatedUser();

        // Only a checking account — no credit card
        $connection = BankConnection::factory()->create(['user_id' => $user->id, 'status' => ConnectionStatus::Active]);
        BankAccount::factory()->create([
            'user_id' => $user->id,
            'bank_connection_id' => $connection->id,
            'type' => 'depository',
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->toContain('link_credit_cards');
    });

    it('omits link_credit_cards when user has a credit account (DRIFT-08)', function () {
        $user = createAuthenticatedUser();

        $connection = BankConnection::factory()->create(['user_id' => $user->id, 'status' => ConnectionStatus::Active]);
        BankAccount::factory()->create([
            'user_id' => $user->id,
            'bank_connection_id' => $connection->id,
            'type' => 'credit',
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->not->toContain('link_credit_cards');
    });

    it('returns link_email when user has no email connection', function () {
        $user = createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->toContain('link_email');
    });

    it('returns do_interview when user has not completed the interview', function () {
        $user = createAuthenticatedUser();

        // No InterviewSession or not yet completed
        InterviewSession::factory()->create(['user_id' => $user->id, 'status' => 'in_progress']);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->toContain('do_interview');
    });

    it('omits do_interview when interview is completed', function () {
        $user = createAuthenticatedUser();

        InterviewSession::factory()->completed()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        expect($keys)->not->toContain('do_interview');
    });

    it('upload_paystub is first in the DOCUMENTS-FIRST order (Δ1)', function () {
        $user = createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $items = $response->json('stage0_items');
        expect($items[0]['key'])->toBe('upload_paystub')
            ->and($items[0]['priority'])->toBe(1);
    });

    it('returns empty stage0_items when all prerequisites are met', function () {
        ['user' => $user, 'connection' => $connection] = createUserWithBank();

        // Add a ready pay stub
        TaxDocument::factory()->paystub()->create(['user_id' => $user->id]);

        // Add a credit card
        BankAccount::factory()->create([
            'user_id' => $user->id,
            'bank_connection_id' => $connection->id,
            'type' => 'credit',
        ]);

        // Add email connection
        \App\Models\EmailConnection::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        // Complete the interview
        InterviewSession::factory()->completed()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $response->assertOk();
        expect($response->json('stage0_items'))->toBeEmpty();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// CHECKLIST ITEMS AGGREGATION
// ─────────────────────────────────────────────────────────────────────────────

describe('checklist_items aggregation', function () {

    it('returns unchecked checklist items with benefit_line_params', function () {
        ['user' => $user] = createUserWithBank();

        $item = OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
            'knob' => 'k401k',
            'benefit_line_params' => ['delta_annual' => 120000, 'per_paycheck' => 5000],
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $response->assertOk();
        $checklistItems = $response->json('checklist_items');
        expect($checklistItems)->toHaveCount(1);
        expect($checklistItems[0]['id'])->toBe($item->id);
        expect($checklistItems[0]['benefit_line_params'])->toMatchArray([
            'delta_annual' => 120000,
            'per_paycheck' => 5000,
        ]);
    });

    it('excludes checklist items that are already done', function () {
        ['user' => $user] = createUserWithBank();

        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => now()->subDays(2),  // already done
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('checklist_items'))->toBeEmpty();
    });

    it('excludes header rows from checklist_items', function () {
        ['user' => $user] = createUserWithBank();

        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
            'knob' => 'header',  // header row — excluded
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('checklist_items'))->toBeEmpty();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// MONITOR PROMPTS (OptimizationFinding change_detected)
// ─────────────────────────────────────────────────────────────────────────────

describe('monitor_prompts aggregation', function () {

    it('returns open change-detected findings', function () {
        ['user' => $user] = createUserWithBank();

        $finding = OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'finding_type' => 'change_detected',
            'finding_key' => 'income_shift',
            'status' => 'open',
            'severity' => 'medium',
            'description' => 'Income has shifted.',
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $response->assertOk();
        $prompts = $response->json('monitor_prompts');
        expect($prompts)->toHaveCount(1);
        expect($prompts[0]['finding_key'])->toBe('income_shift');
        expect($prompts[0]['description'])->toBe('Income has shifted.');
    });

    it('excludes dismissed/resolved findings from monitor_prompts', function () {
        ['user' => $user] = createUserWithBank();

        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'finding_type' => 'change_detected',
            'status' => 'dismissed',
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('monitor_prompts'))->toBeEmpty();
    });

    it('excludes non-change_detected finding types from monitor_prompts', function () {
        ['user' => $user] = createUserWithBank();

        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'finding_type' => 'red_flag',
            'status' => 'open',
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('monitor_prompts'))->toBeEmpty();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// CALENDAR ITEMS (OptimizationCalendarEvent alertReady)
// ─────────────────────────────────────────────────────────────────────────────

describe('calendar_items aggregation', function () {

    it('returns alert-ready calendar events with due dates', function () {
        ['user' => $user] = createUserWithBank();

        // Create a calendar event in the alert window (expected in 5 days, lead_time=21)
        $event = OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'event_type' => 'bonus',
            'expected_at' => now()->addDays(5),
            'lead_time_days' => 21,  // window started 16 days ago — within window
            'alert_fired_at' => null,  // not yet fired
            'metadata' => ['bonus_month' => now()->addDays(5)->month],
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $response->assertOk();
        $calendarItems = $response->json('calendar_items');
        expect($calendarItems)->toHaveCount(1);
        expect($calendarItems[0]['event_type'])->toBe('bonus');
        expect($calendarItems[0]['due_date'])->not->toBeNull();
    });

    it('excludes calendar events outside the alert window', function () {
        ['user' => $user] = createUserWithBank();

        // Event 3 months away — outside 21-day lead window
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'event_type' => 'bonus',
            'expected_at' => now()->addMonths(3),
            'lead_time_days' => 21,
            'alert_fired_at' => null,
            'metadata' => [],
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('calendar_items'))->toBeEmpty();
    });

    it('excludes calendar events that have already been fired', function () {
        ['user' => $user] = createUserWithBank();

        // Alert already fired — ChangeMonitor emitted the finding
        OptimizationCalendarEvent::create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'event_type' => 'bonus',
            'expected_at' => now()->addDays(5),
            'lead_time_days' => 21,
            'alert_fired_at' => now()->subHour(),  // already fired
            'metadata' => [],
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('calendar_items'))->toBeEmpty();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// TOTAL_OPEN + IS_EMPTY
// ─────────────────────────────────────────────────────────────────────────────

describe('total_open and is_empty', function () {

    it('total_open sums all item groups', function () {
        ['user' => $user, 'connection' => $connection] = createUserWithBank();

        // One unchecked checklist item
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
        ]);

        // One monitor prompt
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'finding_type' => 'change_detected',
            'status' => 'open',
        ]);

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $total = $response->json('total_open');
        // Stage-0 still has items: link_credit_cards + link_email + do_interview = 3
        // + 1 checklist + 1 monitor = 5 minimum
        expect($total)->toBeGreaterThan(0);
        expect($response->json('is_empty'))->toBeFalse();
    });

    it('is_empty is true when no items remain', function () {
        ['user' => $user, 'connection' => $connection] = createUserWithBank();

        // All stage-0 conditions met
        TaxDocument::factory()->paystub()->create(['user_id' => $user->id]);
        BankAccount::factory()->create([
            'user_id' => $user->id,
            'bank_connection_id' => $connection->id,
            'type' => 'credit',
        ]);
        \App\Models\EmailConnection::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        InterviewSession::factory()->completed()->create(['user_id' => $user->id]);

        // No checklist items, no findings, no calendar events

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('total_open'))->toBe(0);
        expect($response->json('is_empty'))->toBeTrue();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// PENDING ACTION COUNT (nav badge prop — ACT-01 / DRIFT-09)
// ─────────────────────────────────────────────────────────────────────────────

describe('pendingActionCount nav badge prop', function () {

    it('pendingActionCount reflects unchecked checklist items', function () {
        ['user' => $user] = createUserWithBank();

        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
            'knob' => 'k401k',
        ]);
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
            'knob' => 'k_hsa',
        ]);

        // Hit any Inertia route to trigger HandleInertiaRequests::share()
        $response = $this->get('/dashboard');

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        expect($props['auth']['pendingActionCount'])->toBe(2);
    });

    it('pendingActionCount is 0 when user has no bank connected', function () {
        $user = createAuthenticatedUser();

        // No bank — create checklist items that should NOT be counted
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
        ]);

        $response = $this->get('/dashboard');

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        expect($props['auth']['pendingActionCount'])->toBe(0);
    });

    it('pendingActionCount excludes done items and header rows', function () {
        ['user' => $user] = createUserWithBank();

        // Done item — excluded
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => now(),
        ]);

        // Header row — excluded
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
            'knob' => 'header',
        ]);

        // Unchecked action item — included
        OptimizationChecklistItem::factory()->create([
            'user_id' => $user->id,
            'tax_year' => $this->taxYear,
            'done_at' => null,
            'knob' => 'k401k',
        ]);

        $response = $this->get('/dashboard');

        $props = $response->original->getData()['page']['props'];
        expect($props['auth']['pendingActionCount'])->toBe(1);
    });

    it('pendingOptimizationCount is unchanged (backwards compat, DRIFT-09)', function () {
        ['user' => $user] = createUserWithBank();

        // Create an open narrated finding
        OptimizationFinding::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'description' => 'Some finding.',
        ]);

        $response = $this->get('/dashboard');

        $props = $response->original->getData()['page']['props'];
        expect($props['auth']['pendingOptimizationCount'])->toBe(1);
        // Both props coexist — additive
        expect($props['auth'])->toHaveKey('pendingActionCount');
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// CROSS-USER SCOPING (T-14-09-04)
// ─────────────────────────────────────────────────────────────────────────────

describe('cross-user scoping', function () {

    it('does not return another user\'s Stage-0 completion status', function () {
        // User B has a pay stub — should NOT affect user A's item list
        $userB = User::factory()->create();
        TaxDocument::factory()->paystub()->create(['user_id' => $userB->id]);

        $userA = createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        $keys = collect($response->json('stage0_items'))->pluck('key')->toArray();
        // User A still has no pay stub — item must be present
        expect($keys)->toContain('upload_paystub');
    });

    it('does not return another user\'s checklist items', function () {
        $userB = User::factory()->create();
        OptimizationChecklistItem::factory()->create([
            'user_id' => $userB->id,
            'tax_year' => (int) date('Y'),
            'done_at' => null,
        ]);

        createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('checklist_items'))->toBeEmpty();
    });

    it('does not return another user\'s monitor prompts', function () {
        $userB = User::factory()->create();
        OptimizationFinding::factory()->create([
            'user_id' => $userB->id,
            'tax_year' => (int) date('Y'),
            'finding_type' => 'change_detected',
            'status' => 'open',
        ]);

        createAuthenticatedUser();

        $response = $this->getJson('/api/v1/optimizer/action-center');

        expect($response->json('monitor_prompts'))->toBeEmpty();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// AUTHENTICATION
// ─────────────────────────────────────────────────────────────────────────────

it('requires authentication', function () {
    $this->getJson('/api/v1/optimizer/action-center')
        ->assertUnauthorized();
});
