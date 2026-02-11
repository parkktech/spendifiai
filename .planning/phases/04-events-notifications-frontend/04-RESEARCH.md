# Phase 4: Events, Notifications & Frontend - Research

**Researched:** 2026-02-11
**Domain:** Laravel Events/Notifications, React 19 + Inertia 2 + TypeScript frontend
**Confidence:** HIGH

## Summary

Phase 4 connects the backend features built in Phases 1-3 via Laravel's event-driven architecture, adds user-facing notifications, and builds the complete React/Inertia/TypeScript frontend. The codebase is well-positioned: all 10 API controllers are fully implemented, 8 API Resources exist, all Form Requests exist, all routes are registered and verified (52 routes total), and scheduled tasks are already configured in `routes/console.php`.

The frontend stack is a standard Breeze React+TypeScript scaffold using Inertia 2.3.13, React 19.2.4, Tailwind CSS 4.1.18 (CSS-based config, no tailwind.config.js), and Vite 7. No app-specific frontend components exist yet beyond the Breeze defaults (11 components, 2 layouts, 7 pages for auth). The reference dashboard prototype (`existing-code/reference-dashboard.jsx`) uses Recharts, Lucide React icons, and a dark theme with a custom color palette -- these need to be installed and adapted to Tailwind/TypeScript.

The backend work for events is relatively lightweight -- Laravel events/listeners need to be created, a SyncBankTransactions job needs to be written, and 4 notification classes need to be built. The frontend work is the bulk of this phase: 8 major pages, 9+ shared components, and a complete layout redesign from Breeze defaults to match the SpendWise design.

**Primary recommendation:** Split into 3 plans: (1) events/listeners/jobs/notifications (backend), (2) first batch of frontend pages (Dashboard, Transactions, Connect, Settings, AI Questions), (3) second batch of frontend pages (Subscriptions, Savings, Tax) plus shared components. Use Recharts for charts (matches reference dashboard), Lucide React for icons (matches reference), and build custom Tailwind components rather than adding shadcn/ui (which is not yet installed and would add complexity to Tailwind 4 setup).

## Standard Stack

### Core (Already Installed)

| Library | Version | Purpose | Status |
|---------|---------|---------|--------|
| React | 19.2.4 | UI framework | Installed |
| @inertiajs/react | 2.3.13 | SPA routing + server-driven pages | Installed |
| TypeScript | ^5.0.2 | Type safety | Installed |
| Tailwind CSS | 4.1.18 | Utility-first CSS (CSS-based config, NOT JS config) | Installed |
| @tailwindcss/vite | (v4) | Tailwind Vite plugin | Installed |
| @tailwindcss/forms | ^0.5.3 | Form styling plugin | Installed |
| @headlessui/react | ^2.0.0 | Accessible UI primitives (Dialog, Menu, etc.) | Installed |
| axios | ^1.11.0 | HTTP client | Installed |
| Vite | ^7.0.7 | Build tool | Installed |

### To Be Installed (Frontend)

| Library | Version | Purpose | Why |
|---------|---------|---------|-----|
| recharts | ^2.15 | Charts (Area, Bar, Pie) | Reference dashboard uses Recharts; best React charting library |
| lucide-react | ^0.470 | Icon library | Reference dashboard uses Lucide icons (40+ icons referenced) |
| react-plaid-link | ^3.6 | Plaid Link SDK for React | Required for bank connection flow (CLAUDE.md specifies this) |

### To Be Installed (Backend)

No additional Composer packages needed. Laravel's built-in event system (`php artisan make:event`, `php artisan make:listener`, `php artisan make:notification`) provides everything required. The notifications table migration was just created.

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Recharts | Chart.js + react-chartjs-2 | Chart.js is more established but Recharts is native React, composable, and already used in reference dashboard |
| Custom Tailwind components | shadcn/ui | shadcn/ui is excellent but NOT installed, requires `components.json` setup, and Tailwind 4 CSS-first config may have compatibility issues. Custom components match Breeze pattern better. |
| Lucide React | Heroicons | Heroicons comes with Headless UI but reference dashboard uses 40+ Lucide icons. Switching would mean finding equivalents for every icon. |

**Installation:**
```bash
npm install recharts lucide-react react-plaid-link
```

## Architecture Patterns

### Recommended Frontend Project Structure
```
resources/js/
├── app.tsx                    # Inertia app entry (exists)
├── bootstrap.ts               # Axios setup (exists)
├── types/
│   ├── index.d.ts             # Core types (User, PageProps - exists, extend)
│   ├── spendwise.d.ts         # App-specific types (Transaction, Subscription, etc.)
│   └── global.d.ts            # Global type augmentations (exists)
├── Components/
│   ├── [Breeze components]    # 11 existing Breeze components (keep)
│   ├── SpendWise/             # App-specific shared components
│   │   ├── SpendingChart.tsx
│   │   ├── TransactionRow.tsx
│   │   ├── SubscriptionCard.tsx
│   │   ├── RecommendationCard.tsx
│   │   ├── QuestionCard.tsx
│   │   ├── PlaidLinkButton.tsx
│   │   ├── ViewModeToggle.tsx
│   │   ├── ExportModal.tsx
│   │   ├── ConfirmDialog.tsx
│   │   ├── StatCard.tsx
│   │   ├── Badge.tsx
│   │   └── FilterBar.tsx
│   └── ui/                    # Basic reusable UI primitives if needed
├── Layouts/
│   ├── AuthenticatedLayout.tsx # Exists but needs SpendWise sidebar navigation
│   └── GuestLayout.tsx        # Exists (for auth pages)
├── Pages/
│   ├── Dashboard.tsx          # Exists (placeholder - replace)
│   ├── Transactions/
│   │   └── Index.tsx
│   ├── Subscriptions/
│   │   └── Index.tsx
│   ├── Savings/
│   │   └── Index.tsx
│   ├── Tax/
│   │   └── Index.tsx
│   ├── Connect/
│   │   └── Index.tsx
│   ├── Settings/
│   │   └── Index.tsx
│   ├── Questions/
│   │   └── Index.tsx
│   ├── Auth/                  # 6 existing Breeze auth pages (keep)
│   ├── Profile/               # 1 existing Breeze profile page (keep)
│   └── Welcome.tsx            # Exists (landing page)
└── hooks/
    └── useApi.ts              # Custom hook for API calls via axios
```

### Recommended Backend Event/Notification Structure
```
app/
├── Events/
│   ├── BankConnected.php
│   ├── TransactionsImported.php
│   ├── TransactionCategorized.php
│   └── UserAnsweredQuestion.php
├── Listeners/
│   ├── TriggerInitialSync.php
│   ├── DispatchCategorizationJob.php
│   ├── UpdateSubscriptionDetection.php
│   └── UpdateTransactionCategory.php
├── Notifications/
│   ├── AIQuestionsReady.php
│   ├── UnusedSubscriptionAlert.php
│   ├── BudgetThresholdReached.php
│   └── WeeklySavingsDigest.php
└── Jobs/
    ├── CategorizePendingTransactions.php  # EXISTS
    ├── ProcessOrderEmails.php             # EXISTS
    └── SyncBankTransactions.php           # NEEDS CREATION
```

### Pattern 1: Inertia Page with API Data

The current app has a split architecture: Inertia pages for rendering, API endpoints for data. The Inertia pages should fetch data from the API endpoints using axios, NOT through Inertia props (since the API controllers return JSON, not Inertia responses).

**Architecture decision:** The web routes use Inertia::render() for page scaffolding, and the React pages call the existing `/api/v1/*` endpoints for data via axios. This means:
- Web routes render empty Inertia pages (with just page shell)
- React components fetch data via axios on mount using useEffect
- API responses are already well-structured (all controllers return JsonResponse)

```typescript
// Pattern: Page fetches data from API on mount
export default function Dashboard() {
    const [data, setData] = useState<DashboardData | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios.get('/api/v1/dashboard')
            .then(res => setData(res.data))
            .finally(() => setLoading(false));
    }, []);

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            {loading ? <Loading /> : <DashboardContent data={data} />}
        </AuthenticatedLayout>
    );
}
```

### Pattern 2: Laravel Events with Listeners

```php
// app/Events/BankConnected.php
class BankConnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly BankConnection $connection,
        public readonly User $user,
    ) {}
}

// In PlaidController::exchangeToken() - dispatch after connection:
BankConnected::dispatch($connection, auth()->user());
```

### Pattern 3: Laravel Notification (Database + Email)

```php
// app/Notifications/AIQuestionsReady.php
class AIQuestionsReady extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $questionCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->questionCount} transactions need your input")
            ->line('AI has categorized some transactions but needs your help.')
            ->action('Review Questions', url('/questions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ai_questions_ready',
            'count' => $this->questionCount,
        ];
    }
}
```

### Anti-Patterns to Avoid

- **Do NOT use Inertia shared data for heavy API responses.** The HandleInertiaRequests middleware should only share lightweight data (auth user, flash messages). Heavy data (transactions, dashboard stats) should be fetched by the page component via axios.
- **Do NOT create new API routes for the frontend.** All 52 API endpoints already exist and return well-structured JSON. Pages should call these directly.
- **Do NOT add shadcn/ui.** It is not installed and Tailwind 4 CSS-based config differs from Tailwind 3 JS config that shadcn/ui expects. Use @headlessui/react for accessible primitives + custom Tailwind classes.
- **Do NOT manually manage CSRF tokens for API calls.** The `statefulApi()` middleware is configured in `bootstrap/app.php`, and axios has the `X-Requested-With: XMLHttpRequest` header set in `bootstrap.ts`. Sanctum cookie-based auth handles CSRF automatically for same-domain requests.
- **Do NOT create SPA-style client-side routing.** Use Inertia's `<Link>` component and `router.visit()` for navigation. Inertia handles SPA-like transitions while keeping server-side routing.

## Existing Codebase Inventory

### What EXISTS and is Ready to Use

| Component | Location | Status |
|-----------|----------|--------|
| 10 API Controllers | `app/Http/Controllers/Api/` | Complete, all methods implemented |
| 8 API Resources | `app/Http/Resources/` | TransactionResource, BankAccountResource, BankConnectionResource, SubscriptionResource, AIQuestionResource, SavingsRecommendationResource, SavingsTargetResource, SavingsPlanActionResource |
| 11 Form Requests | `app/Http/Requests/` | All validation rules defined |
| 52 API Routes | `routes/api.php` | Verified via `route:list` |
| 7 Policies | Registered in AppServiceProvider | Authorization for all models |
| 16 Models | `app/Models/` | All relationships, casts, scopes |
| 7 Enums | `app/Enums/` | ConnectionStatus, QuestionStatus, etc. |
| 5 Scheduled Tasks | `routes/console.php` | categorize-pending, detect-subscriptions, generate-savings, expire-questions, sync-email-orders |
| User model | `app/Models/User.php` | Has `Notifiable` trait already |
| Notifications migration | `database/migrations/` | Just created |
| HandleInertiaRequests | `app/Http/Middleware/` | Shares auth.user |
| Breeze Components | `resources/js/Components/` | 11 components (Dropdown, NavLink, Modal, TextInput, etc.) |
| Breeze Auth Pages | `resources/js/Pages/Auth/` | 6 pages (Login, Register, etc.) |
| Breeze Layouts | `resources/js/Layouts/` | AuthenticatedLayout, GuestLayout |

### What NEEDS to Be Created

| Component | Details |
|-----------|---------|
| 4 Events | BankConnected, TransactionsImported, TransactionCategorized, UserAnsweredQuestion |
| 4-6 Listeners | TriggerInitialSync, DispatchCategorizationJob, UpdateSubscriptionDetection, UpdateTransactionCategory, CheckBudgetThresholds, NotifyQuestionsReady |
| 1 Job | SyncBankTransactions (per-connection) |
| 4 Notifications | AIQuestionsReady, UnusedSubscriptionAlert, BudgetThresholdReached, WeeklySavingsDigest |
| 8 Frontend Pages | Dashboard, Transactions, Subscriptions, Savings, Tax, Connect, Settings, AI Questions |
| 9+ Shared Components | PlaidLinkButton, SpendingChart, TransactionRow, SubscriptionCard, RecommendationCard, QuestionCard, ViewModeToggle, ExportModal, ConfirmDialog, StatCard, Badge, FilterBar |
| Web Routes | Inertia routes for each page (currently only `/dashboard` exists) |
| TypeScript Types | Full type definitions matching API response shapes |
| Layout Redesign | AuthenticatedLayout needs sidebar navigation (reference dashboard has sidebar) |

### What NEEDS Modification

| File | Change Needed |
|------|---------------|
| `routes/web.php` | Add Inertia routes for transactions, subscriptions, savings, tax, connect, settings, questions |
| `routes/console.php` | Uncomment/create SyncBankTransactions dispatch, add notification dispatches |
| `resources/js/Layouts/AuthenticatedLayout.tsx` | Redesign with sidebar navigation matching reference dashboard |
| `resources/js/Pages/Dashboard.tsx` | Replace placeholder with full dashboard (spending summary, charts, questions, recent transactions) |
| `resources/js/types/index.d.ts` | Extend with SpendWise-specific types |
| `resources/views/app.blade.php` | May need font change (Figtree -> DM Sans to match reference) |
| `resources/css/app.css` | Add dark theme CSS custom properties, app-specific utilities |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share flash messages, notification count, pending questions count |
| `app/Http/Controllers/Api/PlaidWebhookController.php` | Wire up notifications at TODO comments (lines 189, 211) |
| `app/Http/Controllers/Api/PlaidController.php` | Dispatch BankConnected event |
| `app/Jobs/CategorizePendingTransactions.php` | Dispatch TransactionCategorized events, notify user of new questions |

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Charts (area, bar, pie, line) | Custom SVG charts | Recharts | Reference dashboard already uses it; handles responsiveness, tooltips, animations |
| Icons | Custom SVG icon set | Lucide React | Reference dashboard uses 40+ Lucide icons; comprehensive, tree-shakeable |
| Plaid Link modal | Custom bank linking UI | react-plaid-link | Official Plaid SDK; handles token exchange, error states, institution search |
| Accessible modals/dialogs | Custom modal component | @headlessui/react Dialog | Already installed; handles focus trapping, keyboard nav, screen readers |
| Accessible dropdowns | Custom dropdown | @headlessui/react Menu | Already installed; handles ARIA roles, keyboard navigation |
| Date formatting | Manual date string parsing | Intl.DateTimeFormat or date-fns | Browser-native or lightweight; avoid moment.js |
| Event/Listener registration | Manual event dispatching | Laravel EventServiceProvider or event discovery | Laravel auto-discovers events+listeners or register in EventServiceProvider |
| Notification channels | Custom notification system | Laravel Notification facade | Built-in database + mail channels; queued delivery |

**Key insight:** The reference dashboard is a single-file React prototype with inline styles and mock data. It should be used as a visual reference only -- rebuild components with TypeScript, Tailwind classes, and real API data. Do NOT import or adapt inline styles.

## Common Pitfalls

### Pitfall 1: Tailwind 4 CSS-based Config vs. Tailwind 3 JS Config
**What goes wrong:** Attempting to use `tailwind.config.js` patterns or plugins designed for Tailwind 3.
**Why it happens:** Tailwind 4 uses CSS-based configuration (`@import "tailwindcss"` in app.css) instead of a JavaScript config file. There is no `tailwind.config.js` in this project.
**How to avoid:** Define custom colors, fonts, and utilities in `resources/css/app.css` using `@theme` directive:
```css
@import "tailwindcss";
@theme {
  --color-spendwise-bg: #0B0F1A;
  --color-spendwise-card: #111827;
  --color-spendwise-accent: #10b981;
  /* etc. */
}
```
**Warning signs:** Build errors mentioning `tailwind.config.js`, or utilities not applying.

### Pitfall 2: Dual Auth System Confusion
**What goes wrong:** Mixing Inertia/session auth with API token auth, leading to 401 errors.
**Why it happens:** The app has two auth systems: Breeze (session-based, for web routes) and Sanctum (bearer token, for API routes). Inertia pages run under session auth.
**How to avoid:** Inertia pages making API calls should rely on Sanctum's stateful cookie auth (already configured via `$middleware->statefulApi()` in `bootstrap/app.php`). Axios requests from Inertia pages automatically include the session cookie. Do NOT manually set Authorization headers for same-domain API calls.
**Warning signs:** 401 responses from API endpoints when called from Inertia pages, CSRF token mismatch errors.

### Pitfall 3: Inertia Page Resolution Requires Exact Path
**What goes wrong:** Page not found errors when navigating to Inertia routes.
**Why it happens:** `app.tsx` resolves pages via `./Pages/${name}.tsx` glob. The page name in `Inertia::render()` must match the file path exactly.
**How to avoid:** Use nested paths consistently: `Inertia::render('Transactions/Index')` maps to `resources/js/Pages/Transactions/Index.tsx`. Verify the glob pattern in `app.tsx` matches.
**Warning signs:** "Page not resolved" errors in console.

### Pitfall 4: Event Listener Circular Dispatch
**What goes wrong:** Infinite loops when events trigger listeners that dispatch the same event.
**Why it happens:** E.g., TransactionCategorized -> UpdateSubscriptionDetection -> creates Subscription -> accidentally dispatches TransactionCategorized again.
**How to avoid:** Keep event chains short and unidirectional. Listeners should never dispatch the same event class they handle. Use `ShouldQueue` on listeners to decouple execution.
**Warning signs:** Queue worker consuming unlimited jobs, database deadlocks.

### Pitfall 5: N+1 Queries in Dashboard
**What goes wrong:** Dashboard page making 10+ sequential API calls, causing slow load times.
**Why it happens:** Each widget fetches its own data independently.
**How to avoid:** DashboardController already returns a composite response with all data (summary, categories, questions, recent transactions, spending trend, sync status, accounts summary). Use this single endpoint, not separate calls.
**Warning signs:** Network tab showing many sequential requests on dashboard load.

### Pitfall 6: Notification Migration Not Run
**What goes wrong:** Database error when dispatching notifications.
**Why it happens:** The `notifications` table migration was just created by `php artisan notifications:table` but has not been run yet.
**How to avoid:** Run `php artisan migrate` before testing notifications. Include migration step in plan.
**Warning signs:** "Table 'notifications' doesn't exist" errors.

### Pitfall 7: Reference Dashboard Font Mismatch
**What goes wrong:** UI looks different from reference because of wrong font.
**Why it happens:** Reference dashboard uses "DM Sans", but Breeze uses "Figtree" (loaded in `app.blade.php`).
**How to avoid:** Either switch to DM Sans (update `app.blade.php` font link) or keep Figtree and accept slight visual difference. The dark color scheme is more important than the exact font.
**Warning signs:** Typography looks different from reference mockup.

## Code Examples

### Example 1: Inertia Web Route with Page Rendering

```php
// routes/web.php - Add SpendWise pages
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', fn() => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/transactions', fn() => Inertia::render('Transactions/Index'))->name('transactions');
    Route::get('/subscriptions', fn() => Inertia::render('Subscriptions/Index'))->name('subscriptions');
    Route::get('/savings', fn() => Inertia::render('Savings/Index'))->name('savings');
    Route::get('/tax', fn() => Inertia::render('Tax/Index'))->name('tax');
    Route::get('/connect', fn() => Inertia::render('Connect/Index'))->name('connect');
    Route::get('/settings', fn() => Inertia::render('Settings/Index'))->name('settings');
    Route::get('/questions', fn() => Inertia::render('Questions/Index'))->name('questions');
});
```

### Example 2: TypeScript Types Matching API Responses

```typescript
// resources/js/types/spendwise.d.ts
export interface Transaction {
    id: number;
    merchant: string;
    merchant_name: string;
    amount: number;
    date: string;
    category: string;
    ai_category: string | null;
    user_category: string | null;
    ai_confidence: number | null;
    review_status: string;
    expense_type: string;
    account_purpose: string;
    tax_deductible: boolean;
    is_subscription: boolean;
    description: string | null;
    account?: BankAccount;
}

export interface DashboardData {
    view_mode: 'all' | 'personal' | 'business';
    summary: {
        this_month_spending: number;
        month_over_month: number;
        potential_savings: number;
        tax_deductible_ytd: number;
        needs_review: number;
        unused_subscriptions: number;
        pending_questions: number;
    };
    categories: Array<{ category: string; total: number; count: number }>;
    questions: AIQuestion[];
    recent: Transaction[];
    spending_trend: Array<{ month: string; total: number }>;
    sync_status: { status: string; last_synced_at: string; institution_name: string } | null;
    accounts_summary: { personal: number; business: number; mixed: number };
}
```

### Example 3: Custom Hook for API Data Fetching

```typescript
// resources/js/hooks/useApi.ts
import { useState, useEffect, useCallback } from 'react';
import axios from 'axios';

export function useApi<T>(url: string, deps: any[] = []) {
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const refresh = useCallback(() => {
        setLoading(true);
        axios.get(url)
            .then(res => setData(res.data))
            .catch(err => setError(err.response?.data?.message || 'Failed to load'))
            .finally(() => setLoading(false));
    }, [url, ...deps]);

    useEffect(() => { refresh(); }, [refresh]);

    return { data, loading, error, refresh };
}
```

### Example 4: PlaidLinkButton Component

```typescript
// resources/js/Components/SpendWise/PlaidLinkButton.tsx
import { usePlaidLink } from 'react-plaid-link';
import axios from 'axios';

interface Props {
    onSuccess: (accounts: number) => void;
    onError?: (error: string) => void;
}

export default function PlaidLinkButton({ onSuccess, onError }: Props) {
    const [linkToken, setLinkToken] = useState<string | null>(null);

    useEffect(() => {
        axios.post('/api/v1/plaid/link-token')
            .then(res => setLinkToken(res.data.link_token));
    }, []);

    const { open, ready } = usePlaidLink({
        token: linkToken,
        onSuccess: async (publicToken) => {
            const res = await axios.post('/api/v1/plaid/exchange', {
                public_token: publicToken,
            });
            onSuccess(res.data.accounts);
        },
        onExit: (err) => {
            if (err) onError?.(err.display_message || 'Connection failed');
        },
    });

    return (
        <button onClick={() => open()} disabled={!ready}>
            Connect Bank Account
        </button>
    );
}
```

### Example 5: Laravel Event Registration (EventServiceProvider or auto-discovery)

```php
// In Laravel 12, events can be auto-discovered or registered in AppServiceProvider
// Option A: Auto-discovery (recommended) - just create Event and Listener classes
// Laravel discovers listeners based on their type-hinted handle() parameter

// app/Listeners/TriggerInitialSync.php
class TriggerInitialSync implements ShouldQueue
{
    public function handle(BankConnected $event): void
    {
        $plaidService = app(PlaidService::class);
        $result = $plaidService->syncTransactions($event->connection);

        if ($result['added'] > 0) {
            TransactionsImported::dispatch($event->connection, $result['added']);
        }
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| tailwind.config.js | CSS-based @theme in app.css | Tailwind 4 (Jan 2025) | No JS config file. Colors/fonts defined in CSS. |
| EventServiceProvider class | Auto-discovery or AppServiceProvider boot() | Laravel 11+ | No separate EventServiceProvider needed |
| Inertia v1 manual setup | @inertiajs/react v2 with createInertiaApp() | 2024 | Already in place in app.tsx |
| React class components | React 19 functional components + hooks | Ongoing | All new code uses hooks; existing Breeze code already uses hooks |
| Separate SPA + API auth | Sanctum statefulApi() | Laravel 11+ | Already configured; Inertia pages use cookie auth to call API endpoints |

**Deprecated/outdated:**
- `EventServiceProvider`: No longer needed in Laravel 12. Register events in `AppServiceProvider::boot()` or rely on auto-discovery.
- `tailwind.config.js`: Tailwind 4 uses CSS-based config. No JS config file in this project.
- `Kernel.php` (Console, HTTP): Laravel 12 uses `bootstrap/app.php` for middleware and `routes/console.php` for schedules.

## Key Design Decisions for Planner

### 1. Inertia vs. API-only Frontend
The app has **both** Inertia (web routes) and API endpoints (api routes). The frontend should:
- Use Inertia for page navigation (renders, links, redirects)
- Use axios to call API endpoints for data (the API returns JsonResponse, not Inertia responses)
- This is a hybrid pattern: Inertia for routing/layout, axios for data

### 2. Dark Theme from Reference Dashboard
The reference dashboard (`existing-code/reference-dashboard.jsx`) uses a dark theme with specific color palette:
- Background: `#0B0F1A`
- Card: `#111827`
- Accent: `#10b981` (emerald green)
- Text: `#f1f5f9`, Muted: `#94a3b8`, Dim: `#64748b`
- These map cleanly to Tailwind colors: bg-gray-950, bg-gray-900, emerald-500, slate-100, slate-400, slate-500

### 3. Sidebar Navigation Layout
The reference dashboard uses a collapsible sidebar (not Breeze's top navbar). The AuthenticatedLayout needs to be redesigned with:
- Left sidebar with navigation items
- Top bar with search + notifications + user avatar
- Main content area

### 4. SyncBankTransactions Job
This is the last missing job (commented out in `routes/console.php` with `// TODO: Create in Phase 6`). Since Phase 4 includes EVNT-05 and EVNT-11, it needs to be created now. It should:
- Accept a BankConnection, call PlaidService::syncTransactions()
- Dispatch CategorizePendingTransactions if new transactions added
- Be dispatched by webhook handler and scheduler

### 5. Notification Dispatching Points
Where notifications should be dispatched from:
- **AIQuestionsReady**: After CategorizePendingTransactions job creates questions (at end of job)
- **UnusedSubscriptionAlert**: After subscription detection schedule runs (end of detect-subscriptions in console.php)
- **BudgetThresholdReached**: After categorization (check budget goals in CategorizePendingTransactions listener)
- **WeeklySavingsDigest**: As a separate scheduled task (weekly, after savings analysis)
- **Bank connection errors**: In PlaidWebhookController (lines 189, 211 have TODO comments)

### 6. Web Route Structure
Currently only `/dashboard` has an Inertia route. Need to add 7 more Inertia routes for all app pages. These routes render empty pages (the React components fetch data via API).

## Open Questions

1. **Dark vs. Light Theme**
   - What we know: Reference dashboard uses dark theme. Breeze defaults to light theme.
   - What's unclear: Should the app be dark-only, light-only, or support both?
   - Recommendation: Build dark theme (matching reference) as default. Light mode support can be added later. Use Tailwind's dark mode utilities if needed but start dark-first.

2. **How to handle notification read state in frontend**
   - What we know: Laravel notifications table has `read_at` column. User model has `Notifiable` trait.
   - What's unclear: Should notifications be shown in a dropdown (bell icon), a separate page, or both?
   - Recommendation: Bell icon dropdown showing recent unread notifications + mark-as-read. No separate notifications page needed for MVP.

3. **Event Broadcasting (real-time updates)**
   - What we know: Phase 4 requirements don't mention WebSockets or real-time updates.
   - What's unclear: Should events broadcast to the frontend for live updates?
   - Recommendation: No. Events should be backend-only (queue dispatching, notification sending). Real-time broadcasting (Pusher/Reverb) is out of scope. Frontend polls or refreshes on navigation.

## Sources

### Primary (HIGH confidence)
- Codebase inspection: All files read directly from `/var/www/html/ledgeriq/`
- `package.json`: React 19, Inertia 2, TypeScript 5, Tailwind 4, Vite 7
- `composer.json`: Laravel 12, Sanctum, Fortify
- Verified installed versions: React 19.2.4, @inertiajs/react 2.3.13, tailwindcss 4.1.18
- `routes/api.php`: 52 routes verified via `php artisan route:list`
- Phase 3 verification: All 21 must-haves passed

### Secondary (MEDIUM confidence)
- Reference dashboard: `existing-code/reference-dashboard.jsx` -- visual reference for UI design
- CLAUDE.md project instructions -- architecture decisions and conventions

### Tertiary (LOW confidence)
- shadcn/ui + Tailwind 4 compatibility: Based on knowledge that shadcn/ui was designed for Tailwind 3 JS config. May work with Tailwind 4 but not verified for this project.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Verified installed versions, existing codebase patterns clear
- Architecture: HIGH - All backend pieces exist, frontend patterns established by Breeze scaffold
- Pitfalls: HIGH - Identified from direct codebase inspection (dual auth, Tailwind 4 config, font mismatch)
- Events/Notifications: HIGH - Laravel's built-in event system, standard patterns

**Research date:** 2026-02-11
**Valid until:** 2026-03-11 (stable stack, no fast-moving dependencies)
